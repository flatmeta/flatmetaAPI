<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\OrderHistory;
use App\Models\Orders;
use App\Models\User;
use App\Models\UserBoxes;
use App\Services\Billing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{
    /**
     * Client Brief (25/4/22 & 06/01/23):
     * - Every citizen gets 100 FREE squares
     * - Beyond 100 squares: $0.10 USD / square / month
     * - Marketplace resale commission: 10% platform fee
     */
    const FREE_TILE_QUOTA = 100;
    const PRICE_PER_TILE_MONTH = 0.10;
    const PLATFORM_COMMISSION_RATE = 0.10;

    protected function baseUrl(): string
    {
        $base = config('app.url') ?: env('APP_URL', url('/'));
        return rtrim($base, '/') . '/';
    }

    protected function formatTileImage(?string $image): string
    {
        $baseUrl = $this->baseUrl();
        if (empty($image)) {
            return $baseUrl . 'assets/uploads/defaultimages/placeholder.png';
        }
        if (str_starts_with($image, 'data:') || str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return $baseUrl . 'assets/uploads/defaultimages/' . ltrim($image, '/');
    }

    protected function decodeOrderMeta($order): array
    {
        if (empty($order->log)) {
            return [];
        }
        $decoded = json_decode($order->log, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Owner-editable land details, validated. `promoted` and `views` are not
     * accepted from owners: promotion is set by admins and views by visits.
     * Throws \InvalidArgumentException with a message for the app when a value is rejected.
     */
    protected function applyOrderMeta($order, Request $request): void
    {
        $meta = $this->decodeOrderMeta($order);

        if ($request->has('area_title')) {
            $title = mb_substr(trim(strip_tags((string) $request->input('area_title'))), 0, 120);
            $meta['area_title'] = $title;
            if ($title !== '') {
                $order->name = mb_substr($title, 0, 190);
            }
        }
        if ($request->has('slug')) {
            $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $request->input('slug')))), '-');
            $slug = substr($slug, 0, 60);
            if ($slug !== '' && $this->slugTaken($slug, $order->id)) {
                throw new \InvalidArgumentException("flatmeta.io/u/{$slug} is already taken.");
            }
            $meta['slug'] = $slug;
        }
        if ($request->has('three_words')) {
            $words = strtolower(trim((string) $request->input('three_words')));
            if ($words !== '' && !preg_match('/^[a-z0-9]{1,20}\.[a-z0-9]{1,20}\.[a-z0-9]{1,20}$/', $words)) {
                throw new \InvalidArgumentException('A 3-word address looks like word.word.word');
            }
            $meta['three_words'] = $words;
        }
        if ($request->has('embed_url')) {
            $url = trim((string) $request->input('embed_url'));
            if ($url !== '' && (!preg_match('#^https://\S+$#i', $url) || strlen($url) > 2048)) {
                throw new \InvalidArgumentException('Embed links must start with https://');
            }
            $meta['embed_url'] = $url;
        }
        if ($request->has('custom_code')) {
            $meta['custom_code'] = mb_substr((string) $request->input('custom_code'), 0, 20000);
        }
        if ($request->has('span_bounds')) {
            $span = $request->input('span_bounds');
            $valid = is_array($span) && count(array_filter(['minGx', 'minGy', 'maxGx', 'maxGy'], fn ($k) => isset($span[$k]) && is_numeric($span[$k]))) === 4;
            $meta['span_bounds'] = $valid ? array_map('intval', array_intersect_key($span, array_flip(['minGx', 'minGy', 'maxGx', 'maxGy']))) : null;
        }

        if (!empty($meta)) {
            $order->log = json_encode($meta);
        }
    }

    protected function slugTaken(string $slug, $exceptOrderId = null): bool
    {
        return Orders::whereIn('status', ['0', '1'])
            ->when($exceptOrderId, fn ($q) => $q->where('id', '!=', $exceptOrderId))
            ->whereRaw("JSON_VALID(log) AND JSON_UNQUOTE(JSON_EXTRACT(log, '$.slug')) = ?", [$slug])
            ->exists();
    }

    protected function cancelExistingSubscription(?string $subscriptionId, string $reason): bool
    {
        return app(Billing::class)->safeCancel($subscriptionId, $reason);
    }

    /** Squares of many orders in one query, keyed by order id (avoids one query per order). */
    protected function boxesByOrder($orders)
    {
        $ids = collect($orders)->pluck('id')->all();
        return empty($ids) ? collect() : UserBoxes::whereIn('order_id', $ids)->get()->groupBy('order_id');
    }

    protected function formatOrderRecord($order, $boxes = null): array
    {
        $boxes = $boxes ?? UserBoxes::where('order_id', $order->id)->get();
        $firstBox = $boxes->first();
        $lat = $firstBox ? (float) $firstBox->lat : 51.50972;
        $lng = $firstBox ? (float) $firstBox->lng : -0.104317;
        $tileCount = $boxes->count() > 0 ? $boxes->count() : (int) ($order->no_of_tiles ?: 1);
        $meta = $this->decodeOrderMeta($order);

        $tilesArr = [];
        foreach ($boxes as $b) {
            $tilesArr[] = [
                'id' => $b->id,
                'order_id' => $order->id,
                'lat' => (float) $b->lat,
                'lng' => (float) $b->lng,
                'image' => $this->formatTileImage($b->image ?? null),
                'previous_image' => !empty($b->previous_image) ? $this->formatTileImage($b->previous_image) : '',
                'span_bounds' => !empty($b->span_bounds) ? json_decode($b->span_bounds, true) : null,
            ];
        }

        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'area_id' => $order->id,
            'area_title' => $meta['area_title'] ?? ($order->name ?: ('Land #' . $order->id)),
            'slug' => $meta['slug'] ?? '',
            'three_words' => $meta['three_words'] ?? '',
            'span_bounds' => $meta['span_bounds'] ?? null,
            'embed_url' => $meta['embed_url'] ?? '',
            'custom_code' => $meta['custom_code'] ?? '',
            'previous_image' => $meta['previous_image'] ?? '',
            'promoted' => (bool) ($meta['promoted'] ?? false),
            'views' => (int) ($meta['views'] ?? 0),
            'image' => $this->formatTileImage($firstBox ? $firstBox->image : null),
            'user_id' => $order->user_id,
            'username' => $order->username ?? 'citizen',
            'fullname' => $order->fullname ?? 'Metaverse Citizen',
            'no_of_tiles' => $tileCount,
            'lat' => $lat,
            'lng' => $lng,
            'tile_lat' => $lat,
            'tile_lng' => $lng,
            'center_lat' => $order->center_lat !== null ? (float) $order->center_lat : $lat,
            'center_lng' => $order->center_lng !== null ? (float) $order->center_lng : $lng,
            'custom_details' => $order->custom_details ?? '',
            'amount' => (float) ($order->amount ?? self::PRICE_PER_TILE_MONTH),
            'sale_price' => (float) ($order->sale_price ?? 0),
            'on_sale' => ($order->on_sale ?? '0') == '1',
            'sale_status' => $order->on_sale ?? '0',
            'status' => (string) $order->status,
            'subscription_status' => $order->subscription_status,
            'next_due_date' => $order->next_due_date ? (string) $order->next_due_date : null,
            'paid_until' => $order->next_expiry ? (string) $order->next_expiry : null,
            'billing_required' => !empty($meta['billing_required']),
            'created_at' => $order->created_at ? (string) $order->created_at : now()->toISOString(),
            'tiles' => $tilesArr,
        ];
    }

    /**
     * Returns how many active tiles a user currently owns.
     */
    protected function getOwnedTileCount(int $userId): int
    {
        return (int) Orders::where('user_id', $userId)
            ->where('status', '1')
            ->sum('no_of_tiles');
    }

    /**
     * Returns the tiles (from $tiles) that are already taken by another order: an active one, or
     * (when $includePending) a pending checkout started in the last 24 hours.
     * Must be called inside a transaction; matching rows are locked.
     */
    protected function findTakenTiles(array $tiles, ?int $exceptOrderId = null, bool $includePending = true): array
    {
        $taken = [];
        foreach ($tiles as $tile) {
            $query = UserBoxes::join('orders', 'orders.id', '=', 'user_boxes.order_id')
                ->whereRaw('user_boxes.lat + 0 = ?', [(float) $tile['lat']])
                ->whereRaw('user_boxes.lng + 0 = ?', [(float) $tile['lng']])
                ->where(function ($q) use ($includePending) {
                    $q->where('orders.status', '1');
                    if ($includePending) {
                        $q->orWhere(function ($q) {
                            $q->where('orders.status', '0')->where('orders.created_at', '>=', now()->subDay());
                        });
                    }
                });
            if ($exceptOrderId) {
                $query->where('orders.id', '!=', $exceptOrderId);
            }
            if ($query->lockForUpdate()->exists()) {
                $taken[] = $tile;
            }
        }
        return $taken;
    }


    /**
     * Claims tiles from Cart or direct payload, applying the 100 Free Squares quota
     * and $0.10/tile/month pricing beyond 100 squares.
     */
    public function BuyNow(Request $request)
    {
        $user_id = (int) $request->user()->id;

        DB::beginTransaction();
        try {
            $cartTiles = Cart::where('user_id', $user_id)->get();
            $requestedTiles = $request->input('tiles', []);

            $candidates = [];
            if ($cartTiles->isNotEmpty()) {
                foreach ($cartTiles as $ct) {
                    $candidates[] = ['lat' => $ct->lat, 'lng' => $ct->lng];
                }
            } elseif (is_array($requestedTiles) && count($requestedTiles) > 0) {
                foreach ($requestedTiles as $rt) {
                    if (isset($rt['lat']) && isset($rt['lng'])) {
                        $candidates[] = ['lat' => $rt['lat'], 'lng' => $rt['lng']];
                    }
                }
            }

            // Drop malformed and duplicate coordinates
            $tilesToClaim = [];
            foreach ($candidates as $tile) {
                if (!is_numeric($tile['lat']) || !is_numeric($tile['lng'])) {
                    continue;
                }
                $tilesToClaim[(float) $tile['lat'] . '|' . (float) $tile['lng']] = $tile;
            }
            $tilesToClaim = array_values($tilesToClaim);

            $count = count($tilesToClaim);
            if ($count === 0) {
                DB::rollBack();
                return response()->json(['status' => false, 'data' => ['message' => 'No tiles selected in cart.']]);
            }

            $taken = $this->findTakenTiles($tilesToClaim);
            if (!empty($taken)) {
                DB::rollBack();
                return response()->json(['status' => false, 'data' => [
                    'message' => count($taken) . ' of the selected square(s) are already claimed by another citizen.',
                    'taken_tiles' => $taken,
                ]]);
            }

            $alreadyOwned = $this->getOwnedTileCount((int) $user_id);
            $freeRemaining = max(0, self::FREE_TILE_QUOTA - $alreadyOwned);
            $freeUsedNow = min($freeRemaining, $count);
            $billableTiles = max(0, $count - $freeUsedNow);
            $monthlyAmount = round($billableTiles * self::PRICE_PER_TILE_MONTH, 2);

            $order = new Orders();
            $order->user_id = $user_id;
            $order->name = $request->input('area_title', 'Land Plot');
            $order->no_of_tiles = $count;
            $order->amount = $monthlyAmount;
            $order->custom_details = mb_substr((string) $request->input('custom_details', ''), 0, 20000);
            $order->on_sale = '0';
            // Activate immediately only when fully covered by the 100 free quota; billable orders stay
            // pending ('0') until TransactionCompleted confirms the PayPal subscription.
            $order->status = $billableTiles > 0 ? '0' : '1';
            $order->next_due_date = date('Y-m-d H:i:s', strtotime(date('Y-m-d') . ' +1 month'));
            $order->next_expiry = date('Y-m-d H:i:s', strtotime($order->next_due_date . ' +10 day'));
            $this->applyOrderMeta($order, $request);

            if ($order->save()) {
                $defaultImage = (string) $request->input('image', '');
                if (!preg_match('#^https?://\\S+$#i', $defaultImage) || strlen($defaultImage) > 2048) {
                    $defaultImage = '';
                }
                foreach ($tilesToClaim as $tile) {
                    Cart::where('user_id', $user_id)
                        ->where('lat', $tile['lat'])
                        ->where('lng', $tile['lng'])
                        ->delete();

                    $userBox = new UserBoxes();
                    $userBox->order_id = $order->id;
                    $userBox->lat = $tile['lat'];
                    $userBox->lng = $tile['lng'];
                    $userBox->price = $billableTiles > 0 ? (string) self::PRICE_PER_TILE_MONTH : '0';
                    if (!empty($defaultImage)) {
                        $userBox->image = $defaultImage;
                    }
                    $userBox->save();
                }
                $order->center_lat = round(array_sum(array_map(fn ($t) => (float) $t['lat'], $tilesToClaim)) / $count, 7);
                $order->center_lng = round(array_sum(array_map(fn ($t) => (float) $t['lng'], $tilesToClaim)) / $count, 7);
                $order->save();

                DB::commit();

                $approveUrl = null;
                if ($billableTiles > 0) {
                    $billing = app(Billing::class);
                    try {
                        $approveUrl = $billing->startSubscription($order, $request->user(), $billableTiles);
                    } catch (\Throwable $e) {
                        $billing->cancelReservation((int) $order->id);
                        $message = $e instanceof \RuntimeException && !str_starts_with($e->getMessage(), 'PayPal')
                            ? $e->getMessage()
                            : 'Payments are temporarily unavailable, so squares beyond your free 100 can\'t be claimed right now.';
                        \Log::error('Could not start land subscription', ['order' => $order->id, 'error' => $e->getMessage()]);
                        return response()->json(['status' => false, 'message' => $message, 'data' => ['message' => $message]]);
                    }
                }

                $data = [
                    'id' => $order->id,
                    'order_id' => $order->id,
                    'no_of_tiles' => $count,
                    'free_squares_used' => $freeUsedNow,
                    'free_squares_remaining' => max(0, $freeRemaining - $freeUsedNow),
                    'billable_tiles' => $billableTiles,
                    'monthly_amount' => $monthlyAmount,
                    'free_claimed' => $billableTiles === 0,
                    'url' => $approveUrl,
                    'message' => $billableTiles === 0
                        ? "Claimed {$count} square(s) using your 100 Free Squares quota!"
                        : "Reserved {$count} square(s) for 24 hours. Approve the PayPal subscription (\${$monthlyAmount}/month, first payment in one month) to activate them.",
                ];

                return response()->json(['status' => true, 'data' => $data]);
            }

            DB::rollBack();
            return response()->json(['status' => false, 'data' => ['message' => 'Something went wrong.']]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Alias endpoint for direct tile claiming (Guest or Citizen)
     */
    public function ClaimTiles(Request $request)
    {
        return $this->BuyNow($request);
    }

    public function UpdateCustomDetails(Request $request)
    {
        $userdata = $request->user();

        try {
            $custom_details = $request->custom_details;
            $Orders = Orders::findOrFail($request->order_id);

            if (!empty($Orders)) {
                if ((int) $Orders->user_id === (int) $userdata['id']) {
                    if ($request->has('custom_details')) {
                        $Orders->custom_details = mb_substr((string) ($custom_details ?? ''), 0, 20000);
                    }
                    if ($request->has('on_sale')) {
                        $Orders->on_sale = $request->on_sale ? '1' : '0';
                    }
                    if ($request->has('sale_price')) {
                        $Orders->sale_price = (string) $request->sale_price;
                    }
                    $this->applyOrderMeta($Orders, $request);
                    $Orders->save();
                    $data['message'] = 'Content Updated Successfully.';
                    $data['order'] = $this->formatOrderRecord($Orders);
                    return response()->json(['status' => true, 'data' => $data]);
                } else {
                    $data['message'] = 'Unauthorized to edit this land area.';
                    return response()->json(['status' => false, 'data' => $data]);
                }
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateSalePrice(Request $request)
    {
        $userdata = $request->user();

        try {
            $sale_price = $request->sale_price;
            $Orders = Orders::findOrFail($request->order_id);

            if (!empty($Orders)) {
                if ((int) $Orders->user_id === (int) $userdata['id']) {
                    $Orders->sale_price = $sale_price;
                    $Orders->on_sale = $request->on_sale ? '1' : '0';
                    if ($request->has('custom_details')) {
                        $Orders->custom_details = $request->custom_details;
                    }
                    $this->applyOrderMeta($Orders, $request);
                    $Orders->save();

                    $data['message'] = 'Sale price successfully updated.';
                    return response()->json(['status' => true, 'data' => $data]);
                } else {
                    $data['message'] = 'Unauthorized to update this listing.';
                    return response()->json(['status' => false, 'data' => $data]);
                }
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Releases an owned land area back to the open map and cancels any recurring subscription
     */
    public function ReleaseOrder(Request $request)
    {
        $userdata = $request->user();
        $orderId = $request->order_id;

        try {
            $order = Orders::findOrFail($orderId);
            if ((int) $order->user_id !== (int) $userdata['id']) {
                return response()->json(['status' => false, 'message' => 'Unauthorized to release this land area']);
            }

            if (!empty($order->subscription_id)) {
                $this->cancelExistingSubscription(
                    $order->subscription_id,
                    'Citizen released land area #' . $order->id . ' back to the open map.'
                );
            }

            UserBoxes::where('order_id', $order->id)->delete();
            $order->delete();

            return response()->json([
                'status' => true,
                'data' => ['message' => 'Land area released back to the open map and subscription updated.'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function SaleList(Request $request)
    {
        try {
            $filter = $request->query('filter', 'all');
            $limit = max(1, min(200, (int) $request->query('limit', 100)));
            $Orders = Orders::GetUserTiles($filter, $limit);
            $data = ['tiles' => []];

            $boxes = $this->boxesByOrder($Orders);
            foreach ($Orders as $order) {
                $data['tiles'][] = $this->formatOrderRecord($order, $boxes->get($order->id, collect()));
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function MyPlaces(Request $request)
    {
        try {
            $userdata = $request->user();
            $userId = $userdata['id'];
            $Orders = Orders::GetUserTilesByID($userId);
            $data = ['tiles' => []];

            $boxes = $this->boxesByOrder($Orders);
            foreach ($Orders as $order) {
                $data['tiles'][] = $this->formatOrderRecord($order, $boxes->get($order->id, collect()));
            }

            $ownedCount = $this->getOwnedTileCount((int) $userId);
            $data['portfolio'] = [
                'owned_tiles' => $ownedCount,
                'free_quota_total' => self::FREE_TILE_QUOTA,
                'free_quota_remaining' => max(0, self::FREE_TILE_QUOTA - $ownedCount),
                'billable_tiles' => max(0, $ownedCount - self::FREE_TILE_QUOTA),
                'monthly_subscription_usd' => round(max(0, $ownedCount - self::FREE_TILE_QUOTA) * self::PRICE_PER_TILE_MONTH, 2),
            ] + $this->tradeTotals((int) $userId);

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * PayPal checkout. Reached only through a signed URL from BuyNow / BuyMarketplaceOrder, so $user_id
     * (the buyer) cannot be changed by the client.
     */
    /** What a user has spent buying land and earned selling it on the marketplace. */
    protected function tradeTotals(int $userId): array
    {
        $bought = 0.0;
        $sold = 0.0;
        $buyerPattern = '%"buyer_id":' . $userId . ',%';
        $histories = OrderHistory::where('user_id', $userId)->orWhere('log', 'like', $buyerPattern)->get();
        foreach ($histories as $h) {
            $log = json_decode($h->log ?? '', true) ?: [];
            $total = (float) ($log['total_paid'] ?? ((float) $h->no_of_tiles * (float) $h->sale_price));
            if ((int) $h->user_id === $userId) {
                $sold += (float) ($log['seller_net_90pct'] ?? $total * (1 - self::PLATFORM_COMMISSION_RATE));
            }
            if ((int) ($log['buyer_id'] ?? 0) === $userId) {
                $bought += $total;
            }
        }
        return ['bought_usd' => round($bought, 2), 'sold_usd' => round($sold, 2)];
    }











    public function ViewTiles($id)
    {
        $data['tiles'] = UserBoxes::where('order_id', $id)->get();
        return view('Subscriptions.tiles', $data);
    }

    /**
     * Brief 28/4/22 #6: "Can easily revert code to default or old tiles from CMS. If any issues."
     */
    public function RevertOrderFromCMS($id)
    {
        $order = Orders::findOrFail($id);
        $meta = $this->decodeOrderMeta($order);
        unset($meta['custom_code'], $meta['embed_url']);
        $order->custom_details = '';
        $order->log = !empty($meta) ? json_encode($meta) : null;
        $order->save();

        UserBoxes::where('order_id', $order->id)->update(['image' => null]);

        return redirect()->route('Subscriptions')->with('success', 'Order #' . $id . ' code and tile images reverted to default.');
    }

    /**
     * Brief 28/4/22 #6: "Any abuse can remove user and their code and ownership from CMS."
     */
    public function RevokeOrderFromCMS($id)
    {
        $order = Orders::findOrFail($id);
        if (!empty($order->subscription_id)) {
            $this->cancelExistingSubscription($order->subscription_id, 'Order revoked by CMS moderation.');
        }
        UserBoxes::where('order_id', $order->id)->delete();
        $order->delete();

        return redirect()->route('Subscriptions')->with('success', 'Order #' . $id . ' ownership and tiles removed.');
    }

    public function LatestPurchasedTiles(Request $request)
    {
        try {
            $limit = max(1, min(100, (int) $request->query('limit', 48)));
            $Orders = Orders::GetLatestPurchasedTiles($limit);
            $data = ['tiles' => []];

            $boxes = $this->boxesByOrder($Orders);
            foreach ($Orders as $order) {
                $data['tiles'][] = $this->formatOrderRecord($order, $boxes->get($order->id, collect()));
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Finds active land areas by order id, custom URL slug or 3-word address (exact),
     * or by title / owner username (partial). Used for flatmeta.io/u/<slug> links and
     * search, since the map only has the land in view. At most 8 results.
     */
    public function FindArea(Request $request)
    {
        try {
            $q = trim((string) $request->query('q', ''));
            if ($q === '' || mb_strlen($q) > 120) {
                return response()->json(['status' => true, 'data' => ['tiles' => []]]);
            }
            $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', strtolower($q))), '-');
            $like = '%' . addcslashes($q, '%_\\') . '%';

            $Orders = Orders::select('orders.*', 'users.username', 'users.fullname')
                ->leftJoin('users', 'orders.user_id', '=', 'users.id')
                ->where('orders.status', '1')
                ->where(function ($w) use ($q, $slug, $like) {
                    if (ctype_digit($q)) {
                        $w->orWhere('orders.id', (int) $q);
                    }
                    $w->orWhereRaw("JSON_VALID(orders.log) AND JSON_UNQUOTE(JSON_EXTRACT(orders.log, '$.slug')) = ?", [$slug])
                        ->orWhereRaw("JSON_VALID(orders.log) AND JSON_UNQUOTE(JSON_EXTRACT(orders.log, '$.three_words')) = ?", [strtolower($q)])
                        ->orWhere('orders.name', 'like', $like)
                        ->orWhere('users.username', 'like', $like);
                })
                ->orderBy('orders.id', 'desc')
                ->limit(8)
                ->get();

            $data = ['tiles' => []];
            $boxes = $this->boxesByOrder($Orders);
            foreach ($Orders as $order) {
                $data['tiles'][] = $this->formatOrderRecord($order, $boxes->get($order->id, collect()));
            }
            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Brief 28/4/22 #15 & Gamification:
     * Returns Top Landowners, Friendly Citizens, and Clan Standings.
     */
    public function Leaderboard(Request $request)
    {
        try {
            $topLandowners = Orders::select(
                'orders.user_id',
                'users.username',
                'users.fullname',
                DB::raw('SUM(orders.no_of_tiles) as total_tiles'),
                DB::raw('COUNT(orders.id) as total_areas')
            )
                ->leftJoin('users', 'users.id', '=', 'orders.user_id')
                ->where('orders.status', '1')
                ->groupBy('orders.user_id', 'users.username', 'users.fullname')
                ->orderByDesc('total_tiles')
                ->limit(15)
                ->get();

            // Clan standings from real members and the land they hold.
            $clans = [];
            foreach (User::CLANS as $name => $style) {
                $memberIds = User::where('status', '!=', '3')
                    ->where(function ($q) use ($name) {
                        $q->where('clan', $name);
                        if ($name === User::DEFAULT_CLAN) {
                            $q->orWhereNull('clan');
                        }
                    })
                    ->pluck('id');
                $clans[] = [
                    'name' => $name,
                    'badge' => $style['badge'],
                    'color' => $style['color'],
                    'members' => $memberIds->count(),
                    'tiles' => (int) Orders::whereIn('user_id', $memberIds)->where('status', '1')->sum('no_of_tiles'),
                ];
            }
            usort($clans, fn ($a, $b) => $b['tiles'] <=> $a['tiles'] ?: $b['members'] <=> $a['members']);

            return response()->json([
                'status' => true,
                'data' => [
                    'landowners' => $topLandowners,
                    'clans' => $clans,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function getProposalsFilePath(): string
    {
        return storage_path('app/community_proposals.json');
    }

    protected function loadCommunityProposals(): array
    {
        $path = $this->getProposalsFilePath();
        if (file_exists($path)) {
            $decoded = json_decode(file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Brief 28/4/22 #13: "Mini-git-lab. Users can create code and ask the community to approve features on the site,
     * if majority vote we will test and add the feature."
     */
    public function CommunityProposals(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => ['proposals' => $this->loadCommunityProposals()],
        ]);
    }

    public function SubmitCommunityProposal(Request $request)
    {
        try {
            $proposals = $this->loadCommunityProposals();
            $newProp = [
                'id' => 'prop-' . time(),
                'title' => trim((string) $request->input('title', 'Untitled Community Feature')),
                'author' => (string) $request->user()->username,
                'category' => trim((string) $request->input('category', 'Feature')),
                'description' => trim((string) $request->input('description', '')),
                'codeSnippet' => (string) $request->input('codeSnippet', ''),
                'votes' => 1,
                'targetVotes' => 150,
                'status' => 'Voting',
                'created_at' => now()->toISOString(),
            ];
            array_unshift($proposals, $newProp);
            @file_put_contents($this->getProposalsFilePath(), json_encode($proposals, JSON_PRETTY_PRINT));

            return response()->json([
                'status' => true,
                'data' => [
                    'proposal' => $newProp,
                    'proposals' => $proposals,
                    'message' => 'Proposal submitted to FlatMeta Mini-Git-Lab for community voting!',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function VoteCommunityProposal(Request $request)
    {
        try {
            $id = (string) $request->input('id', '');
            // One vote per user per proposal: +1, -1 or 0 (withdraw). Re-voting replaces the previous vote.
            $delta = max(-1, min(1, (int) $request->input('delta', 1)));
            $userKey = (string) $request->user()->id;
            $proposals = $this->loadCommunityProposals();

            $votesPath = storage_path('app/community_votes.json');
            $votes = file_exists($votesPath) ? (json_decode(file_get_contents($votesPath), true) ?: []) : [];
            $previous = (int) ($votes[$id][$userKey] ?? 0);
            $votes[$id][$userKey] = $delta;

            foreach ($proposals as &$p) {
                if (($p['id'] ?? '') === $id) {
                    $p['votes'] = max(0, (int) ($p['votes'] ?? 0) + $delta - $previous);
                    if ($p['votes'] >= ($p['targetVotes'] ?? 150) && ($p['status'] ?? 'Voting') === 'Voting') {
                        $p['status'] = 'Testing';
                    }
                    break;
                }
            }
            unset($p);

            @file_put_contents($this->getProposalsFilePath(), json_encode($proposals, JSON_PRETTY_PRINT));
            @file_put_contents($votesPath, json_encode($votes, JSON_PRETTY_PRINT));

            return response()->json([
                'status' => true,
                'data' => ['proposals' => $proposals],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
