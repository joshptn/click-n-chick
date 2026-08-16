<?php

namespace App\Models;

use App\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    /** @use HasFactory<\Database\Factories\FoodFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'thumbnail',
        'food_name',
        'price',
        'size',
        'stock_quantity',
        'is_available',
        'is_best_seller',
        'prep_time',
        'description',
    ];

    /** At or below this, the card warns instead of staying silent. */
    public const LOW_STOCK_THRESHOLD = 5;

    public const STOCK_OUT = 'out_of_stock';

    public const STOCK_LOW = 'low_stock';

    public const STOCK_IN = 'in_stock';

    /** Untracked stock: the kitchen makes it to order. */
    public const STOCK_UNTRACKED = 'untracked';

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock_quantity' => 'integer',
            'prep_time' => 'integer',
            'is_available' => 'boolean',
            'is_best_seller' => 'boolean',
        ];
    }

    protected $appends = [
        'is_orderable',
        'stock_status',
    ];

    /**
     * Whether a customer may put this in a cart.
     *
     * Two independent reasons an item goes grey, and they mean different
     * things: is_available is the Store Agent switching it off (FR-07.2),
     * stock_quantity reaching zero is the kitchen running out. Defined here
     * rather than in the UI so the menu, the cart and checkout cannot disagree
     * about it - the frontend greys out what this says, and CartController
     * refuses what this says.
     */
    public function getIsOrderableAttribute(): bool
    {
        if (! $this->is_available) {
            return false;
        }

        return $this->stock_quantity === null || $this->stock_quantity > 0;
    }

    /** Null stock means untracked, NOT zero - the distinction is load-bearing. */
    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity === null) {
            return self::STOCK_UNTRACKED;
        }

        if ($this->stock_quantity <= 0) {
            return self::STOCK_OUT;
        }

        return $this->stock_quantity <= self::LOW_STOCK_THRESHOLD
            ? self::STOCK_LOW
            : self::STOCK_IN;
    }

    /** Items a customer may actually order. */
    public function scopeOrderable($query)
    {
        return $query->where('is_available', true)
            ->where(function ($q) {
                $q->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0);
            });
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        // Escaped so a user-supplied % or _ matches literally instead of
        // widening the search to everything.
        $escaped = addcslashes(trim($term), '%_\\');

        return $query->where(function ($q) use ($escaped) {
            $q->where('food_name', 'LIKE', "%{$escaped}%")
                ->orWhere('description', 'LIKE', "%{$escaped}%");
        });
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * ERD: CATEGORIES ||--o{ FOOD. Replaced the former belongsToMany over the
     * category_food pivot, which has been retired.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /** Add-ons offered for this item, via the addon_food join table. */
    public function addons()
    {
        return $this->belongsToMany(Addon::class, 'addon_food')
            ->withTimestamps();
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }
}
