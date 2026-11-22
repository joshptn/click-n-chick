<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An immediate order not paid within 10 minutes of checkout is cancelled and
 * its stock released (FR-04.5, decided 2026-11-17). Nothing was charged, so
 * nothing is refunded. Nobody is told and nobody sees it, staff or customer
 * (decided 2026-11-17): it never became an order (Order::scopeWithoutUnpaidCheckouts).
 *
 * The checkout is closed with the provider first, so a customer cannot pay
 * into an order that is about to go. A QR Ph code cannot be given an expiry
 * of its own, so a payment can still slip through afterwards; the settlement
 * revives the order then, or owes the money back.
 */
class CheckoutExpiry
{
    public const REASON = 'Payment was not completed within 10 minutes.';

    public const ERROR_CODE = 'PAYMENT_WINDOW_CLOSED';

    public function __construct(private PaymentCheckout $checkout) {}

    /** @return Collection<int, Order> */
    public function due(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return Order::query()
            ->whereNull('scheduled_for')
            ->where('status', OrderStatus::AWAITING_PAYMENT)
            ->where('created_at', '<=', $now->subMinutes(Order::PAYMENT_WINDOW_MINUTES))
            ->orderBy('id')
            ->get();
    }

    public function isDue(Order $order, ?CarbonImmutable $now = null): bool
    {
        $due = $order->paymentDueAt();

        return ! $order->isAdvance()
            && $order->status === OrderStatus::AWAITING_PAYMENT
            && $due !== null
            && $due->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /** Cancel one checkout if it is still unpaid and due. Returns the cancelled order, or null. */
    public function expire(Order $order, ?CarbonImmutable $now = null): ?Order
    {
        if (! $this->isDue($order, $now)) {
            return null;
        }

        // Paid at the last moment: the settlement has placed it instead.
        if ($this->checkout->closeOpen($order)) {
            return null;
        }

        return DB::transaction(function () use ($order, $now) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $this->isDue($locked, $now)) {
                return null;
            }

            $locked->forceFill([
                'status' => OrderStatus::CANCELLED,
                'cancellation_reason' => self::REASON,
                'cancelled_by' => null,
            ])->save();

            $locked->payments()->pending()->update(['payment_status' => Payment::STATUS_CLOSED]);

            return $locked;
        });
    }

    /**
     * A checkout the provider could not open: the order is withdrawn at once,
     * its stock released, so the customer can simply try again. Not announced;
     * the customer is still on the checkout page and is told there.
     */
    public function abandon(Order $order): void
    {
        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'cancellation_reason' => 'Payment could not be started.',
            'cancelled_by' => null,
        ])->save();
    }

    /**
     * Called before acting on an order for its customer, so a checkout past its
     * window is closed now rather than at the next scheduler run. Returns the
     * refusal to send back when it was, or null. Refreshes the given model.
     *
     * @return array{message: string, error_code: string, status: string}|null
     */
    public function enforce(Order $order, ?CarbonImmutable $now = null): ?array
    {
        if (! $this->isDue($order, $now)) {
            return null;
        }

        $expired = $this->expire($order, $now);
        $order->refresh();

        return $expired === null ? null : [
            'message' => 'This order was cancelled because it was not paid within 10 minutes. Nothing was charged.',
            'error_code' => self::ERROR_CODE,
            'status' => (string) $order->status,
        ];
    }
}
