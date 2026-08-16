<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Food;
use Illuminate\Database\Seeder;

/**
 * The menu the customer home page renders.
 *
 * This replaces the hardcoded MENU_ITEMS the marketing page used to import, so
 * both the landing page and the customer home now read the same catalogue from
 * /api/foods.
 *
 * `thumbnail` starts as a remote source URL. SeedMedia moves it to Cloudinary
 * whenever credentials are present, so re-running this after adding
 * CLOUDINARY_URL is all that is needed to host the images properly.
 *
 * Stock levels are chosen to exercise every visual state on the card:
 * a healthy count, a low-stock warning, and a sold-out item that greys out.
 */
class FoodSeeder extends Seeder
{
    private const IMAGE_FOLDER = 'food';

    /**
     * stock_quantity null  -> untracked, always orderable
     * stock_quantity 1..5  -> "Only N stocks left!"
     * stock_quantity 0     -> "No more stock left", card greys out
     */
    public const FOODS = [
        // --- Combos -----------------------------------------------------
        [
            'food_name' => 'Chicken Inasal',
            'category' => 'Combos',
            'price' => 160,
            'stock_quantity' => 10,
            'is_best_seller' => true,
            'prep_time' => 15,
            'description' => 'Charcoal-grilled chicken marinated in calamansi, lemongrass and annatto, served with garlic rice and a side of soy-vinegar dip.',
            'image' => 'https://images.unsplash.com/photo-1432139555190-58524dae6a55?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Coffee', 'Buko Juice', 'Bottled Water', 'Potato Salad', 'Macaroni Salad', 'Fries'],
        ],
        [
            'food_name' => '2 pcs. Spicy & Crispy Fried Chicken',
            'category' => 'Combos',
            'price' => 210,
            'stock_quantity' => 3,
            'is_best_seller' => true,
            'prep_time' => 18,
            'description' => 'Two pieces of hand-breaded chicken tossed in our chili blend, fried to a deep crunch and served with rice and gravy.',
            'image' => 'https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Coffee', 'Buko Juice', 'Bottled Water', 'Potato Salad', 'Cucumber Salad', 'Fries', 'Spaghetti'],
        ],
        [
            'food_name' => '2 pcs. House Blend Fried Chicken',
            'category' => 'Combos',
            'price' => 210,
            'stock_quantity' => 3,
            'prep_time' => 18,
            'description' => 'The original recipe. Two pieces marinated overnight in our house blend of eleven herbs, served with rice and gravy.',
            'image' => 'https://images.unsplash.com/photo-1513639776629-7b61b0ac49cb?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Coffee', 'Bottled Water', 'Macaroni Salad', 'Leche Flan', 'Fries'],
        ],
        [
            'food_name' => 'Chicken Fingers',
            'category' => 'Combos',
            'price' => 180,
            // Sold out on purpose: this is the card that greys out.
            'stock_quantity' => 0,
            'is_best_seller' => true,
            'prep_time' => 12,
            'description' => 'Strips of chicken breast in a seasoned crumb, golden fried and served with fries and a choice of dip.',
            'image' => 'https://images.unsplash.com/photo-1562967914-608f82629710?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Bottled Water', 'Fries', 'Potato Salad'],
        ],
        [
            'food_name' => 'Chicken Spaghetti',
            'category' => 'Combos',
            'price' => 180,
            'stock_quantity' => 27,
            'is_best_seller' => true,
            'prep_time' => 15,
            'description' => 'A Filipino-style spaghetti loaded with tender shredded chicken, sweet tomato sauce, and a generous topping of grated cheese. This comforting classic is slow-cooked to bring out the rich, savory-sweet flavors that make it a crowd favorite for all ages. Served hot with garlic bread on the side.',
            'image' => 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Coffee', 'Buko Juice', 'Bottled Water', 'Potato Salad', 'Macaroni Salad', 'Cucumber Salad', 'Leche Flan', 'Fries', 'Spaghetti'],
        ],

        // --- Ala Carte --------------------------------------------------
        [
            'food_name' => 'Spaghetti Meatballs',
            'category' => 'Ala Carte',
            'price' => 150,
            'stock_quantity' => 10,
            'prep_time' => 14,
            'description' => 'Spaghetti in a slow-simmered tomato sauce with beef meatballs and a dusting of parmesan.',
            'image' => 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Bottled Water', 'Fries'],
        ],
        [
            'food_name' => 'Meatballs',
            'category' => 'Ala Carte',
            'price' => 115,
            'stock_quantity' => 10,
            'is_best_seller' => true,
            'prep_time' => 12,
            'description' => 'Six beef meatballs in a rich tomato sauce, served with steamed rice.',
            'image' => 'https://images.unsplash.com/photo-1529042410759-befb1204b468?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Bottled Water', 'Macaroni Salad'],
        ],
        [
            'food_name' => 'Herb Chicken',
            'category' => 'Ala Carte',
            'price' => 360,
            'stock_quantity' => 8,
            'prep_time' => 25,
            'description' => 'A whole roasted chicken rubbed with rosemary, thyme and garlic, slow-roasted until the skin crisps.',
            'image' => 'https://images.unsplash.com/photo-1598514982205-f36b96d1e8d4?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Buko Juice', 'Bottled Water', 'Potato Salad', 'Fries'],
        ],
        [
            'food_name' => 'Buffalo Wings',
            'category' => 'Ala Carte',
            'price' => 250,
            'stock_quantity' => 12,
            'prep_time' => 16,
            'description' => 'Eight wings tossed in a tangy buffalo glaze, with a blue cheese dip on the side.',
            'image' => 'https://images.unsplash.com/photo-1569691899455-88464f6d3ab1?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Iced Tea', 'Soft Drinks', 'Bottled Water', 'Fries', 'Potato Salad'],
        ],

        // --- Breakfast --------------------------------------------------
        [
            'food_name' => 'Chicken Tocino Silog',
            'category' => 'Breakfast',
            'price' => 135,
            'stock_quantity' => 15,
            'prep_time' => 10,
            'description' => 'Sweet-cured chicken tocino with garlic fried rice and a sunny-side-up egg.',
            'image' => 'https://images.unsplash.com/photo-1608039755401-742074f0548d?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Coffee', 'Iced Tea', 'Bottled Water'],
        ],
        [
            'food_name' => 'Chicken Longganisa Silog',
            'category' => 'Breakfast',
            'price' => 140,
            'stock_quantity' => 4,
            'prep_time' => 10,
            'description' => 'House-made chicken longganisa with garlic rice, egg, and a spiced vinegar dip.',
            'image' => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Coffee', 'Iced Tea', 'Bottled Water'],
        ],

        // --- Group ------------------------------------------------------
        [
            'food_name' => 'Family Bucket - 8 pcs',
            'category' => 'Group',
            'price' => 899,
            'stock_quantity' => 6,
            'is_best_seller' => true,
            'prep_time' => 30,
            'description' => 'Eight pieces of our house blend fried chicken with four rice and two large sides. Good for four to six.',
            'image' => 'https://images.unsplash.com/photo-1513639776629-7b61b0ac49cb?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Soft Drinks', 'Iced Tea', 'Buko Juice', 'Potato Salad', 'Macaroni Salad', 'Fries', 'Spaghetti'],
        ],
        [
            'food_name' => 'Whole Roast Chicken Platter',
            'category' => 'Group',
            'price' => 550,
            'stock_quantity' => 5,
            'prep_time' => 35,
            'description' => 'A whole roast chicken quartered over a bed of java rice, with gravy and atchara.',
            'image' => 'https://images.unsplash.com/photo-1598514982205-f36b96d1e8d4?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Soft Drinks', 'Iced Tea', 'Buko Juice', 'Potato Salad', 'Fries'],
        ],

        // --- Party ------------------------------------------------------
        [
            'food_name' => 'Party Tray - Fried Chicken (16 pcs)',
            'category' => 'Party',
            'price' => 1650,
            'stock_quantity' => 3,
            'prep_time' => 45,
            'description' => 'Sixteen pieces of house blend fried chicken in a foil party tray. Good for eight to ten.',
            'image' => 'https://images.unsplash.com/photo-1626645738196-c2a7c87a8f58?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Soft Drinks', 'Iced Tea', 'Spaghetti', 'Macaroni Salad'],
        ],
        [
            'food_name' => 'Party Tray - Spaghetti',
            'category' => 'Party',
            'price' => 950,
            'stock_quantity' => 4,
            'prep_time' => 40,
            'description' => 'A full tray of Filipino-style sweet spaghetti with chicken and cheese. Good for ten to twelve.',
            'image' => 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?q=80&w=1200&auto=format&fit=crop',
            'addons' => ['Soft Drinks', 'Iced Tea', 'Buko Juice'],
        ],

        // --- Drinks (orderable on their own) ----------------------------
        [
            'food_name' => 'Iced Tea Pitcher',
            'category' => 'Drinks',
            'price' => 120,
            'stock_quantity' => null,
            'prep_time' => 5,
            'description' => 'A one-litre pitcher of house-brewed lemon iced tea.',
            'image' => 'https://images.unsplash.com/photo-1499638673689-79a0b5115d87?q=80&w=1200&auto=format&fit=crop',
            'addons' => [],
        ],
        [
            'food_name' => 'Buko Juice',
            'category' => 'Drinks',
            'price' => 75,
            'stock_quantity' => 20,
            'prep_time' => 5,
            'description' => 'Fresh young coconut water served cold with strips of coconut meat.',
            'image' => 'https://images.unsplash.com/photo-1536759808958-46d1bbb0e2e6?q=80&w=1200&auto=format&fit=crop',
            'addons' => [],
        ],

        // --- Sides ------------------------------------------------------
        [
            'food_name' => 'Regular Fries',
            'category' => 'Sides',
            'price' => 65,
            'stock_quantity' => null,
            'prep_time' => 8,
            'description' => 'Thick-cut potato fries with our house seasoning.',
            'image' => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?q=80&w=1200&auto=format&fit=crop',
            'addons' => [],
        ],
        [
            'food_name' => 'Macaroni Salad',
            'category' => 'Sides',
            'price' => 50,
            'stock_quantity' => 2,
            'prep_time' => 5,
            'description' => 'Chilled elbow macaroni with pineapple, carrot and a creamy dressing.',
            'image' => 'https://images.unsplash.com/photo-1529059997568-3d847b1154f0?q=80&w=1200&auto=format&fit=crop',
            'addons' => [],
        ],
        [
            'food_name' => 'Leche Flan',
            'category' => 'Sides',
            'price' => 80,
            'stock_quantity' => 0,
            'prep_time' => 5,
            'description' => 'A steamed custard with a burnt sugar caramel, served chilled.',
            'image' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?q=80&w=1200&auto=format&fit=crop',
            'addons' => [],
        ],
    ];

    public function run(): void
    {
        $categories = Category::pluck('id', 'name');
        $addons = Addon::pluck('id', 'addon_name');

        foreach (self::FOODS as $item) {
            $food = Food::updateOrCreate(
                ['food_name' => $item['food_name']],
                [
                    'category_id' => $categories[$item['category']] ?? null,
                    'thumbnail' => SeedMedia::publish($item['image'], self::IMAGE_FOLDER),
                    'price' => $item['price'],
                    'stock_quantity' => $item['stock_quantity'],
                    'is_available' => true,
                    'is_best_seller' => $item['is_best_seller'] ?? false,
                    'prep_time' => $item['prep_time'],
                    'description' => $item['description'],
                ],
            );

            $addonIds = collect($item['addons'] ?? [])
                ->map(fn (string $name) => $addons[$name] ?? null)
                ->filter()
                ->all();

            $food->addons()->sync($addonIds);
        }
    }
}
