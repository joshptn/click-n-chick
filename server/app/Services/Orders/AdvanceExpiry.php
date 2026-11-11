<?php

namespace App\Services\Orders;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid advance requests that can no longer go ahead (BR-16f, BR-16h).
 *
 * A request is cancelled by the system when it is still unpaid and either:
 *
 *   - it was accepted and its 24-hour payment deadline has passed, or
 *   - its collection time has passed, whether or not it was ever answered.
 *
 * Paid orders are never touched: the order is re-read under a row lock and
 * checked again just before it is cancelled, so a payment landing at the same
 * moment wins, and running the sweep twice cancels nothing twice.
 *
 * The reasons are stored on the order and shown to the customer. They are also
 * how reports tell an expiry apart from a customer's own cancellation, so they
 * are kept here, once, and matched exactly.
 *
 * Two callers share these rules, so they cannot drift apart:
 *
 *   - Immediate enforcement: every action on an advance order calls enforce()
 *     first. An expired request is cancelled there and then, and the action is
 *     refused, even if the sweep has not reached it yet.
 *   - Scheduled cleanup: advance:expire-unpaid runs every five minutes and
 *     cancels whatever nobody has touched.
 */
final class AdvanceExpiry
{
    public const ERROR_CODE = 'ADVANCE_EXPIRED';

    public function __construct(private OrderAnnouncer $announcer) {}

    public const PAYMENT_DEADLINE = 'Payment was not received within 24 hours of the store accepting this request.';

    public const PAYMENT_BEFORE_COLLECTION = 'Payment was not received before the collection time.';

    public const UNANSWERED = 'The store did not answer this request before the collection time.';

    public const REASONS = [self::PAYMENT_DEADLINE, self::PAYMENT_BEFORE_COLLECTION, self::UNANSWERED];

    /** The statuses an unpaid advance request rests in. */
    public const UNPAID_STATUSES = [OrderStatus::SUBMITTED, OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT];

    /** Whether a closed order ended because the system expired it. */
    public static function expired(?string $status, ?string $reason, mixed $cancelledBy): bool
    {
        return $status === OrderStatus::CANCELLED
            && $cancelledBy === null
            && in_array($reason, self::REASONS, true);
    }

    /** @return Collection<int, Order> Requests that look due. Each is checked again before it is cancelled. */
    public function due(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return $this->unpaid(Order::query())
            ->where(fn (Builder $query) => $query
                ->where('scheduled_for', '<=', $now)
                ->orWhere(fn (Builder $accepted) => $accepted
                    ->whereIn('status', [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT])
                    ->whereNotNull('accepted_at')
                    ->where('accepted_at', '<=', $now->subHours(Order::ADVANCE_PAYMENT_HOURS))))
            ->orderBy('id')
            ->get();
    }

    /** Why this order should be cancelled now, or null if it should not. */
    public function reasonFor(Order $order, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();

        if (! $order->isAdvance() || ! in_array($order->status, self::UNPAID_STATUSES, true) || $this->isPaid($order)) {
            return null;
        }

        $collect = $order->scheduled_for->toImmutable();
        $deadline = $order->status === OrderStatus::SUBMITTED ? null : $order->paymentDueAt();
        $collectionPassed = $collect->lessThanOrEqualTo($now);
        $deadlinePassed = $deadline !== null && $deadline->lessThanOrEqualTo($now);

        if (! $collectionPassed && ! $deadlinePassed) {
            return null;
        }

        if ($order->status === OrderStatus::SUBMITTED) {
            return self::UNANSWERED;
        }

        // Whichever came first is what actually ended it.
        return $deadlinePassed && (! $collectionPassed || $deadline->lessThanOrEqualTo($collect))
            ? self::PAYMENT_DEADLINE
            : self::PAYMENT_BEFORE_COLLECTION;
    }

    /** Cancel one request if it is still due. Returns the cancelled order, or null. */
    public function expire(Order $order, ?CarbonImmutable $now = null): ?Order
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($order, $now) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            $reason = $locked === null ? null : $this->reasonFor($locked, $now);

            if ($reason === null) {
                return null;
            }

            $locked->forceFill([
                'status' => OrderStatus::CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => null,
                'refund_owed' => 0,
            ])->save();

            return $locked;
        });
    }

    /** Cancel one request if it is still due and tell the customer and staff. */
    public function expireAndAnnounce(Order $order, ?CarbonImmutable $now = null): ?Order
    {
        $expired = $this->expire($order, $now);

        if ($expired !== null) {
            $this->announcer->statusChanged($expired);
        }

        return $expired;
    }

    /**
     * Immediate enforcement, called before any action on an order.
     *
     * Returns null when the action may go ahead. When the order is an unpaid
     * advance request past its deadline or collection time, it is cancelled now
     * through expire(), the given model is refreshed to match, and the refusal
     * to send back is returned.
     *
     * @return array{message: string, error_code: string, status: string}|null
     */
    public function enforce(Order $order, ?CarbonImmutable $now = null): ?array
    {
        // Cheap for everything that is not an unpaid advance request: no lock,
        // no transaction, no query.
        if ($this->reasonFor($order, $now) === null) {
            return null;
        }

        $expired = $this->expireAndAnnounce($order, $now);

        $order->refresh();

        if ($expired === null) {
            // Paid or answered in the meantime; the caller's own checks decide.
            return null;
        }

        return [
            'message' => 'This request has expired and has been cancelled. '
                .$expired->cancellation_reason.' Nothing was charged.',
            'error_code' => self::ERROR_CODE,
            'status' => (string) $expired->status,
        ];
    }

    private function unpaid(Builder $query): Builder
    {
        return $query
            ->whereNotNull('scheduled_for')
            ->whereIn('status', self::UNPAID_STATUSES)
            ->where(fn (Builder $payment) => $payment->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
            ->whereDoesntHave('payments', fn (Builder $payment) => $payment->verified());
    }

    private function isPaid(Order $order): bool
    {
        return $order->isPaid() || $order->payments()->verified()->exists();
    }
}
