<?php

namespace App\Services\Orders;

use App\Models\Discount;

/**
 * The Senior Citizen or PWD discount on an order (decided 2026-11-23, PRD
 * FR-05.4, BRD BR-10).
 *
 * It goes on one unit of one dish: the highest-priced dish in the order that
 * the Store Manager marked for it. On that unit the VAT comes off first, since
 * menu prices are VAT-inclusive (A-02), then the rate off the VAT-exclusive
 * price, as RA 9994 and RA 10754 have it. The amounts are kept to the
 * centavo, with no rounding to the peso (the stakeholder's decision,
 * 2026-11-23): a ₱180 dish is ₱160.71 before VAT, so a ₱19.29 VAT exemption
 * and a ₱32.14 discount at 20%, ₱51.43 off.
 *
 * `total` is what the order stores as discount_amount (everything taken off),
 * and `vat_exempt` is the part of it stored as vat_exempt_amount, so totals and
 * reports that read discount_amount still add up.
 */
class StatutoryDiscount
{
    /** @return array{vat_exempt: float, discount: float, total: float} */
    public static function for(float $price, float $percentage): array
    {
        if ($price <= 0) {
            return ['vat_exempt' => 0.0, 'discount' => 0.0, 'total' => 0.0];
        }

        $exclusive = round($price / (1 + Discount::VAT_RATE / 100), 2);
        $vatExempt = round($price - $exclusive, 2);
        $discount = round($exclusive * ($percentage / 100), 2);

        return [
            'vat_exempt' => $vatExempt,
            'discount' => $discount,
            'total' => round($vatExempt + $discount, 2),
        ];
    }

    /**
     * The line whose dish takes the discount: the highest base price among the
     * dishes marked for it, add-ons left out. Null when none is marked.
     *
     * @param  iterable<array{base_price: float, is_discountable: bool}>  $lines
     */
    public static function pick(iterable $lines): ?array
    {
        $best = null;

        foreach ($lines as $line) {
            if (! ($line['is_discountable'] ?? false) || ! ($line['is_orderable'] ?? true)) {
                continue;
            }

            if ($best === null || (float) $line['base_price'] > (float) $best['base_price']) {
                $best = $line;
            }
        }

        return $best;
    }

    /** What the quote says the discount goes to, so the screen can name it. */
    public static function describe(?array $line): ?array
    {
        return $line === null ? null : [
            'food_id' => $line['food_id'],
            'food_name' => $line['food_name'],
            'price' => (float) $line['base_price'],
        ];
    }
}
