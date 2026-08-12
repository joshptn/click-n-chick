<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItemAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'addon_id',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            // Snapshot of the add-on price at order time.
            'unit_price' => 'decimal:2',
        ];
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }
}
