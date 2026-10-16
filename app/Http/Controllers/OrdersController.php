<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\OrderHistory;
use App\Models\Orders;
use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

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
        if (empty($subscriptionId)) {
            return false;
        }
        try {
            $paypal = new PaypalController();
            $paypal->cancel_subscription($subscriptionId, ['reason' => $reason]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
            'buy_link' => url('PurchaseTile/' . $order->id),
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
     * Signed, short-lived PayPal checkout link for $buyerId (the URL cannot be edited to pay for someone else).
     */
    protected function purchaseUrl(int $orderId, int $buyerId): string
    {
        return URL::temporarySignedRoute('PurchaseTile', now()->addHour(), ['id' => $orderId, 'userID' => $buyerId]);
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

                $data = [
                    'id' => $order->id,
                    'order_id' => $order->id,
                    'no_of_tiles' => $count,
                    'free_squares_used' => $freeUsedNow,
                    'free_squares_remaining' => max(0, $freeRemaining - $freeUsedNow),
                    'billable_tiles' => $billableTiles,
                    'monthly_amount' => $monthlyAmount,
                    'free_claimed' => $billableTiles === 0,
                    'url' => $billableTiles > 0 ? $this->purchaseUrl((int) $order->id, $user_id) : null,
                    'message' => $billableTiles === 0
                        ? "Claimed {$count} square(s) using your 100 Free Squares quota!"
                        : "Reserved {$count} square(s). Complete the PayPal subscription (\${$monthlyAmount}/month) to activate them.",
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
     * Marketplace purchase with 10% Platform Commission.
     * Ownership is NOT transferred here: the buyer gets a signed PayPal checkout link and the transfer
     * (plus seller subscription cancellation & OrderHistory logging) happens in TransactionCompleted
     * once PayPal confirms the subscription.
     * Brief [j] & [k]: "if sold subscription for sold tiles will cancel, remaining month will charge them, next month will not charge them"
     */
    public function BuyMarketplaceOrder(Request $request)
    {
        $buyerId = (int) $request->user()->id;
        $orderId = $request->order_id;

        if (!$orderId) {
            return response()->json(['status' => false, 'message' => 'order_id is required']);
        }

        try {
            $order = Orders::findOrFail($orderId);

            if ($order->status != '1' || $order->on_sale != '1') {
                return response()->json(['status' => false, 'data' => ['message' => 'This land area is not for sale.']]);
            }
            if ((int) $order->user_id === $buyerId) {
                return response()->json(['status' => false, 'data' => ['message' => 'You already own this land area.']]);
            }

            $pricePerTile = (float) $order->sale_price;
            if ($pricePerTile <= 0) {
                return response()->json(['status' => false, 'data' => ['message' => 'This listing has no valid sale price.']]);
            }

            $tileCount = max(1, (int) $order->no_of_tiles);
            $totalPrice = round($tileCount * $pricePerTile, 2);
            $commission = round($totalPrice * self::PLATFORM_COMMISSION_RATE, 2);
            $sellerNet = round($totalPrice - $commission, 2);

            return response()->json([
                'status' => true,
                'data' => [
                    'order_id' => $order->id,
                    'requires_payment' => true,
                    'url' => $this->purchaseUrl((int) $order->id, $buyerId),
                    'total_price' => $totalPrice,
                    'commission' => $commission,
                    'seller_net' => $sellerNet,
                    'message' => 'Complete the PayPal checkout to take ownership of this land area.',
                ],
            ]);
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

    public function PurchaseTile($id, $user_id = null)
    {
        $Orders = Orders::findOrFail($id);
        $buyerId = (int) ($user_id ?: $Orders->user_id);
        $User = User::findOrFail($buyerId);

        if ($Orders->on_sale == '1' && (float) $Orders->sale_price > 0 && (int) $Orders->user_id !== $buyerId && $Orders->status == '1') {
            $quantity = max(1, (int) round($Orders->no_of_tiles * $Orders->sale_price * 10));
            $type = 'sale';
        } elseif ((int) $Orders->user_id === $buyerId && $Orders->status != '1') {
            $alreadyOwned = $this->getOwnedTileCount($buyerId);
            $billable = max(1, (int) $Orders->no_of_tiles - max(0, self::FREE_TILE_QUOTA - $alreadyOwned));
            $quantity = $billable;
            $type = 'new';
        } else {
            abort(404, 'This land area is not available for purchase.');
        }

        DB::beginTransaction();
        try {
            $planId = env('PLAN_ID', 'P-1G766395YH0963631MKLR5GI');
            $data = [
                'plan_id' => $planId,
                'quantity' => $quantity,
                'start_time' => Carbon::now()->addSeconds(10),
                'subscriber' => [
                    'name' => ['given_name' => $User->fullname, 'surname' => $User->username],
                    'email_address' => $User->email,
                ],
                'application_context' => [
                    'brand_name' => (config('app.name') ?: env('APP_NAME', 'Flatmeta')) . ' Monthly Subscription',
                    'locale' => 'en-US',
                    'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'payment_method' => [
                        'payer_selected' => 'PAYPAL',
                        'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
                    ],
                    'return_url' => url('TransactionCompleted/' . $id . '/' . $type),
                    'cancel_url' => url('TransactionCancel/' . $id),
                ],
            ];

            $paypal = new PaypalController();
            $subscribe = $paypal->subscribe($data);
            if (!isset($subscribe->debug_id) && isset($subscribe->links) && !empty($subscribe->id)) {
                foreach ($subscribe->links as $link) {
                    if ($link->rel == 'approve') {
                        // Remember which subscription belongs to which buyer/order; TransactionCompleted
                        // only accepts a subscription_id recorded here.
                        $meta = $this->decodeOrderMeta($Orders);
                        $meta['pending_subscriptions'][$subscribe->id] = [
                            'type' => $type,
                            'buyer_id' => $buyerId,
                            'plan_id' => $planId,
                            'quantity' => $quantity,
                            'created_at' => now()->toISOString(),
                        ];
                        $Orders->log = json_encode($meta);
                        $Orders->save();
                        DB::commit();
                        return redirect($link->href);
                    }
                }
            }
            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            echo 'Something went wrong: ' . $e->getMessage();
        }
    }

    public function CreateProd()
    {
        $data = [
            'name' => 'Flatmeta.io',
            'type' => 'SERVICE',
            'description' => 'flatmeta provides the land in a virtual world where u can buy and sale your land',
            'category' => 'SERVICES',
            'home_url' => $this->baseUrl(),
        ];

        $paypal = new PaypalController();
        $subscribe = $paypal->make_product($data);
        print_r($subscribe);
    }

    public function CreatePlan()
    {
        $data = [
            'product_id' => 'PROD-6U229780257548608',
            'name' => 'Flatmeta Plan ($0.10 / Square / Month)',
            'quantity_supported' => true,
            'description' => 'FlatMeta virtual land subscription ($0.10 per square per month after 100 free squares)',
        ];

        $data['billing_cycles'][] = [
            'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            'tenure_type' => 'REGULAR',
            'sequence' => 1,
            'total_cycles' => 0,
            'pricing_scheme' => [
                'fixed_price' => [
                    'value' => '0.10',
                    'currency_code' => 'USD',
                ],
            ],
        ];
        $data['payment_preferences'] = [
            'auto_bill_outstanding' => true,
            'setup_fee' => ['value' => '0', 'currency_code' => 'USD'],
            'setup_fee_failure_action' => 'CONTINUE',
            'payment_failure_threshold' => 3,
        ];
        $data['taxes'] = [
            'percentage' => '0',
            'inclusive' => true,
        ];
        $paypal = new PaypalController();
        $package = $paypal->make_package($data);
        print_r($package);
    }

    public function GetProdById()
    {
        $paypal = new PaypalController();
        $package = $paypal->get_product('PROD-6U229780257548608');
        print_r($package);
    }

    public function GetPlanById()
    {
        $paypal = new PaypalController();
        $package = $paypal->get_plan('P-4V708803SV048851WMKPTV3A');
        print_r($package);
    }

    public function GetAllProducts()
    {
        $paypal = new PaypalController();
        $package = $paypal->get_all_product();
        print_r($package);
    }

    public function GetSubscriptionsDetails()
    {
        $paypal = new PaypalController();
        $package = $paypal->get_subscription('I-KU7MXH8WSP1H');
        print_r($package);
    }

    /**
     * PayPal return URL. The subscription_id must be one PurchaseTile recorded for this order, it must be
     * ACTIVE with the expected plan and quantity, and the buyer comes from that record (not the URL).
     */
    public function TransactionCompleted(Request $request, $id, $type = null, $userID = null)
    {
        $subscriptionId = (string) ($request['subscription_id'] ?? '');
        if ($subscriptionId === '') {
            return response()->json(['status' => false, 'data' => ['message' => 'Missing subscription.']]);
        }

        DB::beginTransaction();
        try {
            $Orders = Orders::lockForUpdate()->findOrFail($id);
            $meta = $this->decodeOrderMeta($Orders);
            $pending = $meta['pending_subscriptions'][$subscriptionId] ?? null;
            if (!$pending) {
                DB::rollBack();
                return response()->json(['status' => false, 'data' => ['message' => 'Unknown subscription for this order.']]);
            }

            $paypal = new PaypalController();
            $subscription_response = $paypal->get_subscription($subscriptionId);
            if (
                !$subscription_response
                || ($subscription_response->status ?? '') !== 'ACTIVE'
                || ($subscription_response->plan_id ?? '') !== $pending['plan_id']
                || (int) ($subscription_response->quantity ?? 0) !== (int) $pending['quantity']
            ) {
                DB::rollBack();
                return response()->json(['status' => false, 'data' => ['message' => 'Subscription is not active.']]);
            }

            // Single use
            unset($meta['pending_subscriptions'][$subscriptionId]);
            $buyerId = (int) $pending['buyer_id'];

            if ($pending['type'] !== 'sale') {
                $myTiles = UserBoxes::where('order_id', $Orders->id)->get(['lat', 'lng'])->toArray();
                if ((int) $Orders->user_id !== $buyerId || !empty($this->findTakenTiles($myTiles, (int) $Orders->id, false))) {
                    $this->cancelExistingSubscription($subscriptionId, 'Squares for order #' . $Orders->id . ' were no longer available.');
                    $Orders->status = '2';
                    $Orders->log = json_encode($meta);
                    $Orders->save();
                    DB::commit();
                    return response()->json(['status' => false, 'data' => ['message' => 'These squares were claimed by someone else. Your subscription was cancelled.']]);
                }

                $meta['paypal_subscription'] = $subscription_response;
                $Orders->status = '1';
                $Orders->subscription_id = $subscriptionId;
                $Orders->log = json_encode($meta);
                $Orders->save();
            } else {
                if ($Orders->status != '1' || $Orders->on_sale != '1' || (int) $Orders->user_id === $buyerId) {
                    $this->cancelExistingSubscription($subscriptionId, 'Land area #' . $Orders->id . ' was no longer for sale.');
                    $Orders->log = json_encode($meta);
                    $Orders->save();
                    DB::commit();
                    return response()->json(['status' => false, 'data' => ['message' => 'This land area is no longer for sale. Your subscription was cancelled.']]);
                }

                $totalAmount = $Orders->no_of_tiles * $Orders->sale_price;
                $commission = round($totalAmount * self::PLATFORM_COMMISSION_RATE, 2);

                // Brief [j] & [k]: Cancel previous owner's subscription so next month will not charge them
                $oldSubscriptionId = $Orders->subscription_id ?? null;
                $subCancelled = $this->cancelExistingSubscription(
                    $oldSubscriptionId,
                    'Land area #' . $Orders->id . ' resold on FlatMeta; previous subscription cancelled for next cycle.'
                );

                $OrderHistory = new OrderHistory();
                $OrderHistory->old_order_id = $Orders->id;
                $OrderHistory->user_id = $Orders->user_id;
                $OrderHistory->name = $Orders->name;
                $OrderHistory->no_of_tiles = $Orders->no_of_tiles;
                $OrderHistory->amount = $Orders->amount;
                $OrderHistory->custom_details = $Orders->custom_details;
                $OrderHistory->sale_price = $Orders->sale_price;
                $OrderHistory->log = json_encode([
                    'paypal' => $subscription_response,
                    'buyer_id' => $buyerId,
                    'platform_commission_10pct' => $commission,
                    'seller_net_90pct' => round($totalAmount - $commission, 2),
                    'previous_subscription_id' => $oldSubscriptionId,
                    'seller_subscription_cancelled' => $subCancelled,
                ]);
                $OrderHistory->next_due_date = $Orders->next_due_date;
                $OrderHistory->next_expiry = $Orders->next_expiry;
                $OrderHistory->save();

                $meta['paypal_subscription'] = $subscription_response;
                $Orders->user_id = $buyerId;
                $Orders->amount = $totalAmount;
                $Orders->sale_price = '';
                $Orders->on_sale = '0';
                $Orders->status = '1';
                $Orders->subscription_id = $subscriptionId;
                $Orders->log = json_encode($meta);
                $Orders->save();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        // Back to the app (not the API host) after PayPal.
        $redirectUrl = rtrim(config('app.frontend_url'), '/') . '/thankyou';
        return redirect()->to($redirectUrl);
    }

    /**
     * PayPal cancel URL. Only a pending (unpaid) order is marked cancelled; active orders and
     * marketplace listings are never touched from here.
     */
    public function TransactionCancel(Request $request, $id)
    {
        $Orders = Orders::findOrFail($id);

        $meta = $this->decodeOrderMeta($Orders);
        $subscriptionId = (string) ($request['subscription_id'] ?? '');
        if ($subscriptionId !== '' && isset($meta['pending_subscriptions'][$subscriptionId])) {
            unset($meta['pending_subscriptions'][$subscriptionId]);
            $Orders->log = json_encode($meta);
        }
        if ($Orders->status == '0') {
            $Orders->status = '2';
        }
        $Orders->save();

        $data['message'] = 'Transaction Cancelled. Please Try Again.';
        return response()->json(['status' => true, 'data' => $data]);
    }

    public function UpdatePlanPricing(Request $request, $id) {}

    public function index()
    {
        $data['Orders'] = Orders::GetAllOrders();
        return view('Subscriptions.list', $data);
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
