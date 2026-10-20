<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the PayPal REST APIs FlatMeta uses:
 * - Subscriptions (monthly rent for squares beyond the free 100)
 * - Orders (one-time marketplace purchases)
 * - Payouts (sending sellers their share)
 * - Webhook signature verification
 *
 * Everything goes through Laravel's HTTP client so tests can fake PayPal with Http::fake().
 */
class PayPal
{
    public function mode(): string
    {
        return config('services.paypal.mode') === 'live' ? 'live' : 'sandbox';
    }

    public function baseUrl(): string
    {
        return $this->mode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function currency(): string
    {
        return config('services.paypal.currency', 'USD');
    }

    public function isConfigured(): bool
    {
        return config('services.paypal.client_id') && config('services.paypal.secret');
    }

    // -------------------------------------------------------------------------
    // Subscriptions
    // -------------------------------------------------------------------------

    /**
     * @return array{id: string, approve_url: string}
     */
    public function createSubscription(array $args): array
    {
        $body = [
            'plan_id' => $args['plan_id'] ?? config('services.paypal.plan_id'),
            'quantity' => (string) max(1, (int) $args['quantity']),
            'custom_id' => (string) $args['custom_id'],
            'application_context' => [
                'brand_name' => config('app.name', 'FlatMeta'),
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $args['return_url'],
                'cancel_url' => $args['cancel_url'],
            ],
        ];
        if (!empty($args['start_time'])) {
            $body['start_time'] = $args['start_time'];
        }
        if (!empty($args['email'])) {
            $body['subscriber'] = ['email_address' => $args['email']];
        }

        $res = $this->json($this->client()->post('/v1/billing/subscriptions', $body), 'create subscription');
        return ['id' => $res['id'], 'approve_url' => $this->link($res, 'approve')];
    }

    public function getSubscription(string $id): array
    {
        return $this->json($this->client()->get('/v1/billing/subscriptions/' . urlencode($id)), 'get subscription');
    }

    public function cancelSubscription(string $id, string $reason): void
    {
        $res = $this->client()->post('/v1/billing/subscriptions/' . urlencode($id) . '/cancel', [
            'reason' => mb_substr($reason, 0, 120),
        ]);
        // 422 = already cancelled/expired, which is fine.
        if (!$res->successful() && $res->status() !== 422) {
            $this->fail($res, 'cancel subscription');
        }
    }

    public function createProduct(string $name, string $description, string $homeUrl): array
    {
        return $this->json($this->client()->post('/v1/catalogs/products', [
            'name' => $name,
            'description' => $description,
            'type' => 'SERVICE',
            'category' => 'SOFTWARE',
            'home_url' => $homeUrl,
        ]), 'create product');
    }

    /** Monthly plan priced per square; the subscription quantity is the number of billable squares. */
    public function createPlan(string $productId, string $name, string $pricePerUnit): array
    {
        return $this->json($this->client()->withHeaders(['Prefer' => 'return=representation'])->post('/v1/billing/plans', [
            'product_id' => $productId,
            'name' => $name,
            'description' => 'FlatMeta land: monthly price per square beyond the free quota',
            'status' => 'ACTIVE',
            'quantity_supported' => true,
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => $pricePerUnit, 'currency_code' => $this->currency()]],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'setup_fee_failure_action' => 'CANCEL',
                'payment_failure_threshold' => 2,
            ],
        ]), 'create plan');
    }

    // -------------------------------------------------------------------------
    // One-time orders (marketplace)
    // -------------------------------------------------------------------------

    /**
     * @return array{id: string, approve_url: string}
     */
    public function createOrder(string $amount, string $description, string $customId, string $returnUrl, string $cancelUrl): array
    {
        $res = $this->json($this->client()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $customId,
                'custom_id' => $customId,
                'description' => mb_substr($description, 0, 127),
                'amount' => ['currency_code' => $this->currency(), 'value' => $amount],
            ]],
            'application_context' => [
                'brand_name' => config('app.name', 'FlatMeta'),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]), 'create order');
        return ['id' => $res['id'], 'approve_url' => $this->link($res, 'approve')];
    }

    public function getOrder(string $id): array
    {
        return $this->json($this->client()->get('/v2/checkout/orders/' . urlencode($id)), 'get order');
    }

    /**
     * Captures an approved order. Returns the order as PayPal reports it after capture;
     * an order that was already captured is returned as-is.
     */
    public function captureOrder(string $id): array
    {
        $res = $this->client()
            ->withHeaders(['PayPal-Request-Id' => 'capture-' . $id])
            ->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/' . urlencode($id) . '/capture');
        if ($res->status() === 422 && str_contains((string) $res->body(), 'ORDER_ALREADY_CAPTURED')) {
            return $this->getOrder($id);
        }
        return $this->json($res, 'capture order');
    }

    public function refundCapture(string $captureId, string $note): array
    {
        return $this->json($this->client()
            ->withHeaders(['PayPal-Request-Id' => 'refund-' . $captureId])
            ->post('/v2/payments/captures/' . urlencode($captureId) . '/refund', ['note_to_payer' => mb_substr($note, 0, 255)]), 'refund');
    }

    /** First completed capture in an order, or null. */
    public static function completedCapture(array $order): ?array
    {
        foreach ($order['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                if (($capture['status'] ?? '') === 'COMPLETED') {
                    return $capture;
                }
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Payouts
    // -------------------------------------------------------------------------

    /**
     * Sends money to a PayPal account by email.
     * @return array{batch_id: string, status: string}
     */
    public function createPayout(string $email, string $amount, string $senderItemId, string $note): array
    {
        $res = $this->json($this->client()->post('/v1/payments/payouts', [
            'sender_batch_header' => [
                'sender_batch_id' => $senderItemId,
                'email_subject' => 'You sold land on FlatMeta',
                'email_message' => $note,
            ],
            'items' => [[
                'recipient_type' => 'EMAIL',
                'receiver' => $email,
                'amount' => ['value' => $amount, 'currency' => $this->currency()],
                'note' => $note,
                'sender_item_id' => $senderItemId,
            ]],
        ]), 'create payout');
        return [
            'batch_id' => $res['batch_header']['payout_batch_id'] ?? '',
            'status' => $res['batch_header']['batch_status'] ?? 'PENDING',
        ];
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /** Asks PayPal whether a webhook delivery is genuine. */
    public function verifyWebhook(array $headers, array $event): bool
    {
        $webhookId = config('services.paypal.webhook_id');
        if (!$webhookId) {
            return false;
        }
        $h = fn ($name) => $headers[strtolower($name)][0] ?? ($headers[$name][0] ?? null);
        $res = $this->client()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h('PAYPAL-AUTH-ALGO'),
            'cert_url' => $h('PAYPAL-CERT-URL'),
            'transmission_id' => $h('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $h('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $h('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);
        return $res->successful() && ($res->json('verification_status') === 'SUCCESS');
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    protected function accessToken(): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('PayPal is not configured (PAYPAL_CLIENT_ID / PAYPAL_SECRET).');
        }
        $key = 'paypal_token_' . $this->mode() . '_' . sha1((string) config('services.paypal.client_id'));
        return Cache::remember($key, now()->addMinutes(30), function () {
            $res = Http::baseUrl($this->baseUrl())
                ->asForm()
                ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
                ->timeout(20)
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (!$res->successful() || !$res->json('access_token')) {
                throw new RuntimeException('PayPal sign-in failed (' . $res->status() . '). Check PAYPAL_CLIENT_ID / PAYPAL_SECRET and PAYPAL_MODE.');
            }
            return $res->json('access_token');
        });
    }

    protected function json(Response $res, string $what): array
    {
        if (!$res->successful()) {
            $this->fail($res, $what);
        }
        return $res->json() ?? [];
    }

    protected function fail(Response $res, string $what): void
    {
        $detail = $res->json('details.0.issue') ?? $res->json('name') ?? $res->json('error') ?? 'HTTP ' . $res->status();
        throw new RuntimeException("PayPal could not {$what}: {$detail}");
    }

    protected function link(array $res, string $rel): string
    {
        foreach ($res['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === $rel || ($rel === 'approve' && ($link['rel'] ?? '') === 'payer-action')) {
                return $link['href'];
            }
        }
        throw new RuntimeException("PayPal did not return an {$rel} link.");
    }
}
