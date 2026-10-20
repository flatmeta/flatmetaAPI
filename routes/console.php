<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flatmeta:make-admin {email} {--revoke}', function ($email) {
    $user = \App\Models\User::where('email', strtolower(trim($email)))->first();
    if (!$user) {
        $this->error("No user with email {$email}");
        return 1;
    }
    $user->is_admin = !$this->option('revoke');
    $user->save();
    $this->info(($user->is_admin ? 'Granted' : 'Revoked') . " CMS access for {$user->email}");
})->purpose('Grant (or --revoke) CMS admin access for a user');

Artisan::command('paypal:setup', function () {
    $paypal = app(\App\Services\PayPal::class);
    $this->info('PayPal mode: ' . $paypal->mode());
    $product = $paypal->createProduct('FlatMeta land', 'Squares of land on the FlatMeta map', rtrim(config('app.frontend_url'), '/'));
    $plan = $paypal->createPlan($product['id'], 'FlatMeta land — per square per month', \App\Services\Billing::PRICE_PER_TILE_MONTH);
    $this->info("Product: {$product['id']}");
    $this->info("Plan:    {$plan['id']}");
    $this->line("Add this to .env:  PAYPAL_PLAN_ID={$plan['id']}");
})->purpose('Create the PayPal product and monthly per-square plan (run once per mode: sandbox, then live)');

Artisan::command('billing:enforce', function () {
    $stats = app(\App\Services\Billing::class)->enforce();
    foreach ($stats as $what => $count) {
        $this->line(str_pad($what, 24) . $count);
    }
})->purpose('Release unpaid reservations and land whose rent has lapsed');

Artisan::command('payouts:retry {--id=}', function () {
    $billing = app(\App\Services\Billing::class);
    $query = \App\Models\Payment::where('kind', \App\Models\Payment::KIND_PAYOUT)->whereIn('status', ['failed', 'unclaimed']);
    if ($this->option('id')) {
        $query->where('id', (int) $this->option('id'));
    }
    // Scheduled runs give up after 5 attempts; those need a look in the CMS (e.g. wrong PayPal email).
    $payouts = $query->get()->filter(fn ($p) => $this->option('id') || (int) ($p->meta['attempts'] ?? 0) < 5);
    foreach ($payouts as $payout) {
        $sale = \App\Models\Payment::find($payout->related_payment_id);
        $result = $sale ? $billing->payoutSeller($sale) : null;
        $this->line("Payout #{$payout->id}: " . ($result->status ?? 'sale missing') . (isset($result->meta['error']) && $result->meta['error'] ? ' — ' . $result->meta['error'] : ''));
    }
})->purpose('Retry failed or unclaimed seller payouts');
