<?php

namespace App\Models;

use App\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Food extends Model
{
    /** @use HasFactory<\Database\Factories\FoodFactory> */
    use HasFactory;

    /**
     * A deleted dish leaves the catalogue, the menu and carts, but its row
     * stays so past orders keep their lines (see OrderItem::food).
     */
    use SoftDeletes;

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

        return $this->stock_quantity === null || $this->available_quantity > 0;
    }

    /**
     * Units nobody has claimed: the count less what is held for customers
     * still paying (StockHold::HOLDING). Sold units have already left the count.
     * Null for an untracked item, which has no count to run out of.
     *
     * Reads `held_quantity` when the query loaded it (withHeldQuantity), and
     * asks for it otherwise.
     */
    public function getAvailableQuantityAttribute(): ?int
    {
        if ($this->stock_quantity === null) {
            return null;
        }

        return max(0, $this->stock_quantity - $this->held_quantity_value);
    }

    public function getHeldQuantityValueAttribute(): int
    {
        if (array_key_exists('held_quantity', $this->attributes)) {
            return (int) $this->attributes['held_quantity'];
        }

        if (! $this->exists) {
            return 0;
        }

        return (int) $this->stockHolds()->active()->sum('quantity');
    }

    /** Null stock means untracked, NOT zero - the distinction is load-bearing. */
    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity === null) {
            return self::STOCK_UNTRACKED;
        }

        $left = $this->available_quantity;

        if ($left <= 0) {
            return self::STOCK_OUT;
        }

        return $left <= self::LOW_STOCK_THRESHOLD
            ? self::STOCK_LOW
            : self::STOCK_IN;
    }

    /** Loads what live holds set aside, so a list reads it in one query. */
    public function scopeWithHeldQuantity($query)
    {
        return $query->withSum(['stockHolds as held_quantity' => fn ($holds) => $holds->active()], 'quantity');
    }

    /** Items a customer may actually order. */
    public function scopeOrderable($query)
    {
        return $query->where('is_available', true)
            ->where(function ($q) {
                $q->whereNull('stock_quantity')
                    ->orWhereRaw(
                        'stock_quantity > (select coalesce(sum(stock_holds.quantity), 0) from stock_holds '
                        .'where stock_holds.food_id = food.id and stock_holds.status = ? '
                        .'and (stock_holds.expires_at is null or stock_holds.expires_at > ?))',
                        [StockHold::HOLDING, now()]
                    );
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
