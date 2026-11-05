<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An order asked for more than is left to sell (GATE-7, FR-08.3).
 *
 * Thrown from inside the place-order transaction once the food rows are
 * locked, so it rolls the order back. The checkout quote catches the same
 * shortage earlier; this is the authoritative check that wins the race when
 * two customers go for the last units at once.
 */
class InsufficientStock extends RuntimeException
{
    /** @param  array<int, array{food_id: int, food_name: string, requested: int, available: int}>  $shortages */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(self::describe($shortages));
    }

    /** @param  array<int, array{food_name: string, available: int}>  $shortages */
    public static function describe(array $shortages): string
    {
        if (count($shortages) !== 1) {
            return count($shortages).' of your items do not have enough left. Lower the quantities to continue.';
        }

        $short = $shortages[0];

        return $short['available'] > 0
            ? "Only {$short['available']} {$short['food_name']} left. Lower the quantity to continue."
            : "{$short['food_name']} just sold out. Remove it to continue.";
    }
}
