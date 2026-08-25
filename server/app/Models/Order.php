<?php

namespace App\Models;

use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    private const QUEUE_ATTEMPTS = 3;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'address_id',
        'order_number',
        'order_type',
        'scheduled_for',
        'pickup_at',
        'status',
        'queue_number',
        'queue_date',
        'queued_at',
        'cancellation_reason',
        'closed_at',
        'total_price',
        'subtotal',
        'discount_amount',
        'delivery_fee',
        'delivery_distance_km',
        'total_amount',
        'payment_status',
        'estimated_time_of_completion',
        'guest_name',
        'guest_phone',
        'guest_email',
        'full_address',
        'latitude',
        'longitude',
        'location',
        'delivery_note',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'pickup_at' => 'datetime',
            'queue_number' => 'integer',
            'queued_at' => 'datetime',
            'closed_at' => 'datetime',
            'delivery_distance_km' => 'decimal:2',
            'total_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
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
    }

    public function nextStatus(): ?string
    {
        return OrderStatus::next((string) $this->status, $this->order_type);
    }

    public function canTransitionTo(string $status): bool
    {
        return OrderStatus::allows((string) $this->status, $status, $this->order_type);
    }

    public function isTerminal(): bool
    {
        return OrderStatus::isTerminal((string) $this->status);
    }

    public function hasEnteredQueue(): bool
    {
        return $this->queue_number !== null;
    }

    public function isInLine(): bool
    {
        return $this->hasEnteredQueue() && OrderStatus::isInLine((string) $this->status);
    }

    public function queueLabel(): ?string
    {
        if (! $this->hasEnteredQueue()) {
            return null;
        }

        $prefix = trim((string) config('store.queue.label_prefix', ''));
        $number = str_pad((string) $this->queue_number, 3, '0', STR_PAD_LEFT);

        return $prefix === '' ? $number : $prefix.'-'.$number;
    }

    public function reference(): string
    {
        return $this->queueLabel() ?? '#'.$this->getKey();
    }

    public function queuePosition(): ?int
    {
        if (! $this->isInLine()) {
            return null;
        }

        return static::query()
            ->where('queue_date', $this->queue_date)
            ->whereIn('status', OrderStatus::inLine())
            ->where('queue_number', '<=', $this->queue_number)
            ->count();
    }

    public function aheadInQueue(): ?int
    {
        $position = $this->queuePosition();

        return $position === null ? null : $position - 1;
    }

    public function enterQueue(?CarbonInterface $at = null): bool
    {
        if ($this->hasEnteredQueue()) {
            return false;
        }

        $moment = $at ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        $date = $moment->setTimezone((string) config('store.timezone', 'Asia/Manila'))->toDateString();

        for ($attempt = 1; $attempt <= self::QUEUE_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(function () use ($moment, $date) {
                    $last = static::query()
                        ->where('queue_date', $date)
                        ->orderByDesc('queue_number')
                        ->lockForUpdate()
                        ->value('queue_number');

                    $this->forceFill([
                        'queue_number' => (int) $last + 1,
                        'queue_date' => $date,
                        'queued_at' => $moment,
                    ])->save();
                });

                return true;
            } catch (QueryException $e) {
                if ($attempt === self::QUEUE_ATTEMPTS) {
                    throw $e;
                }

                $this->forceFill(['queue_number' => null, 'queue_date' => null, 'queued_at' => null]);
            }
        }

        return false;
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

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }
}
