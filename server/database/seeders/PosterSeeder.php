<?php

namespace Database\Seeders;

use App\Models\Poster;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Placeholder for the banner strip above the category chips.
 *
 * A poster is a homepage image and nothing more: it carries no discount logic
 * and has no relationship to orders. The PWD/Senior artwork in the prototype
 * is a promotional graphic, so it seeds as one image here - the 20% discount
 * itself belongs to the discounts module, not to this row.
 */
class PosterSeeder extends Seeder
{
    private const IMAGE_FOLDER = 'posters';

    public const POSTERS = [
        [
            'poster_name' => 'PWD & Senior Citizens Privilege',
            'image' => 'https://images.unsplash.com/photo-1552566626-52f8b828add9?q=80&w=1600&auto=format&fit=crop',
            'sort_order' => 1,
        ],
        [
            'poster_name' => 'Order Today, Enjoy Later',
            'image' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?q=80&w=1600&auto=format&fit=crop',
            'sort_order' => 2,
        ],
    ];

    public function run(): void
    {
        $uploader = User::where('role', User::ROLE_SUPER_ADMIN)->value('id');

        foreach (self::POSTERS as $poster) {
            Poster::updateOrCreate(
                ['poster_name' => $poster['poster_name']],
                [
                    'created_by' => $uploader,
                    'image' => SeedMedia::publish($poster['image'], self::IMAGE_FOLDER),
                    'is_active' => true,
                    'sort_order' => $poster['sort_order'],
                    'expires_at' => null,
                ],
            );
        }
    }
}
