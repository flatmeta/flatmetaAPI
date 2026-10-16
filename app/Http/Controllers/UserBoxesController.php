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

    public function PurchasedTiles(Request $request)
    {
        try {
            $tilesData = UserBoxes::GetUserTiles();

            $data = ['tiles' => []];
            $baseUrl = rtrim(config('app.url', url('/')), '/') . '/';

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
                        'span_bounds' => $meta['span_bounds'] ?? null,
                        'embed_url' => $meta['embed_url'] ?? '',
                        'custom_code' => $meta['custom_code'] ?? '',
                        'previous_image' => $meta['previous_image'] ?? '',
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
}
