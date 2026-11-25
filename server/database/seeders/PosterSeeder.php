<?php

namespace Database\Seeders;

use App\Models\Poster;
use Illuminate\Database\Seeder;

/**
 * Placeholders for the homepage banner, already cropped to its 3:1 shape.
 *
 * A poster is a homepage image and nothing more: it carries no discount logic
 * and has no relationship to orders.
 */
class PosterSeeder extends Seeder
{
    private const IMAGE_FOLDER = 'posters';

    public const POSTERS = [
        'https://images.unsplash.com/photo-1552566626-52f8b828add9?q=80&w=1800&h=600&fit=crop&auto=format',
        'https://images.unsplash.com/photo-1504674900247-0877df9cc836?q=80&w=1800&h=600&fit=crop&auto=format',
    ];

    public function run(): void
    {
        foreach (self::POSTERS as $source) {
            $image = SeedMedia::publish($source, self::IMAGE_FOLDER);

            Poster::firstOrCreate(['image' => $image], ['is_active' => true, 'expires_at' => null]);
        }
    }
}
