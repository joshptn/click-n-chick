<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use App\Utils\Notification as Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class NotifyAdvanceOrdersDueToday extends Command
{
    protected $signature = 'advance:notify-due {--dry-run : List what would be sent without sending it}';

    protected $description = 'Notify staff of paid advance orders due today, and remind customers of unpaid ones';

    /** No collection-day reminder this soon after another payment reminder. */
    private const QUIET_MINUTES = 60;

    public function handle(StoreAvailability $store, OrderAnnouncer $announcer, OrderNotice $notice): int
    {
        $timezone = $store->timezone();
        $now = CarbonImmutable::now($timezone);

        if ($now->format('H:i') < $store->opensAt()) {
            $this->info("Before opening ({$store->opensAt()}); nothing sent yet.");

            return self::SUCCESS;
        }

        $this->remindUnpaid($now, $notice);

        $due = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::SCHEDULED])
            ->whereBetween('scheduled_for', [
                $now->startOfDay()->utc(),
                $now->endOfDay()->utc(),
            ])
            ->orderBy('scheduled_for')
            ->get()
            ->reject(fn (Order $order) => $this->alreadyAnnounced($order, $now));

        if ($due->isEmpty()) {
            $this->info('No advance orders due today that staff have not already been told about.');

            return self::SUCCESS;
        }

        foreach ($due as $order) {
            $at = $order->scheduled_for->setTimezone($timezone)->format('g:i A');
            $this->line("#{$order->id} — due {$at}");

            if ($this->option('dry-run')) {
                continue;
            }

            $announcer->toStaff(
                $order,
                $notice->staffDueToday($order),
                [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN],
            );
        }

        $this->info(
            $this->option('dry-run')
                ? $due->count().' order(s) would be announced.'
                : $due->count().' order(s) announced to staff.'
        );

        return self::SUCCESS;
    }

    /**
     * Decided 2026-11-20: a customer whose advance order is collected today
     * and still unpaid is told so once that day, with the pay-by time and that
     * the store cancels it otherwise.
     */
    private function remindUnpaid(CarbonImmutable $now, OrderNotice $notice): void
    {
        $unpaid = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereNotNull('user_id')
            ->whereIn('status', [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT])
            ->where(fn ($payment) => $payment->whereNull('payment_status')->orWhere('payment_status', '!=', Order::PAYMENT_PAID))
            ->whereBetween('scheduled_for', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])
            ->orderBy('scheduled_for')
            ->get()
            ->filter(function (Order $order) use ($now) {
                $deadline = $order->paymentDueAt();

                return $deadline !== null
                    && $deadline->greaterThan($now)
                    && ! $order->isPaid()
                    && ! $this->customerRemindedToday($order, $now);
            });

        foreach ($unpaid as $order) {
            $this->line("#{$order->id}: customer reminded to pay today");

            if (! $this->option('dry-run')) {
                Notifier::send((int) $order->user_id, $notice->customerDueTodayUnpaid($order), $order);
            }
        }
    }

    /** Already sent today, or another payment reminder went out within the hour. */
    private function customerRemindedToday(Order $order, CarbonImmutable $now): bool
    {
        $reminders = Notification::query()
            ->where('order_id', $order->getKey())
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT);

        return (clone $reminders)
            ->where('title', OrderNotice::DUE_TODAY_UNPAID_TITLE)
            ->where('created_at', '>=', $now->startOfDay()->utc())
            ->exists()
            || (clone $reminders)
                ->where('created_at', '>=', $now->subMinutes(self::QUIET_MINUTES)->utc())
                ->exists();
    }

    private function alreadyAnnounced(Order $order, CarbonImmutable $now): bool
    {
        return Notification::query()
            ->where('order_id', $order->getKey())
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_DUE)
            ->where('created_at', '>=', $now->startOfDay()->utc())
            ->exists();
    }
}
