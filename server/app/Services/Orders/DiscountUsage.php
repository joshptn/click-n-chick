<?php

namespace App\Services\Orders;

use App\Models\Discount;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;

class DiscountUsage
{
    public function usedOn(User $user, CarbonImmutable $day, ?int $ignoreOrderId = null): bool
    {
        $start = $day->setTimezone(Discount::USAGE_TIMEZONE)->startOfDay();
        $end = $start->endOfDay();

        return $user->orders()
            ->where('discount_amount', '>', 0)
            // Decided 2026-11-17: an immediate order uses the day's discount only
            // once it is paid and placed. One still being paid for, or one that
            // lapsed unpaid, has used nothing.
            ->where(fn ($query) => $query
                ->whereNotNull('scheduled_for')
                ->orWhere('payment_status', Order::PAYMENT_PAID))
            ->when($ignoreOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreOrderId))
            ->where(function ($query) use ($start, $end) {
                $query
                    ->where(fn ($q) => $q
                        ->whereNull('scheduled_for')
                        ->whereBetween('created_at', [$start->utc(), $end->utc()]))
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('scheduled_for')
                        ->whereBetween('scheduled_for', [$start->utc(), $end->utc()]));
            })
            ->exists();
    }

    public function usedToday(User $user): bool
    {
        return $this->usedOn($user, CarbonImmutable::now(Discount::USAGE_TIMEZONE));
    }
}
