<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
    ];

    /** Null when a failed login carried an unrecognised identifier. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
