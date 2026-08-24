<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The account's one discounted order for the day already exists (BR-09).
 *
 * Thrown from inside the place-order transaction to roll it back. A distinct
 * type so the catch can tell it apart from a genuine failure: this is a lost
 * race with the customer's own second tab, not a fault, and it deserves a
 * plain explanation rather than "something went wrong".
 */
class DiscountAlreadyUsed extends RuntimeException {}
