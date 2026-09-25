<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * BR-16f - cancel accepted advance requests left unpaid for 24 hours.
 *
 * Housekeeping rather than protection: the shop is already safe because an
 * unpaid order cannot be prepared (BR-16g). This just stops dead requests
 * accumulating in the agent's list.
 */
class ExpireUnpaidAdvanceOrders extends Command
{
    protected $signature = 'advance:expire-unpaid {--dry-run : List what would be cancelled without cancelling it}';

    protected $description = 'Cancel advance requests still unpaid 24 hours after they were accepted';

    public function handle(OrderAnnouncer $announcer): int
    {
        $cutoff = CarbonImmutable::now()->subHours(Order::ADVANCE_PAYMENT_HOURS);

        $expired = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereIn('status', [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT])
            ->where('payment_status', '!=', 'paid')
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $cutoff)
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Nothing to expire.');

            return self::SUCCESS;
        }

        foreach ($expired as $order) {
            $this->line("#{$order->id} — accepted {$order->accepted_at}, still unpaid");

            if ($this->option('dry-run')) {
                continue;
            }

            $order->forceFill([
                'status' => OrderStatus::CANCELLED,
                'cancellation_reason' => 'Payment was not received within 24 hours of the store accepting this request.',
                'refund_owed' => 0,
            ])->save();

            $announcer->statusChanged($order);
        }

        $this->info(
            $this->option('dry-run')
                ? $expired->count().' request(s) would be cancelled.'
                : $expired->count().' request(s) cancelled for non-payment.'
        );

        return self::SUCCESS;
    }
}
