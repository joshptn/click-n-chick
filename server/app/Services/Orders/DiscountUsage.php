<?php

namespace App\Services\Orders;

use App\Models\Discount;
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
