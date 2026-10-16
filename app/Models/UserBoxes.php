<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBoxes extends Model
{
    use HasFactory;

    /** Most squares or areas one map request may return. */
    const MAX_TILES = 5000;
    const MAX_AREAS = 2000;

    protected static function booted()
    {
        // lat/lng are strings; keep the indexed numeric copies used by viewport queries in sync.
        static::saving(function (UserBoxes $box) {
            $box->lat_num = is_numeric($box->lat) ? round((float) $box->lat, 7) : null;
            $box->lng_num = is_numeric($box->lng) ? round((float) $box->lng, 7) : null;
        });
    }

    /**
     * Parses `minLat,minLng,maxLat,maxLng`. Returns null when it is malformed.
     * Longitudes are wrapped into [-180, 180]; minLng > maxLng means the box
     * crosses the antimeridian.
     */
    public static function parseBbox($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            return null;
        }
        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);
        if ($minLat > $maxLat) {
            return null;
        }
        $minLat = max(-90.0, $minLat);
        $maxLat = min(90.0, $maxLat);
        if ($maxLng - $minLng >= 360) {
            return [$minLat, -180.0, $maxLat, 180.0];
        }
        $wrap = fn (float $lng) => $lng >= -180 && $lng <= 180 ? $lng : fmod(fmod($lng + 180.0, 360.0) + 360.0, 360.0) - 180.0;
        return [$minLat, $wrap($minLng), $maxLat, $wrap($maxLng)];
    }

    /** Adds a bbox filter on $latCol/$lngCol (numeric columns) to $query. */
    public static function whereInBbox($query, array $bbox, string $latCol, string $lngCol)
    {
        [$minLat, $minLng, $maxLat, $maxLng] = $bbox;
        $query->whereBetween($latCol, [$minLat, $maxLat]);
        if ($minLng <= $maxLng) {
            $query->whereBetween($lngCol, [$minLng, $maxLng]);
        } else {
            $query->where(function ($q) use ($lngCol, $minLng, $maxLng) {
                $q->where($lngCol, '>=', $minLng)->orWhere($lngCol, '<=', $maxLng);
            });
        }
        return $query;
    }

    /**
     * Squares of active orders, optionally only those inside $bbox. Inside a bbox
     * the squares nearest its centre come first, so a capped response still
     * covers the middle of the view.
     */
    public static function GetUserTiles(?array $bbox = null, int $limit = self::MAX_TILES)
    {
        $query = self::select(
            'user_boxes.*',
            'orders.custom_details',
            'orders.user_id',
            'orders.on_sale',
            'orders.sale_price',
            'orders.amount',
            'orders.no_of_tiles',
            'orders.name as area_name',
            'orders.log as order_log',
            'users.fullname',
            'users.username'
        )
            ->join('orders', 'orders.id', '=', 'user_boxes.order_id')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', '=', '1');

        if ($bbox) {
            self::whereInBbox($query, $bbox, 'user_boxes.lat_num', 'user_boxes.lng_num');
            $cLat = ($bbox[0] + $bbox[2]) / 2;
            $cLng = $bbox[1] <= $bbox[3] ? ($bbox[1] + $bbox[3]) / 2 : null;
            if ($cLng !== null) {
                $query->orderByRaw('ABS(user_boxes.lat_num - ?) + ABS(user_boxes.lng_num - ?)', [$cLat, $cLng]);
            }
        } else {
            $query->orderBy('user_boxes.id', 'desc');
        }

        return $query->limit($limit)->get();
    }

    /**
     * One row per active area (centre, size, one image) for the zoomed-out map,
     * biggest areas first.
     */
    public static function GetAreaSummaries(?array $bbox = null, int $limit = self::MAX_AREAS)
    {
        $query = Orders::select(
            'orders.id',
            'orders.user_id',
            'orders.name',
            'orders.log',
            'orders.on_sale',
            'orders.sale_price',
            'orders.no_of_tiles',
            'orders.center_lat',
            'orders.center_lng',
            'users.username',
            'users.fullname'
        )
            ->selectSub(
                self::select('image')
                    ->whereColumn('user_boxes.order_id', 'orders.id')
                    ->whereNotNull('image')
                    ->where('image', '!=', '')
                    ->limit(1),
                'image'
            )
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', '=', '1')
            ->whereNotNull('orders.center_lat');

        if ($bbox) {
            self::whereInBbox($query, $bbox, 'orders.center_lat', 'orders.center_lng');
        }

        return $query->orderBy('orders.no_of_tiles', 'desc')->orderBy('orders.id', 'desc')->limit($limit)->get();
    }

    public static function GetUserTilesByOrderId($id)
    {
        return self::select('*')
            ->where('order_id', $id)
            ->get();
    }
}
