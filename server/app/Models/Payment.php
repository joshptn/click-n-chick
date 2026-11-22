<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /** A hosted checkout is open and has not taken money yet. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** The checkout closed without taking money: it expired, or we cancelled it. */
    public const STATUS_CLOSED = 'closed';

    public const METHOD_GCASH = 'GCASH';

    public const METHOD_QRPH = 'QRPH';

    /**
     * Refund states (decided 2026-11-23). A refund is the full amount or
     * nothing; refund_amount 0 with no state is the store's "No refund".
     */
    /** "Refunding": a GCash refund sent to Xendit, waiting for its answer. */
    public const REFUND_PENDING = 'pending';

    /** "Refund by hand": QR Ph (Xendit cannot refund it), or a GCash refund Xendit refused. */
    public const REFUND_MANUAL = 'manual';

    /** "Refunded": Xendit confirmed it, or a Store Agent ticked it. */
    public const REFUND_REFUNDED = 'refunded';

    /**
     * Columns as used with Xendit (no new columns, by decision):
     *   provider_reference_id  the payment session id (ps-...)
     *   idempotency_key        the Xendit payment id (py-...), set once paid; unique,
     *                          so one payment can be recorded only once (FR-04.4)
     *   payment_method         QRPH or GCASH, once paid
     *   refund_reference_id    the Xendit refund id (rfd-...), or the reference a
     *                          Store Agent gave a refund by hand
     *
     * Since 2026-11-23 this is the only record of whether an order was paid and
     * what is owed back on it; the order keeps neither.
     */
    protected $fillable = [
        'order_id',
        'user_id',
        'payment_method',
        'payment_status',
        'provider_reference_id',
        'idempotency_key',
        'paid_at',
        'refund_amount',
        'refund_status',
        'refund_reference_id',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refund_amount' => 'decimal:2',
        ];
    }

    /** Money confirmed as received: a paid record with the moment it was paid. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('payments.payment_status', self::STATUS_PAID)->whereNotNull('payments.paid_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('payments.payment_status', self::STATUS_PENDING);
    }

    /** Payments with money owed back on them, refunded or not. */
    public function scopeRefunds(Builder $query): Builder
    {
        return $query->where('payments.refund_amount', '>', 0);
    }

    /** Refunds not yet refunded. */
    public function scopeOwed(Builder $query): Builder
    {
        return $query->refunds()->where(fn (Builder $state) => $state
            ->whereNull('payments.refund_status')
            ->orWhere('payments.refund_status', '!=', self::REFUND_REFUNDED));
    }

    public function isVerified(): bool
    {
        return $this->payment_status === self::STATUS_PAID && $this->paid_at !== null;
    }

    public function isGcash(): bool
    {
        return $this->payment_method === self::METHOD_GCASH;
    }

    public function owesRefund(): bool
    {
        return (float) ($this->refund_amount ?? 0) > 0 && $this->refund_status !== self::REFUND_REFUNDED;
    }

    /**
     * The refund as screens show it: refunding, by_hand, refunded, none (the
     * store chose no refund), or null when no refund question arose.
     */
    public function refundState(): ?string
    {
        if ($this->refund_amount === null) {
            return null;
        }

        if ((float) $this->refund_amount <= 0) {
            return 'none';
        }

        return match ($this->refund_status) {
            self::REFUND_REFUNDED => 'refunded',
            self::REFUND_PENDING => 'refunding',
            default => 'by_hand',
        };
    }

    /** The refund for an API answer, or null when none arose. */
    public function refundSummary(): ?array
    {
        $state = $this->refundState();

        if ($state === null) {
            return null;
        }

        return [
            'payment_id' => $this->getKey(),
            'amount' => round((float) $this->refund_amount, 2),
            'state' => $state,
            'method' => $this->payment_method,
            'reference' => $this->refund_reference_id,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
        ];
    }

    /** The reference a GCash refund is sent to Xendit under; one per payment. */
    public function refundReference(): string
    {
        return 'cnc-refund-'.$this->getKey();
    }

    /** The payment a Xendit refund reference names, if it is one of ours. */
    public static function forRefundReference(?string $reference): ?self
    {
        if (! is_string($reference) || ! preg_match('/^cnc-refund-(\d+)$/', $reference, $match)) {
            return null;
        }

        return static::query()->find((int) $match[1]);
    }

    /** The reference sent to Xendit; it names this row so a webhook can find it. */
    public function gatewayReference(): string
    {
        return 'cnc-order-'.$this->order_id.'-payment-'.$this->getKey();
    }

    /** The payment row a Xendit reference names, if it is one of ours. */
    public static function forGatewayReference(?string $reference): ?self
    {
        if (! is_string($reference) || ! preg_match('/^cnc-order-(\d+)-payment-(\d+)$/', $reference, $match)) {
            return null;
        }

        return static::query()->whereKey((int) $match[2])->where('order_id', (int) $match[1])->first();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
