<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An extra a customer may pick with a dish (BR-17): a drink, a side.
 *
 * It comes in no size, at `addon_price`, or in some of Small, Medium and Large,
 * each at its own price (`sizes`; decided 2026-11-24). For a sized add-on
 * `addon_price` holds the lowest size price, so a reader that knows nothing of
 * sizes still shows a fair "from" figure.
 *
 * `availability` is one switch shared by the Store Manager (Archive) and the
 * Store Agent (Availability), as a dish's is_available is. A deleted add-on is
 * soft-deleted, so past orders keep their add-on lines.
 */
class Addon extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Every size an add-on can come in, smallest first. */
    public const SIZES = [
        'small' => 'Small',
        'medium' => 'Medium',
        'large' => 'Large',
    ];

    protected $fillable = [
        'addon_name',
        'addon_price',
        'availability',
        'addon_group',
    ];

    protected function casts(): array
    {
        return [
            'addon_price' => 'decimal:2',
            'availability' => 'boolean',
        ];
    }

    /** Which food items offer this add-on. */
    public function foods()
    {
        return $this->belongsToMany(Food::class, 'addon_food');
    }

    /** Smallest first. */
    public function sizes()
    {
        return $this->hasMany(AddonSize::class)
            ->orderByRaw("CASE size WHEN 'small' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END");
    }

    public function cartItems()
    {
        return $this->belongsToMany(CartItem::class, 'cart_item_addons');
    }

    public function orderItems()
    {
        return $this->belongsToMany(OrderItem::class, 'order_item_addons')
            ->withPivot('unit_price', 'size');
    }

    public function isSized(): bool
    {
        return $this->sizes->isNotEmpty();
    }

    public function sizeRow(?string $size): ?AddonSize
    {
        return $size === null ? null : $this->sizes->firstWhere('size', $size);
    }

    /**
     * What it costs in this size, or null when it does not come in it: a sized
     * add-on has no price without a size, and an unsized one none with one.
     */
    public function priceFor(?string $size): ?float
    {
        if (! $this->isSized()) {
            return $size === null ? (float) $this->addon_price : null;
        }

        $row = $this->sizeRow($size);

        return $row === null ? null : (float) $row->price;
    }

    /** Whether a customer may pick it, in this size, today. */
    public function isAvailableIn(?string $size): bool
    {
        if (! $this->availability || $this->trashed()) {
            return false;
        }

        if (! $this->isSized()) {
            return $size === null;
        }

        return (bool) $this->sizeRow($size)?->is_available;
    }

    /** The smallest size still available, for a client that sends no size. */
    public function firstAvailableSize(): ?string
    {
        return $this->sizes->firstWhere('is_available', true)?->size;
    }

    public static function sizeLabel(?string $size): ?string
    {
        return $size === null ? null : (self::SIZES[$size] ?? ucfirst($size));
    }

    /** "Iced Tea (Large)", or just "Iced Tea" without a size. */
    public static function nameWith(string $name, ?string $size): string
    {
        $label = self::sizeLabel($size);

        return $label === null ? $name : $name.' ('.$label.')';
    }

    /**
     * The add-on as picked on a cart or order line: its name and, when it was
     * picked in a size, the size. Reads the join row, so it is for add-ons
     * loaded through a line.
     */
    public function getChosenNameAttribute(): string
    {
        return self::nameWith((string) $this->addon_name, $this->pickedSize());
    }

    public function pickedSize(): ?string
    {
        return $this->relationLoaded('pivot') ? ($this->pivot->size ?? null) : null;
    }
}
