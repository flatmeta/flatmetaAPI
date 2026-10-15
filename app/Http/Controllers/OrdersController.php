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

    protected function formatOrderRecord($order): array
    {
        $boxes = UserBoxes::where('order_id', $order->id)->get();
        $firstBox = $boxes->first();
        $lat = $firstBox ? (float) $firstBox->lat : 51.50972;
        $lng = $firstBox ? (float) $firstBox->lng : -0.104317;
        $tileCount = $boxes->count() > 0 ? $boxes->count() : (int) ($order->no_of_tiles ?: 1);

        $tilesArr = [];
        foreach ($boxes as $b) {
            $tilesArr[] = [
                'id' => $b->id,
                'order_id' => $order->id,
                'lat' => (float) $b->lat,
                'lng' => (float) $b->lng,
                'image' => $this->formatTileImage($b->image ?? null),
            ];
        }

        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'area_id' => $order->id,
            'image' => $this->formatTileImage($firstBox ? $firstBox->image : null),
            'user_id' => $order->user_id,
            'username' => $order->username ?? 'citizen',
            'fullname' => $order->fullname ?? 'Metaverse Citizen',
            'no_of_tiles' => $tileCount,
            'lat' => $lat,
            'lng' => $lng,
            'tile_lat' => $lat,
            'tile_lng' => $lng,
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
     * Claims tiles from Cart or direct payload, applying the 100 Free Squares quota
     * and $0.10/tile/month pricing beyond 100 squares.
     */
    public function BuyNow(Request $request)
    {
        $userdata = $request->user();
        $user_id = $userdata ? $userdata['id'] : (int) $request->user_id;

        if (!$user_id) {
            return response()->json(['status' => false, 'message' => 'User ID is required']);
        }

        DB::beginTransaction();
        try {
            $cartTiles = Cart::where('user_id', $user_id)->get();
            $requestedTiles = $request->input('tiles', []);

            $tilesToClaim = [];
            if ($cartTiles->isNotEmpty()) {
                foreach ($cartTiles as $ct) {
                    $tilesToClaim[] = ['lat' => $ct->lat, 'lng' => $ct->lng];
                }
            } elseif (is_array($requestedTiles) && count($requestedTiles) > 0) {
                foreach ($requestedTiles as $rt) {
                    if (isset($rt['lat']) && isset($rt['lng'])) {
                        $tilesToClaim[] = ['lat' => $rt['lat'], 'lng' => $rt['lng']];
                    }
                }
            }

            $count = count($tilesToClaim);
            if ($count === 0) {
                DB::rollBack();
                return response()->json(['status' => false, 'data' => ['message' => 'No tiles selected in cart.']]);
            }

            $alreadyOwned = $this->getOwnedTileCount((int) $user_id);
            $freeRemaining = max(0, self::FREE_TILE_QUOTA - $alreadyOwned);
            $freeUsedNow = min($freeRemaining, $count);
            $billableTiles = max(0, $count - $freeUsedNow);
            $monthlyAmount = round($billableTiles * self::PRICE_PER_TILE_MONTH, 2);

            $order = new Orders();
            $order->user_id = $user_id;
            $order->no_of_tiles = $count;
            $order->amount = $monthlyAmount;
            $order->custom_details = $request->input('custom_details', '');
            $order->on_sale = '0';
            // Activate immediately when covered by 100 free quota (or instant claim)
            $order->status = '1';
            $order->next_due_date = date('Y-m-d H:i:s', strtotime(date('Y-m-d') . ' +1 month'));
            $order->next_expiry = date('Y-m-d H:i:s', strtotime($order->next_due_date . ' +10 day'));

            if ($order->save()) {
                $defaultImage = $request->input('image', '');
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
                    'url' => $billableTiles > 0 ? url('PurchaseTile/' . $order->id . '/' . $user_id) : null,
                    'message' => $billableTiles === 0
                        ? "Claimed {$count} square(s) using your 100 Free Squares quota!"
                        : "Claimed {$count} square(s) (\${$monthlyAmount}/month subscription).",
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
                if (!$userdata || $Orders->user_id == $userdata['id']) {
                    $Orders->custom_details = $custom_details;
                    if ($request->has('on_sale')) {
                        $Orders->on_sale = $request->on_sale ? '1' : '0';
                    }
                    if ($request->has('sale_price')) {
                        $Orders->sale_price = (string) $request->sale_price;
                    }
                    $Orders->save();
                    $data['message'] = 'Content Updated Successfully.';
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
                if (!$userdata || $Orders->user_id == $userdata['id']) {
                    $Orders->sale_price = $sale_price;
                    $Orders->on_sale = $request->on_sale ? '1' : '0';
                    if ($request->has('custom_details')) {
                        $Orders->custom_details = $request->custom_details;
                    }
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
     * Direct Marketplace Purchase with 10% Platform Commission & OrderHistory logging
     */
    public function BuyMarketplaceOrder(Request $request)
    {
        $userdata = $request->user();
        $buyerId = $userdata ? $userdata['id'] : (int) $request->buyer_user_id;
        $orderId = $request->order_id;

        if (!$buyerId || !$orderId) {
            return response()->json(['status' => false, 'message' => 'order_id and buyer_user_id are required']);
        }

        DB::beginTransaction();
        try {
            $order = Orders::findOrFail($orderId);
            $tileCount = max(1, (int) $order->no_of_tiles);
            $pricePerTile = (float) ($order->sale_price ?: $order->amount ?: 0.50);
            $totalPrice = round($tileCount * $pricePerTile, 2);
            $commission = round($totalPrice * self::PLATFORM_COMMISSION_RATE, 2);
            $sellerNet = round($totalPrice - $commission, 2);

            $history = new OrderHistory();
            $history->old_order_id = $order->id;
            $history->user_id = $order->user_id;
            $history->name = $order->name ?? ('Order #' . $order->id);
            $history->no_of_tiles = $order->no_of_tiles;
            $history->amount = $order->amount;
            $history->custom_details = $order->custom_details;
            $history->sale_price = $order->sale_price;
            $history->log = json_encode([
                'buyer_id' => $buyerId,
                'total_paid' => $totalPrice,
                'platform_commission_10pct' => $commission,
                'seller_net_90pct' => $sellerNet,
                'transferred_at' => now()->toISOString(),
            ]);
            $history->next_due_date = $order->next_due_date;
            $history->next_expiry = $order->next_expiry;
            $history->save();

            $order->user_id = $buyerId;
            $order->amount = $totalPrice;
            $order->sale_price = '0';
            $order->on_sale = '0';
            $order->status = '1';
            $order->save();

            DB::commit();

            return response()->json([
                'status' => true,
                'data' => [
                    'order_id' => $order->id,
                    'total_paid' => $totalPrice,
                    'commission' => $commission,
                    'seller_net' => $sellerNet,
                    'message' => "Land area acquired! Ownership transferred to Citizen #{$buyerId}.",
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Releases an owned land area back to the open map and updates subscription count
     */
    public function ReleaseOrder(Request $request)
    {
        $userdata = $request->user();
        $orderId = $request->order_id;

        try {
            $order = Orders::findOrFail($orderId);
            if ($userdata && $order->user_id != $userdata['id']) {
                return response()->json(['status' => false, 'message' => 'Unauthorized to release this land area']);
            }

            UserBoxes::where('order_id', $order->id)->delete();
            $order->delete();

            return response()->json([
                'status' => true,
                'data' => ['message' => 'Land area released back to the open map.'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function SaleList(Request $request)
    {
        try {
            $filter = $request->query('filter', 'all');
            $Orders = Orders::GetUserTiles($filter);
            $data = ['tiles' => []];

            if (!empty($Orders)) {
                foreach ($Orders as $order) {
                    $data['tiles'][] = $this->formatOrderRecord($order);
                }
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
            $userId = $userdata ? $userdata['id'] : $request->query('user_id');
            $Orders = Orders::GetUserTilesByID($userId);
            $data = ['tiles' => []];

            if (!empty($Orders)) {
                foreach ($Orders as $order) {
                    $data['tiles'][] = $this->formatOrderRecord($order);
                }
            }

            $ownedCount = $this->getOwnedTileCount((int) $userId);
            $data['portfolio'] = [
                'owned_tiles' => $ownedCount,
                'free_quota_total' => self::FREE_TILE_QUOTA,
                'free_quota_remaining' => max(0, self::FREE_TILE_QUOTA - $ownedCount),
                'billable_tiles' => max(0, $ownedCount - self::FREE_TILE_QUOTA),
                'monthly_subscription_usd' => round(max(0, $ownedCount - self::FREE_TILE_QUOTA) * self::PRICE_PER_TILE_MONTH, 2),
            ];

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function PurchaseTile($id, $user_id = null)
    {
        $Orders = Orders::findOrFail($id);
        if (empty($user_id)) {
            $user_id = $Orders->user_id;
        }
        $User = User::findOrFail($Orders->user_id);

        if ($Orders->sale_price) {
            $quantity = max(1, (int) round($Orders->no_of_tiles * $Orders->sale_price * 10));
            $type = 'sale';
        } else {
            $alreadyOwned = $this->getOwnedTileCount((int) $user_id);
            $billable = max(1, (int) $Orders->no_of_tiles - max(0, self::FREE_TILE_QUOTA - $alreadyOwned));
            $quantity = $billable;
            $type = 'new';
        }

        DB::beginTransaction();
        try {
            $data = [
                'plan_id' => env('PLAN_ID', 'P-1G766395YH0963631MKLR5GI'),
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
                    'return_url' => url('TransactionCompleted/' . $id . '/' . $type . '/' . $user_id),
                    'cancel_url' => url('TransactionCancel/' . $id),
                ],
            ];

            $paypal = new PaypalController();
            $subscribe = $paypal->subscribe($data);
            if (!isset($subscribe->debug_id) && isset($subscribe->links)) {
                foreach ($subscribe->links as $link) {
                    if ($link->rel == 'approve') {
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

    public function TransactionCompleted(Request $request, $id, $type, $userID)
    {
        if (!empty($request['subscription_id'])) {
            $paypal = new PaypalController();
            $subscription_response = $paypal->get_subscription($request['subscription_id']);

            if ($subscription_response && $subscription_response->status == 'ACTIVE') {
                if ($type != 'sale') {
                    $Orders = Orders::findOrFail($id);
                    $Orders->status = '1';
                    $Orders->subscription_id = $request['subscription_id'];
                    $Orders->log = json_encode($subscription_response);
                    $Orders->save();
                } else {
                    $Orders = Orders::findOrFail($id);
                    $totalAmount = $Orders->no_of_tiles * $Orders->sale_price;
                    $commission = round($totalAmount * self::PLATFORM_COMMISSION_RATE, 2);

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
                        'platform_commission_10pct' => $commission,
                        'seller_net_90pct' => round($totalAmount - $commission, 2),
                    ]);
                    $OrderHistory->next_due_date = $Orders->next_due_date;
                    $OrderHistory->next_expiry = $Orders->next_expiry;

                    if ($OrderHistory->save()) {
                        $Orders->user_id = $userID;
                        $Orders->amount = $totalAmount;
                        $Orders->sale_price = '';
                        $Orders->on_sale = '0';
                        $Orders->status = '1';
                        $Orders->subscription_id = $request['subscription_id'];
                        $Orders->log = json_encode($subscription_response);
                        $Orders->save();
                    }
                }

                if ($type != 'sale') {
                    $tiles = Cart::where('user_id', $Orders->user_id)->get();
                    foreach ($tiles as $tile) {
                        Cart::where('lat', $tile['lat'])->where('lng', $tile['lng'])->delete();

                        $UserBoxes = new UserBoxes();
                        $UserBoxes->order_id = $Orders->id;
                        $UserBoxes->lat = $tile['lat'];
                        $UserBoxes->lng = $tile['lng'];
                        $UserBoxes->price = (string) self::PRICE_PER_TILE_MONTH;
                        $UserBoxes->save();
                    }
                }

                $redirectUrl = $this->baseUrl() . 'flatmeta/thankyou';
                return redirect()->to($redirectUrl);
            }
        }
    }

    public function TransactionCancel(Request $request, $id)
    {
        $Orders = Orders::findOrFail($id);

        if (!empty($Orders)) {
            $Orders->status = '2';
            $Orders->save();

            $data['message'] = 'Transaction Cancelled. Please Try Again.';
            return response()->json(['status' => true, 'data' => $data]);
        }
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
        $order->custom_details = '';
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
        UserBoxes::where('order_id', $order->id)->delete();
        $order->delete();

        return redirect()->route('Subscriptions')->with('success', 'Order #' . $id . ' ownership and tiles removed.');
    }

    public function LatestPurchasedTiles(Request $request)
    {
        try {
            $Orders = Orders::GetLatestPurchasedTiles();
            $data = ['tiles' => []];

            if (!empty($Orders)) {
                foreach ($Orders as $order) {
                    $data['tiles'][] = $this->formatOrderRecord($order);
                }
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
