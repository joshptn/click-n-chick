<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Order;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Utils\Notification as Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The automatic payment reminder (decided 2026-11-20): an accepted advance
 * order still unpaid two hours before its pay-by time is reminded once, with
 * that time and that the store cancels it otherwise.
 *
 * Skipped when the whole window was two hours or less (the acceptance already
 * said when to pay), or when a reminder, a Store Agent's included, already
 * went out in those two hours.
 */
class RemindUnpaidAdvanceOrders extends Command
{
    public const HOURS_BEFORE = 2;

    protected $signature = 'advance:remind-payment {--dry-run : List who would be reminded without sending anything}';

    protected $description = 'Remind customers to pay accepted advance orders two hours before the pay-by time';

    public function handle(OrderNotice $notice): int
    {
        $now = CarbonImmutable::now();

        $due = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereNotNull('user_id')
            ->whereNotNull('accepted_at')
            ->whereIn('status', [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT])
            ->where(fn ($payment) => $payment->whereNull('payment_status')->orWhere('payment_status', '!=', Order::PAYMENT_PAID))
            ->where('scheduled_for', '>', $now)
            ->orderBy('id')
            ->get()
            ->filter(fn (Order $order) => $this->shouldRemind($order, $now));

        foreach ($due as $order) {
            $this->line("#{$order->id}: pay by {$order->paymentDueAt()?->toIso8601String()}");

            if (! $this->option('dry-run')) {
                Notifier::send((int) $order->user_id, $notice->paymentDueSoon($order), $order);
            }
        }

        $this->info($due->count().' customer(s) '.($this->option('dry-run') ? 'would be ' : '').'reminded.');

        return self::SUCCESS;
    }

    private function shouldRemind(Order $order, CarbonImmutable $now): bool
    {
        $deadline = $order->paymentDueAt();

        if ($deadline === null || $deadline->lessThanOrEqualTo($now) || $order->isPaid()) {
            return false;
        }

        $remindFrom = $deadline->subHours(self::HOURS_BEFORE);

        if ($now->lessThan($remindFrom) || CarbonImmutable::parse($order->accepted_at)->greaterThanOrEqualTo($remindFrom)) {
            return false;
        }

        return ! Notification::query()
            ->where('order_id', $order->getKey())
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT)
            ->where('created_at', '>=', $remindFrom)
            ->exists();
    }
}
