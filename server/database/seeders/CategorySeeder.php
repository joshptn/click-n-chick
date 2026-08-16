<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The category chips across the top of the customer home page.
 *
 * 'All' is deliberately absent: it is a UI-only pseudo-filter meaning "no
 * category constraint", not a row. Giving it a row would let a food be filed
 * under it.
 */
class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        ['name' => 'Ala Carte', 'description' => 'Single servings, ordered on their own'],
        ['name' => 'Breakfast', 'description' => 'Morning plates served with rice or egg'],
        ['name' => 'Combos', 'description' => 'Rice meals that pair a main with a side'],
        ['name' => 'Group', 'description' => 'Platters good for four to six people'],
        ['name' => 'Party', 'description' => 'Large trays for events and celebrations'],
        ['name' => 'Drinks', 'description' => 'Cold and hot drinks'],
        ['name' => 'Sides', 'description' => 'Small plates to round out a meal'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            Category::updateOrCreate(
                ['name' => $category['name']],
                ['description' => $category['description']],
            );
        }
    }
}
