<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class NotifyAdvanceOrdersDueToday extends Command
{
    protected $signature = 'advance:notify-due {--dry-run : List what would be sent without sending it}';

    protected $description = 'Notify staff of advance orders due for collection today';

    public function handle(StoreAvailability $store, OrderAnnouncer $announcer, OrderNotice $notice): int
    {
        $timezone = $store->timezone();
        $now = CarbonImmutable::now($timezone);

        if ($now->format('H:i') < $store->opensAt()) {
            $this->info("Before opening ({$store->opensAt()}); nothing sent yet.");

            return self::SUCCESS;
        }

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
            $this->line("#{$order->id} — due {$at}".($order->isPaid() ? '' : ' (unpaid)'));

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


    private function alreadyAnnounced(Order $order, CarbonImmutable $now): bool
    {
        return Notification::query()
            ->where('order_id', $order->getKey())
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_DUE)
            ->where('created_at', '>=', $now->startOfDay()->utc())
            ->exists();
    }
}
