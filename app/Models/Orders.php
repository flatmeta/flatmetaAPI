<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    use HasFactory;

    public static function GetUserTiles()
    {
        return self::select('orders.*','users.username')
        ->leftJoin('users','orders.user_id','=','users.id')
        ->get();
    }

    public static function GetUserTilesByID($id)
    {
        return self::select('orders.*','users.username')
        ->leftJoin('users','orders.user_id','=','users.id')
        ->where('orders.user_id',"=",$id)
        ->get();
    }
}
