<?php

namespace Database\Seeders;

use App\Models\Addon;
use Illuminate\Database\Seeder;

/**
 * The optional extras offered on the item detail modal.
 *
 * Distinct from the Drinks and Sides *categories*, which hold food a customer
 * can order on its own. These rows are the "+P65 Iced Tea" chips attached to a
 * main dish, and they live in `addons` rather than `food`.
 */
class AddonSeeder extends Seeder
{
    public const ADDONS = [
        ['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks'],
        ['addon_name' => 'Soft Drinks', 'addon_price' => 80, 'addon_group' => 'Drinks'],
        ['addon_name' => 'Coffee', 'addon_price' => 40, 'addon_group' => 'Drinks'],
        ['addon_name' => 'Buko Juice', 'addon_price' => 75, 'addon_group' => 'Drinks'],
        ['addon_name' => 'Bottled Water', 'addon_price' => 30, 'addon_group' => 'Drinks'],

        ['addon_name' => 'Potato Salad', 'addon_price' => 50, 'addon_group' => 'Sides'],
        ['addon_name' => 'Macaroni Salad', 'addon_price' => 50, 'addon_group' => 'Sides'],
        ['addon_name' => 'Cucumber Salad', 'addon_price' => 35, 'addon_group' => 'Sides'],
        ['addon_name' => 'Leche Flan', 'addon_price' => 80, 'addon_group' => 'Sides'],
        ['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides'],
        ['addon_name' => 'Spaghetti', 'addon_price' => 95, 'addon_group' => 'Sides'],
    ];

    public function run(): void
    {
        foreach (self::ADDONS as $addon) {
            Addon::updateOrCreate(
                ['addon_name' => $addon['addon_name']],
                [
                    'addon_price' => $addon['addon_price'],
                    'addon_group' => $addon['addon_group'],
                    'availability' => true,
                ],
            );
        }
    }
}
