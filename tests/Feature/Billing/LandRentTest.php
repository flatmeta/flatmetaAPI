<?php

namespace Tests\Feature\Billing;

use App\Models\Orders;
use App\Models\Payment;
use App\Services\Billing;

class LandRentTest extends BillingTestCase
{
    public function test_free_squares_are_claimed_without_payment(): void
    {
        $user = $this->user();
        $res = $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(3)]);

        $res->assertJson(['status' => true, 'data' => ['free_claimed' => true, 'url' => null]]);
        $this->assertSame('1', Orders::first()->status);
        $this->assertPayPalNotCalled('POST', '/v1/billing/subscriptions');
    }

    public function test_squares_beyond_the_free_quota_reserve_land_and_start_a_subscription(): void
    {
        $user = $this->user();
        $this->landFor($user, 98);

        $res = $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(7)]);

        $res->assertJson(['status' => true, 'data' => ['billable_tiles' => 5, 'free_squares_used' => 2]]);
        $this->assertStringContainsString('sandbox.paypal.com', $res->json('data.url'));
        $order = Orders::latest('id')->first();
        $this->assertSame('0', $order->status, 'reserved until PayPal approves');

        $payment = Payment::where('kind', 'subscription')->first();
        $this->assertSame('I-SUB1', $payment->provider_ref);
        $this->assertSame(5, $payment->meta['quantity']);
        // Billed per square, first payment a month from now.
        \Illuminate\Support\Facades\Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || !str_ends_with($r->url(), '/v1/billing/subscriptions')) {
                return false;
            }
            return $r['quantity'] === '5' && $r['plan_id'] === 'P-TESTPLAN' && str_starts_with($r['custom_id'], 'order:')
                && \Carbon\Carbon::parse($r['start_time'])->greaterThan(now()->addDays(27));
        });
    }

    public function test_returning_from_paypal_activates_the_land(): void
    {
        $user = $this->user();
        $this->landFor($user, 100);
        $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(5)])->assertJson(['status' => true]);
        $order = Orders::latest('id')->first();
        $this->paypal['subscription']['custom_id'] = 'order:' . $order->id;

        $res = $this->get('/billing/subscription/return?subscription_id=I-SUB1&ba_token=BA-1&token=X');

        $res->assertRedirect();
        $this->assertStringStartsWith('https://app.test/thankyou?kind=rent&status=ok', $res->headers->get('Location'));
        $order->refresh();
        $this->assertSame('1', $order->status);
        $this->assertSame('I-SUB1', $order->subscription_id);
        $this->assertSame('ACTIVE', $order->subscription_status);
        $this->assertSame('completed', Payment::first()->status);
    }

    public function test_a_subscription_that_does_not_match_the_order_is_rejected(): void
    {
        $user = $this->user();
        $this->landFor($user, 100);
        $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(5)]);
        $order = Orders::latest('id')->first();
        $this->paypal['subscription']['custom_id'] = 'order:' . $order->id;
        $this->paypal['subscription']['quantity'] = '1'; // paid for fewer squares

        $res = $this->get('/billing/subscription/return?subscription_id=I-SUB1');

        $this->assertStringContainsString('status=problem', $res->headers->get('Location'));
        $this->assertSame('0', $order->fresh()->status);
    }

    public function test_cancelling_at_paypal_releases_the_reservation(): void
    {
        $user = $this->user();
        $this->landFor($user, 100);
        $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(2)]);
        $order = Orders::latest('id')->first();

        // Without the subscription id nothing happens (someone else can't cancel your reservation).
        $this->get('/billing/subscription/cancel/' . $order->id);
        $this->assertSame('0', $order->fresh()->status);

        $this->get('/billing/subscription/cancel/' . $order->id . '?subscription_id=I-SUB1');
        $this->assertSame('2', $order->fresh()->status);
        $this->assertSame(0, \App\Models\UserBoxes::where('order_id', $order->id)->count());
    }

    public function test_if_paypal_is_down_the_reservation_is_dropped_and_explained(): void
    {
        config(['services.paypal.plan_id' => null]);
        $user = $this->user();
        $this->landFor($user, 100);

        $res = $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(2)]);

        $res->assertJson(['status' => false]);
        $this->assertStringContainsString('not available yet', $res->json('message'));
        $this->assertStringNotContainsString('PAYPAL', $res->json('message'));
        $this->assertSame('2', Orders::latest('id')->first()->status);
    }

    public function test_cancelled_rent_keeps_land_until_paid_period_ends_then_releases_it(): void
    {
        $user = $this->user();
        $order = $this->landFor($user, 3, 0, [
            'subscription_id' => 'I-SUB1',
            'subscription_status' => 'ACTIVE',
            'next_due_date' => now()->addDays(10),
        ]);

        $this->postJson('/api/paypal/webhook', [
            'id' => 'WH-EV-1',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource' => ['id' => 'I-SUB1'],
        ])->assertOk();

        $order->refresh();
        $this->assertSame('CANCELLED', $order->subscription_status);
        $this->assertSame('1', $order->status, 'still yours for the month you paid');

        app(Billing::class)->enforce();
        $this->assertSame('1', $order->fresh()->status);

        $this->travel(11)->days();
        app(Billing::class)->enforce();
        $this->assertSame('2', $order->fresh()->status);
    }

    public function test_unpaid_reservations_expire_after_a_day(): void
    {
        $user = $this->user();
        $this->landFor($user, 100);
        $this->actingAsUser($user)->postJson('/api/ClaimTiles', ['tiles' => $this->squares(2)]);
        $order = Orders::latest('id')->first();

        $this->travel(25)->hours();
        $stats = app(Billing::class)->enforce();

        $this->assertSame(1, $stats['expired_reservations']);
        $this->assertSame('2', $order->fresh()->status);
    }

    public function test_rent_payment_webhook_extends_the_paid_period(): void
    {
        $user = $this->user();
        $order = $this->landFor($user, 3, 0, ['subscription_id' => 'I-SUB1', 'subscription_status' => 'SUSPENDED']);

        $this->postJson('/api/paypal/webhook', [
            'id' => 'WH-EV-2',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB1', 'amount' => ['total' => '0.30', 'currency' => 'USD']],
        ])->assertOk();

        $order->refresh();
        $this->assertSame('ACTIVE', $order->subscription_status);
        $this->assertTrue($order->next_expiry > now()->addDays(30));
        $this->assertDatabaseHas('payments', ['kind' => 'rent', 'provider_ref' => 'SALE-1', 'amount' => 0.30]);
    }
}
