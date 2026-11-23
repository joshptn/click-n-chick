<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One size an add-on comes in, at its own price (decided 2026-11-24). The Store
 * Manager sets which sizes and their prices; the Store Agent switches a size
 * off when it runs out.
 */
class AddonSize extends Model
{
    protected $fillable = [
        'addon_id',
        'size',
        'price',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }

    public function label(): string
    {
        return Addon::sizeLabel($this->size) ?? (string) $this->size;
    }
}
