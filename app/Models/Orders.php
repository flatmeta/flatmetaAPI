<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    use HasFactory;

    public static function GetUserTiles($filter = 'all', int $limit = 100)
    {
        $query = self::select('orders.*', 'users.username', 'users.fullname')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.status', '=', '1')
            ->where('orders.on_sale', '=', '1')
            ->limit($limit);

        if ($filter === 'recent') {
            $query->orderBy('orders.created_at', 'desc');
        } elseif ($filter === 'popular') {
            $query->orderBy('orders.no_of_tiles', 'desc')->orderBy('orders.created_at', 'desc');
        } else {
            $query->orderBy('orders.id', 'desc');
        }

        return $query->get();
    }

    public static function GetUserTilesByID($id)
    {
        return self::select('orders.*', 'users.username', 'users.fullname')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.user_id', '=', $id)
            ->where('orders.status', '=', '1')
            ->orderBy('orders.id', 'desc')
            ->get();
    }

    public static function GetAllOrders()
    {
        return self::select('orders.*', 'users.username', 'users.fullname')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.status', '=', '1')
            ->orderBy('orders.id', 'desc')
            ->get();
    }

    public static function GetTilesCount()
    {
        return self::selectRaw('sum(no_of_tiles) as total')->get();
    }

    public static function GetLatestPurchasedTiles(int $limit = 48)
    {
        return self::select('orders.*', 'users.username', 'users.fullname')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.status', '=', '1')
            ->orderBy('orders.created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
