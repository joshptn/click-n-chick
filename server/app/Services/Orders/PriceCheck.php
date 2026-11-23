<?php

namespace App\Services\Orders;

/**
 * The customer pays the total they were shown (decided 2026-11-24).
 *
 * Placing an order, sending an advance request and paying for one each take
 * `expected_total`, the total on the page when the customer tapped. Prices may
 * have changed since (BR-18); if the total the server works out now differs,
 * nothing is placed or charged, and the answer carries the new figures for the
 * page to show. A request without it (an older app) goes ahead as before.
 */
final class PriceCheck
{
    public const ERROR_CODE = 'PRICE_CHANGED';

    public const FIELD = 'expected_total';

    public const RULE = ['sometimes', 'nullable', 'numeric', 'min:0'];

    /** @return array{message: string, error_code: string}|null null when the totals agree, or none was sent */
    public static function refusal(mixed $shown, float $total): ?array
    {
        if ($shown === null || $shown === '' || ! is_numeric($shown)) {
            return null;
        }

        if (abs(round((float) $shown, 2) - round($total, 2)) < 0.005) {
            return null;
        }

        return [
            'message' => 'Prices changed, so your total is now ₱'.number_format($total, 2).'. Please check it, then try again.',
            'error_code' => self::ERROR_CODE,
        ];
    }
}
