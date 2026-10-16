<?php

namespace App\Http\Controllers;

use App\Models\UserBoxes;
use Illuminate\Http\Request;

class UserBoxesController extends Controller
{
    protected function formatTileImage(?string $image, string $baseUrl): string
    {
        if (empty($image)) {
            return '';
        }
        if (str_starts_with($image, 'data:') || str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return $baseUrl . 'assets/uploads/defaultimages/' . ltrim($image, '/');
    }

    /**
     * Owned squares for the map.
     * - `bbox=minLat,minLng,maxLat,maxLng`: only squares whose north-west corner is inside it
     *   (nearest the centre first). Without it, the newest squares.
     * - `limit`: at most UserBoxes::MAX_TILES (the default). `data.truncated` says when more matched.
     * - `summary=1`: one row per area (centre, tile count, image, title, on_sale) for the
     *   zoomed-out levels, filtered on the area centre and capped at UserBoxes::MAX_AREAS.
     */
    public function PurchasedTiles(Request $request)
    {
        try {
            $bbox = null;
            if ($request->filled('bbox')) {
                $bbox = UserBoxes::parseBbox((string) $request->query('bbox'));
                if (!$bbox) {
                    return response()->json(['status' => false, 'message' => 'bbox must be minLat,minLng,maxLat,maxLng']);
                }
            }
            $baseUrl = rtrim(config('app.url', url('/')), '/') . '/';

            if ($request->boolean('summary')) {
                return $this->AreaSummaries($bbox, $baseUrl, (int) $request->query('limit', UserBoxes::MAX_AREAS));
            }

            $limit = max(1, min(UserBoxes::MAX_TILES, (int) $request->query('limit', UserBoxes::MAX_TILES)));
            // One extra row tells us whether the cap cut the answer short.
            $tilesData = UserBoxes::GetUserTiles($bbox, $limit + 1);
            $truncated = $tilesData->count() > $limit;
            if ($truncated) {
                $tilesData = $tilesData->take($limit);
            }

            $data = ['tiles' => [], 'bbox' => $bbox, 'limit' => $limit, 'truncated' => $truncated];

            if (!empty($tilesData) && $tilesData->isNotEmpty()) {
                foreach ($tilesData as $tiles) {
                    $meta = [];
                    if (!empty($tiles->order_log)) {
                        $decoded = json_decode($tiles->order_log, true);
                        if (is_array($decoded)) {
                            $meta = $decoded;
                        }
                    }

                    $data['tiles'][] = [
                        'id' => $tiles->id,
                        'user_id' => $tiles->user_id,
                        'username' => $tiles->username ?? 'citizen',
                        'fullname' => $tiles->fullname ?? 'Metaverse Citizen',
                        'order_id' => $tiles->order_id,
                        'area_id' => $tiles->order_id,
                        'area_title' => $meta['area_title'] ?? ($tiles->area_name ?: ''),
                        'slug' => $meta['slug'] ?? '',
                        'three_words' => $meta['three_words'] ?? '',
                        // A square's own span (set when an image is stretched over a selection) wins over the area's.
                        'span_bounds' => !empty($tiles->span_bounds) ? json_decode($tiles->span_bounds, true) : ($meta['span_bounds'] ?? null),
                        'embed_url' => $meta['embed_url'] ?? '',
                        'custom_code' => $meta['custom_code'] ?? '',
                        'previous_image' => $this->formatTileImage($tiles->previous_image ?? null, $baseUrl),
                        'promoted' => (bool) ($meta['promoted'] ?? false),
                        'views' => (int) ($meta['views'] ?? 0),
                        'lat' => (float) $tiles->lat,
                        'lng' => (float) $tiles->lng,
                        'image' => $this->formatTileImage($tiles->image, $baseUrl),
                        'custom_details' => !empty($tiles->custom_details) ? $tiles->custom_details : '',
                        'on_sale' => ($tiles->on_sale ?? '0') == '1',
                        'sale_status' => $tiles->on_sale ?? '0',
                        'sale_price' => (float) ($tiles->sale_price ?? 0),
                        'amount' => (float) ($tiles->amount ?? 0.10),
                        'no_of_tiles' => (int) ($tiles->no_of_tiles ?? 1),
                    ];
                }
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /** `PurchasedTiles?summary=1`: one row per area, for the City and World zoom levels. */
    protected function AreaSummaries(?array $bbox, string $baseUrl, int $limit)
    {
        $limit = max(1, min(UserBoxes::MAX_AREAS, $limit));
        $rows = UserBoxes::GetAreaSummaries($bbox, $limit + 1);
        $truncated = $rows->count() > $limit;

        $areas = [];
        foreach ($rows->take($limit) as $row) {
            $meta = json_decode((string) $row->log, true);
            $meta = is_array($meta) ? $meta : [];
            $areas[] = [
                'order_id' => $row->id,
                'user_id' => $row->user_id,
                'username' => $row->username ?? 'citizen',
                'fullname' => $row->fullname ?? 'Metaverse Citizen',
                'area_title' => $meta['area_title'] ?? ($row->name ?: ''),
                'slug' => $meta['slug'] ?? '',
                'lat' => (float) $row->center_lat,
                'lng' => (float) $row->center_lng,
                'no_of_tiles' => (int) ($row->no_of_tiles ?: 1),
                'image' => $this->formatTileImage($row->image ?? null, $baseUrl),
                'on_sale' => ($row->on_sale ?? '0') == '1' && (float) $row->sale_price > 0,
                'sale_price' => (float) ($row->sale_price ?? 0),
                'promoted' => (bool) ($meta['promoted'] ?? false),
            ];
        }

        return response()->json(['status' => true, 'data' => [
            'areas' => $areas,
            'bbox' => $bbox,
            'limit' => $limit,
            'truncated' => $truncated,
        ]]);
    }
}
