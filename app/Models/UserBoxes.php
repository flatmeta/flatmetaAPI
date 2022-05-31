<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBoxes extends Model
{
    use HasFactory;

    public static function GetUserTiles()
    {
        return self::select('user_boxes.*','orders.custom_details','orders.user_id','users.fullname')
        ->leftJoin('orders','orders.id','=','user_boxes.order_id')
        ->leftJoin('users','users.id','=','orders.user_id')
        ->get();
    }

    public static function GetUserTilesByOrderId($id)
    {
        return self::select('*')
        ->where('order_id',$id)
        ->get();
    }
    
}
