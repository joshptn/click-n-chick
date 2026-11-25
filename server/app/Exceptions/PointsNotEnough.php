<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The customer's points no longer cover the dishes picked to pay with them
 * (decided 2026-11-24): spent on another order between the quote and placing
 * this one. Thrown inside the place-order transaction to roll it back.
 */
class PointsNotEnough extends RuntimeException
{
    public const CODE = 'POINTS_NOT_ENOUGH';

    public function __construct()
    {
        parent::__construct('Your points no longer cover what you picked to pay with them. Check your order and try again.');
    }
}
