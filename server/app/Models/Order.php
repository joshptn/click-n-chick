<?php

namespace App\Models;

use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Orders\OrderStatus;
use App\Services\Stock\StockReservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    public const ADVANCE_PAYMENT_HOURS = 24;

    /**
     * Decided 2026-11-20: an advance order collected today or tomorrow (counted
     * from the day the store accepts it) must be paid by its collection time
     * less the preparation time the Store Agent gave. Later dates keep the
     * 24-hour rule.
     */
    public const PREPARATION_RULE_DAYS = 1;

    /** The preparation time a Store Agent may give when accepting, in minutes. */
    public const MIN_PREPARATION_MINUTES = 5;

    public const MAX_PREPARATION_MINUTES = 480;

    /** An immediate order not paid this long after checkout is cancelled (FR-04.5). */
    public const PAYMENT_WINDOW_MINUTES = StockReservation::PAYMENT_HOLD_MINUTES;

    /** Stands in for the customer on the orders of a deleted account. */
    public const ANONYMIZED_CUSTOMER = 'Deleted customer';

    /** When an order closed; rows from before closed_at was stamped closed at their last update. */
    public const CLOSED_AT_SQL = 'COALESCE(orders.closed_at, orders.updated_at)';

    /** What an order came to; rows from before total_amount carry total_price only. */
    public const TOTAL_SQL = 'COALESCE(orders.total_amount, orders.total_price, 0)';

    /** Domain label for the guest tracking token's HMAC (mintGuestToken). */
    private const GUEST_TOKEN_LABEL = 'guest-order-tracking';

    use HasFactory;

    protected $fillable = [
        'user_id',
        'address_id',
        'order_number',
        'advance_order_number',
        'order_type',
        'scheduled_for',
        'pickup_at',
        'status',
        'details_confirmed_at',
        'accepted_at',
        'cancellation_reason',
        'cancelled_by',
        'closed_at',
        'total_price',
        'subtotal',
        'discount_amount',
        'vat_exempt_amount',
        'points_amount',
        'delivery_fee',
        'delivery_distance_km',
        'total_amount',
        'preparation_time',
        'contact_name',
        'contact_phone',
        'guest_email',
        'full_address',
        'latitude',
        'longitude',
        'location',
        'delivery_note',
    ];

    /**
     * Every date is stored as UTC (fixed 2026-11-20). Laravel writes a date's
     * clock time as it stands, so a time carrying an offset ("15:00+08:00",
     * as the pickup and collection times arrive) was stored as 15:00 UTC and
     * read back eight hours late. Converting first keeps the moment.
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : parent::fromDateTime($this->asDateTime($value)->utc());
    }

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'pickup_at' => 'datetime',
            'details_confirmed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
            'preparation_time' => 'integer',
            'delivery_distance_km' => 'decimal:2',
            'total_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_exempt_amount' => 'decimal:2',
            'points_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $order) {
            if (! $order->isDirty('status')) {
                return;
            }

            if (OrderStatus::isTerminal((string) $order->status)) {
                $order->closed_at ??= now();
            }
        });

        // Every way an order is cancelled - the customer, a guest's link, the
        // agent's board, the generic status endpoint - gives its stock back
        // here, so no path can forget to. Whether the units return to the count
        // depends on the status it was cancelled from (StockReservation).
        static::updated(function (self $order) {
            if ($order->wasChanged('status') && $order->status === OrderStatus::CANCELLED) {
                app(StockReservation::class)->orderCancelled($order, $order->getOriginal('status'));
            }

            // Likewise loyalty points (decided 2026-11-24): earned when the
            // order is completed or delivered, given back when it is cancelled
            // or declined, whichever path got it there.
            if ($order->wasChanged('status')) {
                app(LoyaltyPoints::class)->statusChanged($order);
            }
        });
    }

    public function isAdvance(): bool
    {
        return $this->scheduled_for !== null;
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Who the order is for (BR-21b): the name given at checkout, or changed on
     * the order since. Orders from before every order carried one read it off
     * their account.
     */
    public function contactName(): ?string
    {
        $name = trim((string) $this->contact_name);

        if ($name !== '') {
            return $name;
        }

        $account = trim(($this->user?->first_name ?? '').' '.($this->user?->last_name ?? ''));

        return $account === '' ? null : $account;
    }

    public function contactPhone(): ?string
    {
        return $this->contact_phone ?: $this->user?->phone_number;
    }

    /**
     * Make the tracking token and keep its hash (decided 2026-11-22).
     *
     * Derived rather than random, so the server can rebuild it to email the
     * link once the order is paid, while the database still holds only the
     * hash: a copy of the database cannot produce a link without the key.
     * Called inside the placement transaction (OrderPlacement::place), so an
     * order never exists without its link.
     *
     * Kept off $fillable deliberately: a verifier must never be settable from
     * request input, so this is the one way it is written.
     */
    public function mintGuestToken(): string
    {
        $token = $this->deriveGuestToken((string) config('app.key'));

        $this->forceFill(['guest_token_hash' => static::hashGuestToken($token)])->save();

        return $token;
    }

    /**
     * The token behind the stored hash, rebuilt: under the current key, then
     * each of app.previous_keys, so rotating APP_KEY does not strand an order.
     * Null when no key reproduces it (an order whose token was made before
     * tokens were derived).
     */
    public function guestToken(): ?string
    {
        if ($this->guest_token_hash === null) {
            return null;
        }

        $keys = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])]);

        foreach ($keys as $key) {
            $token = $this->deriveGuestToken((string) $key);

            if (hash_equals($this->guest_token_hash, static::hashGuestToken($token))) {
                return $token;
            }
        }

        return null;
    }

    /**
     * HMAC-SHA256 of the order under one key, labelled so it can never match
     * another use of APP_KEY. The placement time is in it as well as the id:
     * a database reset under the same key would otherwise hand an old link
     * the new order that reuses its id.
     */
    private function deriveGuestToken(string $key): string
    {
        $placedAt = CarbonImmutable::parse($this->created_at)->getTimestamp();

        return hash_hmac('sha256', self::GUEST_TOKEN_LABEL.'|'.$this->getKey().'|'.$placedAt, $key);
    }

    public static function hashGuestToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The order a tracking token names, if any.
     *
     * The whereNull is the fence, as it is on the cart: no token may ever resolve
     * to an order that belongs to an account.
     */
    public static function forGuestToken(?string $token): ?self
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return static::query()
            ->whereNull('user_id')
            ->where('guest_token_hash', static::hashGuestToken($token))
            ->first();
    }

    /**
     * The public channel this order's events are broadcast on for its guest.
     *
     * Derived from the stored verifier rather than from the token, because the
     * token is deliberately not stored and so is unavailable at broadcast time.
     * Domain-separated all the same, so the channel name cannot be turned back
     * into the value the database holds.
     */
    public function guestChannel(): ?string
    {
        return $this->guest_token_hash === null
            ? null
            : hash('sha256', 'channel|'.$this->guest_token_hash);
    }

    /**
     * When the tracking link stops working: a fixed number of days from
     * placement, whatever happens to the order meanwhile (decided 2026-11-22).
     *
     * One predicate for both surfaces - the HTTP routes and broadcasting consult
     * this and nothing else, so the two windows cannot drift apart. The hash is
     * never cleared afterwards, so a lapsed link is still recognised and can be
     * told why it no longer opens the order (ResolveGuestOrder).
     */
    public function guestAccessExpiresAt(): ?CarbonImmutable
    {
        if ($this->guest_token_hash === null) {
            return null;
        }

        return CarbonImmutable::parse($this->created_at)
            ->addDays((int) config('store.guest.track_days'));
    }

    public function guestAccessIsLive(?CarbonInterface $at = null): bool
    {
        $expires = $this->guestAccessExpiresAt();

        return $expires !== null
            && $expires->greaterThan($at ? CarbonImmutable::parse($at) : CarbonImmutable::now());
    }

    /**
     * Paid when a payment for it is confirmed (decided 2026-11-23: the payment
     * record is the only record of it). Reads the paidPayment relation, so a
     * model kept across a settlement needs refresh() first.
     */
    public function isPaid(): bool
    {
        return $this->paidPayment !== null;
    }

    /** "paid" or "unpaid", as the API has always answered. */
    public function paymentStatus(): string
    {
        return $this->isPaid() ? Payment::STATUS_PAID : 'unpaid';
    }

    /**
     * An immediate order still being paid for, or one whose payment window
     * closed unpaid. Nobody sees these, staff or customer (decided
     * 2026-11-17): it is not a real order until it is paid. The row exists so
     * the stock hold knows which checkout it is holding for.
     *
     * Never paid, and either still waiting for payment or cancelled by nobody:
     * the system lets a checkout lapse with cancelled_by empty, while a store
     * cancellation always names the agent.
     */
    public function isUnpaidCheckout(): bool
    {
        if ($this->isAdvance() || $this->isPaid()) {
            return false;
        }

        return $this->status === OrderStatus::AWAITING_PAYMENT
            || ($this->status === OrderStatus::CANCELLED && $this->cancelled_by === null);
    }

    /** Real orders only: every order except an unpaid immediate checkout. */
    public function scopeWithoutUnpaidCheckouts(Builder $query): Builder
    {
        return $query->where(fn (Builder $seen) => $seen
            ->whereNotNull('orders.scheduled_for')
            ->orWhereHas('payments', fn (Builder $payment) => $payment->verified())
            ->orWhereNotIn('orders.status', [OrderStatus::AWAITING_PAYMENT, OrderStatus::CANCELLED])
            ->orWhere(fn (Builder $cancelled) => $cancelled
                ->where('orders.status', OrderStatus::CANCELLED)
                ->whereNotNull('orders.cancelled_by')));
    }

    /** Orders with a confirmed payment. */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereHas('payments', fn (Builder $payment) => $payment->verified());
    }

    /** Orders without a confirmed payment. */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereDoesntHave('payments', fn (Builder $payment) => $payment->verified());
    }

    /**
     * When the customer must have paid by.
     *
     * An immediate order: 10 minutes from checkout (FR-04.5).
     *
     * An advance order, once accepted (decided 2026-11-20):
     *   - collected today or tomorrow, counted from the day it was accepted,
     *     with a preparation time: the collection time less that time;
     *   - otherwise 24 hours after acceptance (BR-16f), and never later than
     *     the collection time itself (BR-16h).
     */
    public function paymentDueAt(): ?CarbonImmutable
    {
        if (! $this->isAdvance()) {
            return $this->status === OrderStatus::AWAITING_PAYMENT && $this->created_at !== null
                ? CarbonImmutable::parse($this->created_at)->addMinutes(self::PAYMENT_WINDOW_MINUTES)
                : null;
        }

        if ($this->accepted_at === null) {
            return null;
        }

        $collect = CarbonImmutable::parse($this->scheduled_for);

        if ($this->paysBeforePreparation()) {
            return $collect->subMinutes((int) $this->preparation_time);
        }

        $dayAfterAcceptance = CarbonImmutable::parse($this->accepted_at)->addHours(self::ADVANCE_PAYMENT_HOURS);

        return $dayAfterAcceptance->lessThan($collect) ? $dayAfterAcceptance : $collect;
    }

    /**
     * Whether this advance order's deadline is its collection time less the
     * preparation time, rather than the 24-hour rule.
     */
    public function paysBeforePreparation(?CarbonInterface $acceptedAt = null): bool
    {
        $acceptedAt ??= $this->accepted_at;

        if (! $this->isAdvance() || $acceptedAt === null || $this->preparation_time === null) {
            return false;
        }

        return self::collectsSoonAfter(CarbonImmutable::parse($acceptedAt), CarbonImmutable::parse($this->scheduled_for));
    }

    /** Whether a collection falls on the same day as, or the day after, $from in store time. */
    public static function collectsSoonAfter(CarbonImmutable $from, CarbonImmutable $collect): bool
    {
        $timezone = (string) config('store.timezone', 'Asia/Manila');
        $days = $from->setTimezone($timezone)->startOfDay()
            ->diffInDays($collect->setTimezone($timezone)->startOfDay(), false);

        return $days <= self::PREPARATION_RULE_DAYS;
    }

    public function isPaymentOverdue(?CarbonInterface $at = null): bool
    {
        $due = $this->paymentDueAt();

        if ($due === null || $this->isPaid()) {
            return false;
        }

        return $due->lessThanOrEqualTo($at ? CarbonImmutable::parse($at) : CarbonImmutable::now());
    }

    public function statusChain(): array
    {
        return $this->isAdvance()
            ? OrderStatus::advanceChain()
            : OrderStatus::chain($this->order_type);
    }

    public function nextStatus(): ?string
    {
        if (! $this->isAdvance()) {
            return OrderStatus::next((string) $this->status, $this->order_type);
        }

        // Decided 2026-11-20: a paid order for today goes straight into the
        // kitchen; one for a later day is scheduled first.
        if ($this->status === OrderStatus::CONFIRMED && $this->collectionDaysAway() === 0) {
            return OrderStatus::PREPARING;
        }

        return OrderStatus::advanceNext((string) $this->status);
    }

    /** Whole days from today to the collection date in store time: 0 today, negative once passed. */
    public function collectionDaysAway(): ?int
    {
        if ($this->scheduled_for === null) {
            return null;
        }

        $timezone = (string) config('store.timezone', 'Asia/Manila');

        return (int) CarbonImmutable::now($timezone)->startOfDay()
            ->diffInDays($this->scheduled_for->toImmutable()->setTimezone($timezone)->startOfDay(), false);
    }

    public function canTransitionTo(string $status): bool
    {
        return $this->isAdvance()
            ? OrderStatus::allowsAdvance((string) $this->status, $status)
            : OrderStatus::allows((string) $this->status, $status, $this->order_type);
    }

    public function isTerminal(): bool
    {
        return OrderStatus::isTerminal((string) $this->status);
    }

    /**
     * The order's number as staff and customers read it (decided 2026-11-20):
     * "007" for an immediate order once paid, "A-007" for an advance order once
     * accepted, null before then. Both counts restart every day and may repeat;
     * nothing prevents two orders sharing one, and the pickup person is called
     * by name, not by number.
     */
    public function number(): ?string
    {
        if ($this->isAdvance()) {
            return $this->advance_order_number === null ? null : 'A-'.$this->advance_order_number;
        }

        return $this->order_number;
    }

    /**
     * What the customer sees the order called: its number, "#id" for an
     * immediate checkout not paid yet, and for an advance request not accepted
     * yet its collection ("Order for Sunday, November 22 at 3:00 AM").
     */
    public function reference(): string
    {
        $number = $this->number();

        if ($number !== null) {
            return $number;
        }

        if ($this->isAdvance()) {
            return 'Order for '.$this->collectionPhrase();
        }

        return '#'.$this->getKey();
    }

    /** "Order 007", "Order A-007", "Order #29", "Order for Sunday, November 22 at 3:00 AM". */
    public function title(): string
    {
        $reference = $this->reference();

        return str_starts_with($reference, 'Order ') ? $reference : 'Order '.$reference;
    }

    /** "Sunday, November 22 at 3:00 AM", in store time. */
    public function collectionPhrase(): string
    {
        $at = CarbonImmutable::parse($this->scheduled_for)
            ->setTimezone((string) config('store.timezone', 'Asia/Manila'));

        return $at->format('l, F j').' at '.$at->format('g:i A');
    }

    /** The UTC bounds of the store day holding $moment. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function storeDay(CarbonInterface $moment): array
    {
        $start = CarbonImmutable::parse($moment)->setTimezone((string) config('store.timezone', 'Asia/Manila'))->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** Orders created on the store day holding $moment. */
    public function scopeCreatedOnStoreDay(Builder $query, CarbonInterface $moment): Builder
    {
        [$start, $end] = self::storeDay($moment);

        return $query->where('orders.created_at', '>=', $start)->where('orders.created_at', '<', $end);
    }

    /** Orders created before the store day holding $moment. */
    public function scopeCreatedBeforeStoreDay(Builder $query, CarbonInterface $moment): Builder
    {
        return $query->where('orders.created_at', '<', self::storeDay($moment)[0]);
    }

    /**
     * Give a paid immediate order the next number of the day it was created
     * on. Nothing locks or retries (decided 2026-11-20): two payments settled
     * at the same instant may take the same number.
     */
    public function assignOrderNumber(): bool
    {
        if ($this->isAdvance() || $this->order_number !== null) {
            return false;
        }

        $last = static::query()
            ->whereNull('scheduled_for')
            ->whereNotNull('order_number')
            ->createdOnStoreDay(CarbonImmutable::parse($this->created_at ?? now()))
            ->max(DB::raw('CAST(order_number AS INTEGER)'));

        $this->forceFill(['order_number' => self::pad((int) $last + 1)])->save();

        return true;
    }

    /**
     * Give an advance order the next number of the day it is accepted on,
     * counted apart from immediate orders. Not saved here: the caller saves it
     * with the acceptance.
     */
    public function assignAdvanceOrderNumber(CarbonInterface $acceptedAt): void
    {
        if (! $this->isAdvance() || $this->advance_order_number !== null) {
            return;
        }

        [$start, $end] = self::storeDay($acceptedAt);

        $last = static::query()
            ->whereNotNull('scheduled_for')
            ->whereNotNull('advance_order_number')
            ->where('accepted_at', '>=', $start)
            ->where('accepted_at', '<', $end)
            ->max(DB::raw('CAST(advance_order_number AS INTEGER)'));

        $this->advance_order_number = self::pad((int) $last + 1);
    }

    private static function pad(int $number): string
    {
        return str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /** The staff member or customer who cancelled it, if it was cancelled. */
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Null for guest orders. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Null for guest orders; the snapshot fields on this table are the record. */
    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The payment that paid for the order: the first one confirmed. Any later
     * confirmed payment is an extra payment, owed back in full (decided
     * 2026-11-23).
     */
    public function paidPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->ofMany(
            ['paid_at' => 'min', 'id' => 'min'],
            fn (Builder $payment) => $payment->verified(),
        );
    }

    /** Confirmed payments after the one that paid for the order. */
    public function extraPayments()
    {
        // With no paid payment there is nothing extra: id 0 matches no row's exclusion,
        // and verified() finds nothing anyway.
        return $this->payments()
            ->verified()
            ->whereKeyNot($this->paidPayment?->getKey() ?? 0)
            ->orderBy('paid_at')
            ->orderBy('id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /**
     * The order's points (decided 2026-11-24), read from its ledger rows:
     * the pesos of the bill paid with points, the points that took and has
     * not given back, and the points it earned once completed.
     *
     * @return array{amount: float, points_used: float, points_earned: float}
     */
    public function loyaltySummary(): array
    {
        $rows = $this->loyaltyTransactions;

        $spent = $rows->whereIn('reason', [LoyaltyTransaction::REDEEMED, LoyaltyTransaction::RETURNED])->sum('points_change');

        return [
            'amount' => round((float) $this->points_amount, 2),
            'points_used' => round(-(float) $spent, 2),
            'points_earned' => round((float) $rows->where('reason', LoyaltyTransaction::EARNED)->sum('points_change'), 2),
        ];
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }
}
