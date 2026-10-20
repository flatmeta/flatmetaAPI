<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;

class PaymentsAdminTest extends BillingTestCase
{
    public function test_admins_see_payments_and_can_retry_a_failed_payout(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $seller = $this->user(['paypal_email' => 'seller@example.com']);
        $sale = Payment::create(['kind' => 'sale', 'status' => 'completed', 'user_id' => $admin->id, 'amount' => 10,
            'meta' => ['seller_id' => $seller->id, 'seller_net' => 9]]);
        $payout = Payment::create(['kind' => 'payout', 'status' => 'failed', 'user_id' => $seller->id, 'amount' => 9,
            'related_payment_id' => $sale->id, 'meta' => ['error' => 'Payouts not enabled']]);

        $this->actingAs($admin)->get('/Payments')->assertOk()->assertSee('Payouts not enabled')->assertSee('need attention');

        $this->actingAs($admin)->post('/Payments/' . $payout->id . '/retry')->assertRedirect('/Payments');
        $this->assertSame('sent', $payout->fresh()->status);
    }

    public function test_regular_users_cannot_open_the_payments_page(): void
    {
        $this->actingAs($this->user())->get('/Payments')->assertRedirect()->assertSessionHasErrors();
    }
}
