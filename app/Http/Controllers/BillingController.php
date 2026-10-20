<?php

namespace App\Http\Controllers;

use App\Models\Orders;
use App\Models\Payment;
use App\Services\Billing;
use App\Services\PayPal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayPal entry points:
 * - API (token): StartBilling, BuyMarketplaceOrder, MyPayments
 * - Browser return pages PayPal sends people back to (then on to the app's /thankyou)
 * - Webhook: POST /api/paypal/webhook
 */
class BillingController extends Controller
{
    public function __construct(protected Billing $billing, protected PayPal $paypal)
    {
    }

    // -------------------------------------------------------------------------
    // API
    // -------------------------------------------------------------------------

    /** Starts the marketplace checkout; the app opens the returned PayPal link. */
    public function BuyMarketplaceOrder(Request $request)
    {
        $order = Orders::find((int) $request->order_id);
        if (!$order) {
            return $this->fail('This land area no longer exists.');
        }
        try {
            $url = $this->billing->startSale($order, $request->user());
        } catch (\Throwable $e) {
            return $this->fail($this->friendly($e));
        }
        $total = round((int) $order->no_of_tiles * (float) $order->sale_price, 2);
        $commission = round($total * Billing::COMMISSION_RATE, 2);
        return response()->json(['status' => true, 'data' => [
            'order_id' => $order->id,
            'requires_payment' => true,
            'url' => $url,
            'total_price' => $total,
            'commission' => $commission,
            'seller_net' => round($total - $commission, 2),
            'message' => 'Continue to PayPal to pay. The land becomes yours as soon as payment completes.',
        ]]);
    }

    /** Sets up monthly rent for land that is past the owner's free squares (e.g. after buying on the marketplace). */
    public function StartBilling(Request $request)
    {
        $user = $request->user();
        $order = Orders::where('id', (int) $request->order_id)->where('user_id', $user->id)->whereIn('status', ['0', '1'])->first();
        if (!$order) {
            return $this->fail('Land not found.');
        }
        if ($order->subscription_id && $order->subscription_status === 'ACTIVE') {
            return $this->fail('Billing is already set up for this land.');
        }
        $quantity = $this->billing->billableTilesFor($order);
        if ($quantity <= 0) {
            return $this->fail('This land is covered by your free squares, so there is nothing to pay.');
        }
        try {
            $url = $this->billing->startSubscription($order, $user, $quantity);
        } catch (\Throwable $e) {
            return $this->fail($this->friendly($e));
        }
        return response()->json(['status' => true, 'data' => [
            'url' => $url,
            'billable_tiles' => $quantity,
            'monthly_amount' => round($quantity * (float) Billing::PRICE_PER_TILE_MONTH, 2),
            'message' => 'Continue to PayPal to set up monthly billing.',
        ]]);
    }

    /** The signed-in user's payment history (subscriptions, purchases, payouts received). */
    public function MyPayments(Request $request)
    {
        $userId = $request->user()->id;
        $rows = Payment::where('user_id', $userId)->whereIn('status', ['completed', 'sent', 'failed', 'unclaimed', 'refunded'])
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'kind' => $p->kind,
                'status' => $p->status,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'order_id' => $p->order_id,
                'created_at' => (string) $p->created_at,
            ]);
        return response()->json(['status' => true, 'data' => [
            'payments' => $rows,
            'manage_url' => $this->paypal->mode() === 'live'
                ? 'https://www.paypal.com/myaccount/autopay/'
                : 'https://www.sandbox.paypal.com/myaccount/autopay/',
        ]]);
    }

    // -------------------------------------------------------------------------
    // PayPal return pages (browser)
    // -------------------------------------------------------------------------

    public function subscriptionReturn(Request $request)
    {
        $id = (string) $request->query('subscription_id', '');
        try {
            $result = $id ? $this->billing->completeSubscription($id) : ['ok' => false, 'message' => 'Missing subscription.'];
        } catch (\Throwable $e) {
            Log::error('Subscription return failed', ['subscription' => $id, 'error' => $e->getMessage()]);
            $result = ['ok' => false, 'message' => 'We could not confirm your subscription with PayPal yet. If PayPal shows it as active, your land will switch on within a few minutes.'];
        }
        return $this->toApp('rent', $result);
    }

    public function subscriptionCancel(Request $request, $orderId)
    {
        // Only drop a reservation whose PayPal subscription was started for it.
        $subId = (string) $request->query('subscription_id', '');
        $payment = $subId === '' ? null : Payment::where('kind', Payment::KIND_SUBSCRIPTION)->where('order_id', (int) $orderId)
            ->where('provider_ref', $subId)->where('status', 'pending')->first();
        if ($payment) {
            $this->billing->cancelReservation((int) $orderId, (int) $payment->user_id);
        }
        return $this->toApp('rent', ['ok' => false, 'cancelled' => true, 'message' => 'You cancelled at PayPal, so the squares were not claimed.']);
    }

    public function saleReturn(Request $request)
    {
        $token = (string) $request->query('token', '');
        try {
            $result = $token ? $this->billing->completeSale($token) : ['ok' => false, 'message' => 'Missing checkout.'];
        } catch (\Throwable $e) {
            Log::error('Sale return failed', ['token' => $token, 'error' => $e->getMessage()]);
            $result = ['ok' => false, 'message' => 'We could not confirm your payment with PayPal yet. If you were charged, the land will move to you within a few minutes, or you will be refunded.'];
        }
        return $this->toApp('sale', $result);
    }

    public function saleCancel(Request $request)
    {
        $token = (string) $request->query('token', '');
        if ($token) {
            $this->billing->cancelSale($token);
        }
        return $this->toApp('sale', ['ok' => false, 'cancelled' => true, 'message' => 'You cancelled at PayPal, so nothing was charged.']);
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    public function webhook(Request $request)
    {
        $event = $request->json()->all();
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['event_type'] ?? '');
        if ($id === '' || $type === '') {
            return response()->json(['ok' => false], 400);
        }
        if (!$this->paypal->verifyWebhook($request->headers->all(), $event)) {
            Log::warning('Rejected unverified PayPal webhook', ['id' => $id, 'type' => $type]);
            return response()->json(['ok' => false], 400);
        }

        // PayPal retries deliveries; handle each event once.
        $inserted = DB::table('paypal_events')->insertOrIgnore([
            'id' => $id,
            'type' => $type,
            'resource_id' => mb_substr((string) ($event['resource']['id'] ?? ''), 0, 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (!$inserted) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $resource = $event['resource'] ?? [];
            $result = str_starts_with($type, 'BILLING.SUBSCRIPTION.') || $type === 'PAYMENT.SALE.COMPLETED'
                ? $this->billing->handleSubscriptionEvent($type, $resource)
                : $this->billing->handleMoneyEvent($type, $resource);
        } catch (\Throwable $e) {
            // Let PayPal retry later.
            DB::table('paypal_events')->where('id', $id)->delete();
            Log::error('PayPal webhook failed', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false], 500);
        }
        DB::table('paypal_events')->where('id', $id)->update(['result' => mb_substr($result, 0, 120)]);
        return response()->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    protected function toApp(string $kind, array $result)
    {
        $status = !empty($result['cancelled']) ? 'cancelled' : (!empty($result['ok']) ? 'ok' : 'problem');
        $query = http_build_query(array_filter([
            'kind' => $kind,
            'status' => $status,
            'message' => $result['message'] ?? null,
            'order' => $result['order_id'] ?? null,
        ]));
        return redirect()->away(rtrim(config('app.frontend_url'), '/') . '/thankyou?' . $query);
    }

    protected function fail(string $message)
    {
        return response()->json(['status' => false, 'message' => $message, 'data' => ['message' => $message]]);
    }

    protected function friendly(\Throwable $e): string
    {
        Log::error('Billing error', ['error' => $e->getMessage()]);
        // Our own validation messages are safe to show; PayPal/HTTP internals are not.
        return $e instanceof \RuntimeException && !str_starts_with($e->getMessage(), 'PayPal')
            ? $e->getMessage()
            : 'Payments are temporarily unavailable. Please try again in a few minutes.';
    }
}
