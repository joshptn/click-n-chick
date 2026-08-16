<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Poster extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'poster_name',
        'image',
        'is_active',
        'sort_order',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            // Date it stops showing on the homepage.
            'expires_at' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Posters the homepage should show right now.
     *
     * Expiry is inclusive of the day itself - a poster set to expire today is
     * still shown today, and gone tomorrow.
     */
    public function scopeVisible($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }

    /** The admin who uploaded it; null if that account was removed. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
