<?php

namespace Tests\Unit;

use App\Services\Orders\StatutoryDiscount;
use PHPUnit\Framework\TestCase;

/**
 * RA 9994 / RA 10754: VAT off the VAT-inclusive price first, then the rate, on
 * one unit of the dearest dish marked for it, kept to the centavo with no
 * rounding to the peso.
 */
class StatutoryDiscountTest extends TestCase
{
    public function test_the_vat_comes_off_before_the_discount_rate(): void
    {
        // ₱180 → ₱160.71 before VAT: ₱19.29 + ₱32.14 = ₱51.43, as it is.
        $this->assertSame(
            ['vat_exempt' => 19.29, 'discount' => 32.14, 'total' => 51.43],
            StatutoryDiscount::for(180, 20)
        );
    }

    public function test_a_higher_rate_applies_to_the_vat_exclusive_price(): void
    {
        // ₱42.86 + 25% of ₱357.14.
        $this->assertSame(
            ['vat_exempt' => 42.86, 'discount' => 89.29, 'total' => 132.15],
            StatutoryDiscount::for(400, 25)
        );
    }

    /** No rounding to the peso, up or down. */
    public function test_centavos_are_kept(): void
    {
        // ₱160 → ₱142.86 before VAT: ₱17.14 + ₱28.57 = ₱45.71, not ₱46.
        $this->assertSame(
            ['vat_exempt' => 17.14, 'discount' => 28.57, 'total' => 45.71],
            StatutoryDiscount::for(160, 20)
        );
    }

    public function test_the_dearest_marked_dish_is_picked(): void
    {
        $lines = [
            ['food_id' => 1, 'food_name' => 'Spaghetti', 'base_price' => 180.0, 'is_discountable' => true],
            ['food_id' => 2, 'food_name' => 'Herb Chicken', 'base_price' => 360.0, 'is_discountable' => false],
            ['food_id' => 3, 'food_name' => 'Fried Chicken', 'base_price' => 210.0, 'is_discountable' => true],
            ['food_id' => 4, 'food_name' => 'Sold out', 'base_price' => 300.0, 'is_discountable' => true, 'is_orderable' => false],
        ];

        $this->assertSame(3, StatutoryDiscount::pick($lines)['food_id']);
        $this->assertNull(StatutoryDiscount::pick([$lines[1]]));
    }

    public function test_nothing_to_discount_is_zero(): void
    {
        $this->assertSame(
            ['vat_exempt' => 0.0, 'discount' => 0.0, 'total' => 0.0],
            StatutoryDiscount::for(0, 20)
        );
    }
}
