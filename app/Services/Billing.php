<?php

namespace App\Services;

use App\Models\OrderHistory;
use App\Models\Orders;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FlatMeta's money rules, shared by the API, the PayPal return pages, webhooks
 * and the scheduled clean-up.
 *
 * Land rent: the first 100 squares are free. Claims beyond that create a
 * reserved order (status '0') and a PayPal subscription priced per square per
 * month. The first payment is taken one month after approval ("pay at the end
 * of the month, cancel before payment is taken"). The land goes live (status
 * '1') once PayPal reports the subscription approved. If the subscription is
 * later cancelled or suspended, the land stays until the paid period runs out,
 * then goes back to the open map.
 *
 * Marketplace: a one-time PayPal payment of tiles x price. Ownership moves only
 * after the money is captured; if the listing changed in the meantime the buyer
 * is refunded. The seller's share (90%) is sent with PayPal Payouts.
 */
class Billing
{
    public const FREE_TILE_QUOTA = 100;
    public const PRICE_PER_TILE_MONTH = '0.10';
    public const COMMISSION_RATE = 0.10;
    /** Unpaid reservations and checkouts are dropped after this long. */
    public const RESERVATION_HOURS = 24;
    /** Extra days of ownership after a payment fails before land is released. */
    public const GRACE_DAYS = 7;

    public function __construct(protected PayPal $paypal)
    {
    }

    // =========================================================================
    // Land rent (subscriptions)
    // =========================================================================

    /** Squares in an order that are beyond the owner's free quota. */
    public function billableTilesFor(Orders $order): int
    {
        $ownedElsewhere = (int) Orders::where('user_id', $order->user_id)
            ->where('status', '1')
            ->where('id', '!=', $order->id)
            ->sum('no_of_tiles');
        $freeLeft = max(0, self::FREE_TILE_QUOTA - $ownedElsewhere);
        return max(0, (int) $order->no_of_tiles - $freeLeft);
    }

    /**
     * Creates the PayPal subscription for a reserved order and returns the link
     * where the user approves it.
     */
    public function startSubscription(Orders $order, User $user, int $quantity): string
    {
        $planId = config('services.paypal.plan_id');
        if (!$planId) {
            Log::error('PAYPAL_PLAN_ID is not set: run php artisan paypal:setup (see deploy/README.md).');
            throw new RuntimeException('Paid squares are not available yet. Your first 100 squares are free; please try again later for more.');
        }
        $payment = Payment::create([
            'kind' => Payment::KIND_SUBSCRIPTION,
            'status' => 'pending',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => round($quantity * (float) self::PRICE_PER_TILE_MONTH, 2),
            'currency' => $this->paypal->currency(),
            'meta' => ['quantity' => $quantity, 'plan_id' => $planId],
        ]);

        $sub = $this->paypal->createSubscription([
            'plan_id' => $planId,
            'quantity' => $quantity,
            'custom_id' => 'order:' . $order->id,
            'email' => $user->email,
            // First month free: PayPal takes the first payment a month from now.
            'start_time' => now()->addMonth()->utc()->format('Y-m-d\TH:i:s\Z'),
            'return_url' => url('/billing/subscription/return'),
            'cancel_url' => url('/billing/subscription/cancel/' . $order->id),
        ]);

        $payment->provider_ref = $sub['id'];
        $payment->save();
        return $sub['approve_url'];
    }

    /**
     * Activates the land once PayPal says the subscription is approved. Safe to
     * call more than once (return page and webhook can both arrive).
     *
     * @return array{ok: bool, message: string, order_id?: int}
     */
    public function completeSubscription(string $subscriptionId): array
    {
        $payment = Payment::where('kind', Payment::KIND_SUBSCRIPTION)->where('provider_ref', $subscriptionId)->first();
        if (!$payment) {
            return ['ok' => false, 'message' => 'Unknown subscription.'];
        }
        if ($payment->status === 'completed') {
            return ['ok' => true, 'message' => 'Your land is active.', 'order_id' => $payment->order_id];
        }

        $sub = $this->paypal->getSubscription($subscriptionId);
        $status = $sub['status'] ?? '';
        $meta = $payment->meta ?? [];
        if (($sub['custom_id'] ?? '') !== 'order:' . $payment->order_id
            || ($sub['plan_id'] ?? '') !== ($meta['plan_id'] ?? null)
            || (int) ($sub['quantity'] ?? 0) !== (int) ($meta['quantity'] ?? -1)) {
            Log::warning('PayPal subscription does not match its order', ['subscription' => $subscriptionId]);
            return ['ok' => false, 'message' => 'This subscription does not match the land it was created for.'];
        }
        if (!in_array($status, ['ACTIVE', 'APPROVED'], true)) {
            return ['ok' => false, 'message' => 'PayPal has not approved the subscription yet (status: ' . strtolower($status ?: 'unknown') . ').'];
        }

        return DB::transaction(function () use ($payment, $subscriptionId, $sub) {
            $order = Orders::lockForUpdate()->find($payment->order_id);
            if (!$order || $order->status === '2' || (int) $order->user_id !== (int) $payment->user_id) {
                $this->safeCancel($subscriptionId, 'The reserved squares were released before payment was set up.');
                $payment->update(['status' => 'cancelled']);
                return ['ok' => false, 'message' => 'Your reservation had expired, so the subscription was cancelled. Please claim the squares again.'];
            }
            if ($this->squaresTakenByOthers($order)) {
                $this->safeCancel($subscriptionId, 'Squares were claimed by someone else.');
                $order->status = '2';
                $order->save();
                $payment->update(['status' => 'cancelled']);
                return ['ok' => false, 'message' => 'Someone else claimed these squares first, so your subscription was cancelled.'];
            }

            $nextBilling = $sub['billing_info']['next_billing_time'] ?? $sub['start_time'] ?? null;
            $due = $nextBilling ? \Carbon\Carbon::parse($nextBilling) : now()->addMonth();
            $order->status = '1';
            $order->subscription_id = $subscriptionId;
            $order->subscription_status = 'ACTIVE';
            $order->next_due_date = $due;
            $order->next_expiry = $due->copy()->addDays(self::GRACE_DAYS);
            $orderMeta = json_decode($order->log ?? '', true) ?: [];
            unset($orderMeta['billing_required']);
            $order->log = json_encode($orderMeta);
            $order->save();
            $payment->update(['status' => 'completed']);
            return ['ok' => true, 'message' => 'Your land is active. The first monthly payment is taken in one month.', 'order_id' => $order->id];
        });
    }

    public function cancelReservation(int $orderId, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderId, $userId) {
            $order = Orders::lockForUpdate()->find($orderId);
            if (!$order || $order->status !== '0' || ($userId && (int) $order->user_id !== $userId)) {
                return;
            }
            $order->status = '2';
            $order->save();
            UserBoxes::where('order_id', $order->id)->delete();
            Payment::where('kind', Payment::KIND_SUBSCRIPTION)->where('order_id', $order->id)->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        });
    }

    /** Webhook events for land subscriptions. */
    public function handleSubscriptionEvent(string $type, array $resource): string
    {
        if ($type === 'PAYMENT.SALE.COMPLETED') {
            $subId = $resource['billing_agreement_id'] ?? null;
            $order = $subId ? Orders::where('subscription_id', $subId)->first() : null;
            if (!$order) {
                return 'payment for unknown subscription';
            }
            Payment::firstOrCreate(
                ['kind' => 'rent', 'provider_ref' => $resource['id'] ?? null],
                [
                    'status' => 'completed',
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'amount' => (float) ($resource['amount']['total'] ?? 0),
                    'currency' => $resource['amount']['currency'] ?? $this->paypal->currency(),
                ]
            );
            $order->subscription_status = 'ACTIVE';
            $order->next_due_date = now()->addMonth();
            $order->next_expiry = now()->addMonth()->addDays(self::GRACE_DAYS);
            $order->save();
            return 'rent recorded';
        }

        $subId = $resource['id'] ?? null;
        if (!$subId) {
            return 'no subscription id';
        }
        switch ($type) {
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                return $this->completeSubscription($subId)['message'];
            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
                // Keep the land for the time already paid for, then release it.
                return $this->markSubscription($subId, 'CANCELLED', null);
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                return $this->markSubscription($subId, 'SUSPENDED', now()->addDays(self::GRACE_DAYS));
            default:
                return 'ignored';
        }
    }

    protected function markSubscription(string $subId, string $status, $expiry): string
    {
        $order = Orders::where('subscription_id', $subId)->first();
        if (!$order) {
            return 'unknown subscription';
        }
        $order->subscription_status = $status;
        if ($expiry) {
            $order->next_expiry = $order->next_expiry && $order->next_expiry < $expiry ? $order->next_expiry : $expiry;
        } elseif ($order->next_due_date) {
            $order->next_expiry = $order->next_due_date;
        } else {
            $order->next_expiry = now();
        }
        $order->save();
        return "subscription {$status}";
    }

    // =========================================================================
    // Marketplace sales
    // =========================================================================

    /** Creates the one-time PayPal checkout for buying a listed land area. */
    public function startSale(Orders $order, User $buyer): string
    {
        if ($order->status !== '1' || $order->on_sale !== '1') {
            throw new RuntimeException('This land area is not for sale.');
        }
        if ((int) $order->user_id === (int) $buyer->id) {
            throw new RuntimeException('You already own this land area.');
        }
        $pricePerTile = round((float) $order->sale_price, 2);
        $tiles = max(1, (int) $order->no_of_tiles);
        if ($pricePerTile <= 0) {
            throw new RuntimeException('This listing has no valid price.');
        }
        $total = round($tiles * $pricePerTile, 2);
        $commission = round($total * self::COMMISSION_RATE, 2);

        $payment = Payment::create([
            'kind' => Payment::KIND_SALE,
            'status' => 'pending',
            'user_id' => $buyer->id,
            'order_id' => $order->id,
            'amount' => $total,
            'currency' => $this->paypal->currency(),
            'meta' => [
                'seller_id' => (int) $order->user_id,
                'tiles' => $tiles,
                'price_per_tile' => $pricePerTile,
                'commission' => $commission,
                'seller_net' => round($total - $commission, 2),
            ],
        ]);

        $checkout = $this->paypal->createOrder(
            number_format($total, 2, '.', ''),
            "FlatMeta land: {$tiles} square" . ($tiles === 1 ? '' : 's') . ' — ' . ($order->name ?: 'Land #' . $order->id),
            'sale:' . $payment->id,
            url('/billing/sale/return'),
            url('/billing/sale/cancel')
        );
        $payment->provider_ref = $checkout['id'];
        $payment->save();
        return $checkout['approve_url'];
    }

    /**
     * Captures an approved marketplace checkout and moves ownership to the buyer.
     * Safe to call more than once.
     *
     * @return array{ok: bool, message: string, order_id?: int}
     */
    public function completeSale(string $paypalOrderId): array
    {
        $payment = Payment::where('kind', Payment::KIND_SALE)->where('provider_ref', $paypalOrderId)->first();
        if (!$payment) {
            return ['ok' => false, 'message' => 'Unknown checkout.'];
        }
        if ($payment->status === 'completed') {
            return ['ok' => true, 'message' => 'The land is yours.', 'order_id' => $payment->order_id];
        }
        if (in_array($payment->status, ['refunded', 'cancelled'], true)) {
            return ['ok' => false, 'message' => 'This checkout was cancelled.'];
        }

        $captured = $this->paypal->captureOrder($paypalOrderId);
        $capture = PayPal::completedCapture($captured);
        if (!$capture) {
            $payment->mergeMeta(['paypal_status' => $captured['status'] ?? null]);
            $payment->status = 'failed';
            $payment->save();
            return ['ok' => false, 'message' => 'PayPal did not complete the payment, so nothing was charged.'];
        }
        $paidAmount = round((float) ($capture['amount']['value'] ?? 0), 2);

        $result = DB::transaction(function () use ($payment, $capture, $paidAmount) {
            $payment = Payment::lockForUpdate()->find($payment->id);
            if ($payment->status === 'completed') {
                return ['ok' => true, 'message' => 'The land is yours.', 'order_id' => $payment->order_id, 'already' => true];
            }
            $meta = $payment->meta ?? [];
            $payment->mergeMeta(['capture_id' => $capture['id']]);
            $order = Orders::lockForUpdate()->find($payment->order_id);

            $stillValid = $order
                && $order->status === '1'
                && $order->on_sale === '1'
                && (int) $order->user_id === (int) ($meta['seller_id'] ?? 0)
                && (int) $order->no_of_tiles === (int) ($meta['tiles'] ?? -1)
                && round((float) $order->sale_price, 2) === round((float) ($meta['price_per_tile'] ?? -1), 2)
                && $paidAmount === round((float) $payment->amount, 2);

            if (!$stillValid) {
                $payment->status = 'refund_due';
                $payment->save();
                return ['ok' => false, 'refund' => true, 'message' => 'The listing changed or was sold before your payment went through, so you are being refunded in full.'];
            }

            $sellerId = (int) $order->user_id;
            $history = new OrderHistory();
            $history->old_order_id = $order->id;
            $history->user_id = $sellerId;
            $history->name = $order->name;
            $history->no_of_tiles = $order->no_of_tiles;
            $history->amount = $order->amount;
            $history->custom_details = $order->custom_details;
            $history->sale_price = $order->sale_price;
            $history->log = json_encode([
                'buyer_id' => (int) $payment->user_id,
                'total_paid' => (float) $payment->amount,
                'platform_commission_10pct' => (float) ($meta['commission'] ?? 0),
                'seller_net_90pct' => (float) ($meta['seller_net'] ?? 0),
                'payment_id' => $payment->id,
                'paypal_capture_id' => $capture['id'],
                'previous_subscription_id' => $order->subscription_id,
            ]);
            $history->next_due_date = $order->next_due_date;
            $history->next_expiry = $order->next_expiry;
            $history->save();

            $oldSubscription = $order->subscription_id;
            $order->user_id = (int) $payment->user_id;
            $order->on_sale = '0';
            $order->sale_price = '';
            $order->subscription_id = null;
            $order->subscription_status = null;
            $order->amount = 0;
            $order->save();

            // The buyer now owns this land; if it takes them past the free quota, they set up rent for it.
            $billable = $this->billableTilesFor($order);
            $orderMeta = json_decode($order->log ?? '', true) ?: [];
            unset($orderMeta['pending_subscriptions']);
            if ($billable > 0) {
                $orderMeta['billing_required'] = ['tiles' => $billable, 'since' => now()->toISOString()];
            } else {
                unset($orderMeta['billing_required']);
            }
            $order->log = json_encode($orderMeta);
            $order->save();

            $payment->status = 'completed';
            $payment->save();

            return [
                'ok' => true,
                'message' => 'Payment received — the land is yours.',
                'order_id' => $order->id,
                'old_subscription' => $oldSubscription,
                'billable' => $billable,
            ];
        });

        if (!empty($result['refund'])) {
            $this->refundSale($payment->fresh(), 'Listing changed before payment completed');
            return $result;
        }
        if (empty($result['already'])) {
            // The seller stops paying rent for land they no longer own.
            if (!empty($result['old_subscription'])) {
                $this->safeCancel($result['old_subscription'], 'Land sold on the FlatMeta marketplace.');
            }
            $this->payoutSeller($payment->fresh());
        }
        if (($result['billable'] ?? 0) > 0) {
            $result['message'] .= " It takes you past your {$this->freeQuota()} free squares, so set up monthly billing for it in My land.";
        }
        return $result;
    }

    public function cancelSale(string $paypalOrderId): void
    {
        Payment::where('kind', Payment::KIND_SALE)->where('provider_ref', $paypalOrderId)->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    protected function refundSale(Payment $payment, string $why): void
    {
        $captureId = $payment->meta['capture_id'] ?? null;
        try {
            $refund = $this->paypal->refundCapture($captureId, 'FlatMeta: ' . $why);
            Payment::create([
                'kind' => Payment::KIND_REFUND,
                'status' => ($refund['status'] ?? '') === 'COMPLETED' ? 'completed' : 'pending',
                'user_id' => $payment->user_id,
                'order_id' => $payment->order_id,
                'related_payment_id' => $payment->id,
                'provider_ref' => $refund['id'] ?? null,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'meta' => ['reason' => $why],
            ]);
            $payment->status = 'refunded';
        } catch (\Throwable $e) {
            Log::error('Marketplace refund failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            $payment->mergeMeta(['refund_error' => $e->getMessage()]);
            $payment->status = 'refund_due';
        }
        $payment->save();
    }

    // =========================================================================
    // Seller payouts
    // =========================================================================

    /** Sends the seller's share of a completed sale. Failures stay visible in the CMS to retry. */
    public function payoutSeller(Payment $sale): Payment
    {
        $meta = $sale->meta ?? [];
        $seller = User::find($meta['seller_id'] ?? 0);
        $payout = Payment::firstOrCreate(
            ['kind' => Payment::KIND_PAYOUT, 'related_payment_id' => $sale->id],
            [
                'status' => 'pending',
                'user_id' => $seller->id ?? null,
                'order_id' => $sale->order_id,
                'amount' => (float) ($meta['seller_net'] ?? 0),
                'currency' => $sale->currency,
            ]
        );
        if (in_array($payout->status, ['completed', 'sent'], true)) {
            return $payout;
        }
        if (!config('services.paypal.payouts_enabled')) {
            $payout->update(['status' => 'failed', 'meta' => array_merge($payout->meta ?? [], ['error' => 'Automatic payouts are turned off; pay this seller manually.'])]);
            return $payout;
        }
        $email = $seller ? ($seller->paypal_email ?: $seller->email) : null;
        if (!$email || $payout->amount <= 0) {
            $payout->update(['status' => 'failed', 'meta' => array_merge($payout->meta ?? [], ['error' => 'No seller PayPal email.'])]);
            return $payout;
        }
        try {
            $attempt = (int) (($payout->meta['attempts'] ?? 0)) + 1;
            $res = $this->paypal->createPayout(
                $email,
                number_format($payout->amount, 2, '.', ''),
                'payout-' . $payout->id . '-' . $attempt,
                'Your FlatMeta land sold. This is your share after the 10% FlatMeta fee.'
            );
            $payout->provider_ref = $res['batch_id'];
            $payout->status = 'sent';
            $payout->mergeMeta(['attempts' => $attempt, 'email' => $email, 'error' => null]);
        } catch (\Throwable $e) {
            $payout->status = 'failed';
            $payout->mergeMeta(['attempts' => ($payout->meta['attempts'] ?? 0) + 1, 'email' => $email, 'error' => $e->getMessage()]);
            Log::error('Seller payout failed', ['payout' => $payout->id, 'error' => $e->getMessage()]);
        }
        $payout->save();
        return $payout;
    }

    /** Webhook events for payouts and one-time checkouts. */
    public function handleMoneyEvent(string $type, array $resource): string
    {
        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                // The buyer approved but may have closed the tab before returning: capture here.
                return $this->completeSale((string) ($resource['id'] ?? ''))['message'];
            case 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED':
            case 'PAYMENT.PAYOUTS-ITEM.FAILED':
            case 'PAYMENT.PAYOUTS-ITEM.RETURNED':
            case 'PAYMENT.PAYOUTS-ITEM.BLOCKED':
            case 'PAYMENT.PAYOUTS-ITEM.DENIED':
            case 'PAYMENT.PAYOUTS-ITEM.UNCLAIMED':
                $batch = $resource['payout_batch_id'] ?? null;
                $payout = $batch ? Payment::where('kind', Payment::KIND_PAYOUT)->where('provider_ref', $batch)->first() : null;
                if (!$payout) {
                    return 'unknown payout';
                }
                $payout->status = match ($type) {
                    'PAYMENT.PAYOUTS-ITEM.SUCCEEDED' => 'completed',
                    'PAYMENT.PAYOUTS-ITEM.UNCLAIMED' => 'unclaimed',
                    default => 'failed',
                };
                $payout->mergeMeta(['paypal_item_status' => $resource['transaction_status'] ?? null]);
                $payout->save();
                return 'payout ' . $payout->status;
            default:
                return 'ignored';
        }
    }

    // =========================================================================
    // Scheduled clean-up (php artisan billing:enforce, every 15 minutes)
    // =========================================================================

    /** @return array<string, int> */
    public function enforce(): array
    {
        $stats = ['expired_reservations' => 0, 'released_lapsed' => 0, 'stale_checkouts' => 0];

        // Reservations nobody paid for.
        $stale = Orders::where('status', '0')->where('created_at', '<', now()->subHours(self::RESERVATION_HOURS))->pluck('id');
        foreach ($stale as $id) {
            $this->cancelReservation((int) $id);
            $stats['expired_reservations']++;
        }

        // Rent stopped (cancelled / payments failed) and the paid period plus grace is over.
        $lapsed = Orders::where('status', '1')
            ->whereIn('subscription_status', ['CANCELLED', 'SUSPENDED'])
            ->whereNotNull('next_expiry')
            ->where('next_expiry', '<', now())
            ->get();
        foreach ($lapsed as $order) {
            DB::transaction(function () use ($order) {
                $order->status = '2';
                $order->on_sale = '0';
                $order->save();
                UserBoxes::where('order_id', $order->id)->delete();
            });
            $stats['released_lapsed']++;
        }

        // Marketplace checkouts that were never paid.
        $stats['stale_checkouts'] = Payment::where('kind', Payment::KIND_SALE)->where('status', 'pending')
            ->where('created_at', '<', now()->subHours(self::RESERVATION_HOURS))
            ->update(['status' => 'cancelled']);

        return $stats;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function freeQuota(): int
    {
        return self::FREE_TILE_QUOTA;
    }

    protected function squaresTakenByOthers(Orders $order): bool
    {
        foreach (UserBoxes::where('order_id', $order->id)->get(['lat', 'lng']) as $box) {
            $taken = UserBoxes::join('orders', 'orders.id', '=', 'user_boxes.order_id')
                ->where('orders.id', '!=', $order->id)
                ->where('orders.status', '1')
                ->whereRaw('user_boxes.lat + 0 = ?', [(float) $box->lat])
                ->whereRaw('user_boxes.lng + 0 = ?', [(float) $box->lng])
                ->exists();
            if ($taken) {
                return true;
            }
        }
        return false;
    }

    public function safeCancel(?string $subscriptionId, string $reason): bool
    {
        if (!$subscriptionId) {
            return false;
        }
        try {
            $this->paypal->cancelSubscription($subscriptionId, $reason);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not cancel PayPal subscription', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
