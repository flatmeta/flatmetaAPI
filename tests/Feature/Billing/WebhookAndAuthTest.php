<?php

namespace Tests\Feature\Billing;

use App\Mail\ValidateUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class WebhookAndAuthTest extends BillingTestCase
{
    public function test_webhooks_that_paypal_does_not_verify_are_rejected(): void
    {
        $this->paypal['verify'] = 'FAILURE';
        $order = $this->landFor($this->user(), 2, 0, ['subscription_id' => 'I-SUB1', 'subscription_status' => 'ACTIVE']);

        $this->postJson('/api/paypal/webhook', [
            'id' => 'WH-FAKE', 'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED', 'resource' => ['id' => 'I-SUB1'],
        ])->assertStatus(400);

        $this->assertSame('ACTIVE', $order->fresh()->subscription_status);
    }

    public function test_the_same_webhook_event_is_handled_once(): void
    {
        $order = $this->landFor($this->user(), 2, 0, ['subscription_id' => 'I-SUB1', 'subscription_status' => 'ACTIVE']);
        $event = ['id' => 'WH-DUP', 'event_type' => 'BILLING.SUBSCRIPTION.SUSPENDED', 'resource' => ['id' => 'I-SUB1']];

        $this->postJson('/api/paypal/webhook', $event)->assertOk();
        $this->postJson('/api/paypal/webhook', $event)->assertJson(['duplicate' => true]);

        $this->assertSame('SUSPENDED', $order->fresh()->subscription_status);
        $this->assertDatabaseCount('paypal_events', 1);
    }

    public function test_sign_in_needs_the_emailed_code(): void
    {
        Mail::fake();

        $this->postJson('/api/VerifyEmail', ['email' => 'new@example.com'])
            ->assertJson(['status' => true, 'code_sent' => true])
            ->assertJsonMissing(['access_token']);

        $code = null;
        Mail::assertSent(ValidateUser::class, function ($mail) use (&$code) {
            $code = $mail->details['button_text'];
            return true;
        });

        $this->postJson('/api/VerifyEmail', ['email' => 'new@example.com', 'code' => '000000'])->assertJson(['status' => false]);
        $res = $this->postJson('/api/VerifyEmail', ['email' => 'new@example.com', 'code' => $code]);
        $res->assertJson(['status' => true])->assertJsonStructure(['access_token', 'data' => ['user_id', 'paypal_email']]);

        // Codes work once.
        $this->postJson('/api/VerifyEmail', ['email' => 'new@example.com', 'code' => $code])->assertJson(['status' => false]);
    }

    public function test_an_email_alone_never_gives_a_token(): void
    {
        $existing = $this->user(['email' => 'owner@example.com']);
        Mail::fake();

        $this->postJson('/api/login', ['email' => 'owner@example.com'])->assertJsonMissing(['access_token']);
        $this->postJson('/api/VerifyEmail', ['email' => 'owner@example.com'])->assertJsonMissing(['access_token']);
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_payout_email_can_be_set_and_must_be_valid(): void
    {
        $user = $this->user();
        $this->actingAsUser($user)->postJson('/api/UpdateUser', ['paypal_email' => 'not-an-email'])->assertJson(['status' => false]);
        $this->actingAsUser($user)->postJson('/api/UpdateUser', ['paypal_email' => 'Me@PayPal.test'])
            ->assertJson(['status' => true, 'data' => ['paypal_email' => 'me@paypal.test']]);
        $this->assertSame('me@paypal.test', User::find($user->id)->paypal_email);
    }

    public function test_billing_routes_need_a_signed_in_user(): void
    {
        $this->postJson('/api/BuyMarketplaceOrder', ['order_id' => 1])->assertStatus(401);
        $this->postJson('/api/StartBilling', ['order_id' => 1])->assertStatus(401);
        $this->getJson('/api/MyPayments')->assertStatus(401);
    }
}
