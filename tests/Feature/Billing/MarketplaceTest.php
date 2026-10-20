<?php

namespace Tests\Feature\Billing;

use App\Models\OrderHistory;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class MarketplaceTest extends BillingTestCase
{
    protected function listing(array $sellerAttrs = [])
    {
        $seller = $this->user(array_merge(['paypal_email' => 'seller-pay@example.com'], $sellerAttrs));
        $order = $this->landFor($seller, 4, 0, [
            'on_sale' => '1',
            'sale_price' => '2.50',
            'subscription_id' => 'I-SELLER',
            'subscription_status' => 'ACTIVE',
        ]);
        return [$seller, $order];
    }

    public function test_buying_starts_a_one_time_paypal_checkout_not_a_subscription(): void
    {
        [, $order] = $this->listing();
        $buyer = $this->user();

        $res = $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);

        $res->assertJson(['status' => true, 'data' => ['total_price' => 10.0, 'commission' => 1.0, 'seller_net' => 9.0]]);
        $this->assertStringContainsString('checkoutnow?token=ORDER-1', $res->json('data.url'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && $r['intent'] === 'CAPTURE'
            && $r['purchase_units'][0]['amount']['value'] === '10.00');
        $this->assertPayPalNotCalled('POST', '/v1/billing/subscriptions');
        $this->assertSame('1', $order->fresh()->status);
        $this->assertNotEquals($buyer->id, $order->fresh()->user_id, 'nothing moves before payment');
    }

    public function test_paying_moves_ownership_cancels_seller_rent_and_pays_the_seller(): void
    {
        [$seller, $order] = $this->listing();
        $buyer = $this->user();
        $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        $this->paypal['capture'] = $this->capturedOrder('10.00');

        $res = $this->get('/billing/sale/return?token=ORDER-1&PayerID=P1');

        $this->assertStringStartsWith('https://app.test/thankyou?kind=sale&status=ok', $res->headers->get('Location'));
        $order->refresh();
        $this->assertSame($buyer->id, (int) $order->user_id);
        $this->assertSame('0', $order->on_sale);
        $this->assertNull($order->subscription_id);
        $this->assertPayPalCalled('POST', '/v1/billing/subscriptions/I-SELLER/cancel');

        $sale = Payment::where('kind', 'sale')->first();
        $this->assertSame('completed', $sale->status);
        $payout = Payment::where('kind', 'payout')->first();
        $this->assertSame('sent', $payout->status);
        $this->assertSame(9.0, $payout->amount);
        $this->assertSame($seller->id, (int) $payout->user_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payments/payouts')
            && $r['items'][0]['receiver'] === 'seller-pay@example.com'
            && $r['items'][0]['amount']['value'] === '9.00');

        $history = OrderHistory::first();
        $this->assertSame($seller->id, (int) $history->user_id);
        $this->assertSame($buyer->id, json_decode($history->log, true)['buyer_id']);
    }

    public function test_coming_back_twice_does_not_pay_the_seller_twice(): void
    {
        [, $order] = $this->listing();
        $buyer = $this->user();
        $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        $this->paypal['capture'] = $this->capturedOrder('10.00');

        $this->get('/billing/sale/return?token=ORDER-1');
        $this->get('/billing/sale/return?token=ORDER-1');
        // And the webhook for the same checkout.
        $this->postJson('/api/paypal/webhook', ['id' => 'WH-A', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']]);

        $this->assertSame(1, Payment::where('kind', 'payout')->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/payments/payouts'))->count());
    }

    public function test_if_the_listing_changed_before_payment_the_buyer_is_refunded(): void
    {
        [$seller, $order] = $this->listing();
        $buyer = $this->user();
        $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        // Seller raises the price while the buyer is at PayPal.
        $order->sale_price = '5.00';
        $order->save();
        $this->paypal['capture'] = $this->capturedOrder('10.00');

        $res = $this->get('/billing/sale/return?token=ORDER-1');

        $this->assertStringContainsString('status=problem', $res->headers->get('Location'));
        $this->assertSame($seller->id, (int) $order->fresh()->user_id);
        $this->assertPayPalCalled('POST', '/v2/payments/captures/CAPTURE-1/refund');
        $this->assertSame('refunded', Payment::where('kind', 'sale')->first()->status);
        $this->assertSame(0, Payment::where('kind', 'payout')->count());
    }

    public function test_an_unfinished_payment_moves_nothing(): void
    {
        [, $order] = $this->listing();
        $buyer = $this->user();
        $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        $this->paypal['capture'] = $this->capturedOrder('10.00', 'DECLINED');

        $this->get('/billing/sale/return?token=ORDER-1');

        $this->assertNotEquals($buyer->id, (int) $order->fresh()->user_id);
        $this->assertSame('failed', Payment::where('kind', 'sale')->first()->status);
    }

    public function test_you_cannot_buy_your_own_or_unlisted_land(): void
    {
        [$seller, $order] = $this->listing();
        $this->actingAsUser($seller)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id])
            ->assertJson(['status' => false]);

        $order->on_sale = '0';
        $order->save();
        $this->actingAsUser($this->user())->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id])
            ->assertJson(['status' => false]);
        $this->assertPayPalNotCalled('POST', '/v2/checkout/orders');
    }

    public function test_a_failed_payout_is_recorded_and_retried(): void
    {
        [, $order] = $this->listing();
        $this->paypal['payout_status'] = 403; // Payouts not enabled on the account
        $this->actingAsUser($this->user())->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        $this->paypal['capture'] = $this->capturedOrder('10.00');
        $this->get('/billing/sale/return?token=ORDER-1');

        $payout = Payment::where('kind', 'payout')->first();
        $this->assertSame('failed', $payout->status);
        $this->assertStringContainsString('AUTHORIZATION_ERROR', $payout->meta['error']);

        $this->paypal['payout_status'] = 200;
        $this->artisan('payouts:retry')->assertExitCode(0);
        $this->assertSame('sent', $payout->fresh()->status);

        $this->postJson('/api/paypal/webhook', [
            'id' => 'WH-P', 'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'resource' => ['payout_batch_id' => 'BATCH-1', 'transaction_status' => 'SUCCESS'],
        ])->assertOk();
        $this->assertSame('completed', $payout->fresh()->status);
    }

    public function test_buying_past_your_free_squares_flags_billing(): void
    {
        [, $order] = $this->listing();
        $buyer = $this->user();
        $this->landFor($buyer, 99, 5000);
        $this->actingAsUser($buyer)->postJson('/api/BuyMarketplaceOrder', ['order_id' => $order->id]);
        $this->paypal['capture'] = $this->capturedOrder('10.00');
        $this->get('/billing/sale/return?token=ORDER-1');

        $mine = $this->actingAsUser($buyer)->getJson('/api/MyPlaces')->json('data.tiles');
        $bought = collect($mine)->firstWhere('order_id', $order->id);
        $this->assertTrue($bought['billing_required']);

        $res = $this->actingAsUser($buyer)->postJson('/api/StartBilling', ['order_id' => $order->id]);
        $res->assertJson(['status' => true, 'data' => ['billable_tiles' => 3]]);
    }
}
