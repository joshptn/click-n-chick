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
 */
final class AdvanceExpiry
{
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
