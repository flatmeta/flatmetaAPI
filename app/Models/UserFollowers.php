<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserFollowers extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'follower_user_id',
        'status',
        'created_at',
        'updated_at',
    ];
}
