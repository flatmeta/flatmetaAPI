<?php

namespace Tests;

use Illuminate\Support\Facades\Http;

/**
 * Fakes the PayPal REST API. Each test sets the responses it needs; every call
 * is recorded so tests can assert what was sent to PayPal.
 */
trait FakesPayPal
{
    protected array $paypal = [];

    protected function fakePayPal(array $overrides = []): void
    {
        $this->paypal = array_merge([
            'subscription' => [
                'id' => 'I-SUB1',
                'status' => 'ACTIVE',
                'plan_id' => 'P-TESTPLAN',
                'quantity' => '5',
                'custom_id' => 'order:1',
                'billing_info' => ['next_billing_time' => now()->addMonth()->toIso8601String()],
            ],
            'capture' => null,       // set per test: full captured order response
            'verify' => 'SUCCESS',
            'payout_status' => 200,
        ], $overrides);

        $base = 'https://api-m.sandbox.paypal.com';
        Http::fake(function ($request) use ($base) {
            $url = $request->url();
            $path = substr($url, strlen($base));
            $method = $request->method();

            if (str_ends_with($path, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'A21-test', 'expires_in' => 32400]);
            }
            if ($method === 'POST' && $path === '/v1/billing/subscriptions') {
                $id = $this->paypal['subscription']['id'];
                return Http::response(['id' => $id, 'status' => 'APPROVAL_PENDING', 'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-' . $id],
                ]], 201);
            }
            if ($method === 'GET' && str_starts_with($path, '/v1/billing/subscriptions/')) {
                return Http::response($this->paypal['subscription']);
            }
            if ($method === 'POST' && str_ends_with($path, '/cancel')) {
                return Http::response(null, 204);
            }
            if ($method === 'POST' && $path === '/v2/checkout/orders') {
                return Http::response(['id' => 'ORDER-1', 'status' => 'CREATED', 'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
                ]], 201);
            }
            if ($method === 'POST' && str_ends_with($path, '/capture')) {
                return Http::response($this->paypal['capture'], 201);
            }
            if ($method === 'POST' && str_contains($path, '/v2/payments/captures/') && str_ends_with($path, '/refund')) {
                return Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201);
            }
            if ($method === 'POST' && $path === '/v1/payments/payouts') {
                if ($this->paypal['payout_status'] !== 200) {
                    return Http::response(['name' => 'AUTHORIZATION_ERROR'], $this->paypal['payout_status']);
                }
                return Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-1', 'batch_status' => 'PENDING']], 201);
            }
            if ($method === 'POST' && $path === '/v1/notifications/verify-webhook-signature') {
                return Http::response(['verification_status' => $this->paypal['verify']]);
            }
            return Http::response(['name' => 'NOT_FAKED', 'path' => $path], 500);
        });
    }

    protected function capturedOrder(string $amount, string $status = 'COMPLETED'): array
    {
        return [
            'id' => 'ORDER-1',
            'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'PAYER_ACTION_REQUIRED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE-1',
                    'status' => $status,
                    'amount' => ['currency_code' => 'USD', 'value' => $amount],
                ]]],
            ]],
        ];
    }

    protected function assertPayPalCalled(string $method, string $pathContains): void
    {
        Http::assertSent(fn ($r) => $r->method() === $method && str_contains($r->url(), $pathContains));
    }

    protected function assertPayPalNotCalled(string $method, string $pathContains): void
    {
        Http::assertNotSent(fn ($r) => $r->method() === $method && str_contains($r->url(), $pathContains));
    }
}
