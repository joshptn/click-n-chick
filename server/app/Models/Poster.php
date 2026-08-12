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
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            // Date it stops showing on the homepage.
            'expires_at' => 'date',
        ];
    }

    /** The admin who uploaded it; null if that account was removed. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
