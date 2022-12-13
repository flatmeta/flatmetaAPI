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
        ->where('orders.on_sale',"=","1")
        ->get();
    }

    public static function GetUserTilesByID($id)
    {
        return self::select('orders.*','users.username')
        ->leftJoin('users','orders.user_id','=','users.id')
        ->where('orders.user_id',"=",$id)
        ->where('orders.status',"=","1")
        ->get();
    }

    public static function GetAllOrders()
    {
        return self::select('orders.*','users.username')
        ->leftJoin('users','orders.user_id','=','users.id')
        ->where('orders.status',"=","1")
        ->get();
    }

    public static function GetTilesCount()
    {
        return self::selectraw('sum(no_of_tiles) as total')
        ->get();
    }

    public static function GetLatestPurchasedTiles()
    {
        return self::select('orders.*','users.username')
        ->leftJoin('users','orders.user_id','=','users.id')
        ->where('orders.on_sale',"=","1")
        ->orderBy('orders.created_at',"desc")
        ->get();
    }

}
