<?php

namespace App\Services\Orders;

use App\Services\Store\StoreAvailability;

final class OrderStatus
{
    public const PLACED = 'placed';

    public const CONFIRMED = 'confirmed';

    public const PREPARING = 'preparing';

    public const READY_FOR_PICKUP = 'ready_for_pickup';

    public const ON_THE_WAY = 'on_the_way';

    public const COMPLETED = 'completed';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /** Advance orders only (BRD §3.2). Pickup, never delivery. */
    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const SCHEDULED = 'scheduled';

    public const REJECTED = 'rejected';

    public static function chain(?string $fulfilmentType): array
    {
        if ($fulfilmentType === StoreAvailability::TYPE_DELIVERY) {
            return [self::PLACED, self::CONFIRMED, self::PREPARING, self::ON_THE_WAY, self::DELIVERED];
        }

        return [self::PLACED, self::CONFIRMED, self::PREPARING, self::READY_FOR_PICKUP, self::COMPLETED];
    }

    /**
     * Once an advance order reaches `preparing` it is indistinguishable from an
     * immediate pickup order, so the tail here is the pickup chain (BR-22c).
     */
    public static function advanceChain(): array
    {
        return [
            self::SUBMITTED,
            self::ACCEPTED,
            self::AWAITING_PAYMENT,
            self::CONFIRMED,
            self::SCHEDULED,
            self::PREPARING,
            self::READY_FOR_PICKUP,
            self::COMPLETED,
        ];
    }

    /** Statuses only an advance order ever holds. */
    public static function advanceOnly(): array
    {
        return [self::SUBMITTED, self::ACCEPTED, self::AWAITING_PAYMENT, self::SCHEDULED, self::REJECTED];
    }

    public static function isAdvanceOnly(string $status): bool
    {
        return in_array($status, self::advanceOnly(), true);
    }

    public static function all(): array
    {
        return [
            self::PLACED,
            self::CONFIRMED,
            self::PREPARING,
            self::READY_FOR_PICKUP,
            self::ON_THE_WAY,
            self::COMPLETED,
            self::DELIVERED,
            self::CANCELLED,
            self::SUBMITTED,
            self::ACCEPTED,
            self::AWAITING_PAYMENT,
            self::SCHEDULED,
            self::REJECTED,
        ];
    }

    /** @return array<int, string> */
    public static function terminal(): array
    {
        return [self::COMPLETED, self::DELIVERED, self::CANCELLED, self::REJECTED];
    }

    public static function inLine(): array
    {
        return [self::PLACED, self::CONFIRMED, self::PREPARING];
    }

    public static function isInLine(string $status): bool
    {
        return in_array($status, self::inLine(), true);
    }

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }

    public static function next(string $from, ?string $fulfilmentType): ?string
    {
        $chain = self::chain($fulfilmentType);
        $at = array_search($from, $chain, true);

        if ($at === false) {
            return null;
        }

        return $chain[$at + 1] ?? null;
    }

    public static function transitionsFrom(string $from, ?string $fulfilmentType): array
    {
        if (self::isTerminal($from)) {
            return [];
        }

        $next = self::next($from, $fulfilmentType);

        return $next === null ? [self::CANCELLED] : [$next, self::CANCELLED];
    }

    public static function allows(string $from, string $to, ?string $fulfilmentType): bool
    {
        return in_array($to, self::transitionsFrom($from, $fulfilmentType), true);
    }

    public static function isCustomerCancellable(string $status): bool
    {
        return $status === self::PLACED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PLACED => 'Placed',
            self::CONFIRMED => 'Confirmed',
            self::PREPARING => 'Preparing',
            self::READY_FOR_PICKUP => 'Ready for pickup',
            self::ON_THE_WAY => 'On the way',
            self::COMPLETED => 'Completed',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Cancelled',
            self::SUBMITTED => 'Request sent',
            self::ACCEPTED => 'Accepted',
            self::AWAITING_PAYMENT => 'Awaiting payment',
            self::SCHEDULED => 'Scheduled',
            self::REJECTED => 'Rejected',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
