<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Payments\CheckoutRequest;
use App\Payments\StripeGateway;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/*
 * Checks the requests StripeGateway sends, through stripe-php's own request
 * pipeline, with an in-memory HTTP client instead of the network.
 */

beforeEach(function (): void {
    $this->http = new class implements ClientInterface
    {
        /** @var list<array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
        public array $requests = [];

        /** @var array<string, mixed> */
        public array $reply = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

            return [(string) json_encode($this->reply), 200, []];
        }
    };
    ApiRequestor::setHttpClient($this->http);
    $this->gateway = new StripeGateway(new StripeClient('sk_test_fake'));
});

afterEach(fn () => ApiRequestor::setHttpClient(CurlClient::instance()));

it('creates a destination charge session paying the store minus the fee', function (): void {
    $this->http->reply = ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'];
    $expires = Carbon::parse('2026-10-24 12:31:00');

    $session = $this->gateway->createCheckoutSession(new CheckoutRequest(
        tenantId: 'tenant-1', orderId: 'order-1', description: 'Pedido BH-000007 — Bella',
        amountCents: 9980, applicationFeeCents: 499, destinationAccountId: 'acct_bella',
        customerEmail: 'ana@example.com', successUrl: 'https://bella.test/ok', cancelUrl: 'https://bella.test/cancel',
        expiresAt: $expires, idempotencyKey: 'checkout-order-1-1',
    ));

    $request = $this->http->requests[0];
    expect($session->id)->toBe('cs_test_1')
        ->and($request['url'])->toEndWith('/v1/checkout/sessions')
        ->and($request['headers'])->toContain('Idempotency-Key: checkout-order-1-1')
        ->and($request['params']['payment_intent_data'])->toBe([
            'application_fee_amount' => 499,
            'transfer_data' => ['destination' => 'acct_bella'],
            'metadata' => ['tenant_id' => 'tenant-1', 'order_id' => 'order-1'],
        ])
        ->and($request['params']['line_items'][0]['price_data'])->toBe([
            'currency' => 'brl', 'unit_amount' => 9980, 'product_data' => ['name' => 'Pedido BH-000007 — Bella'],
        ])
        ->and($request['params']['expires_at'])->toBe($expires->getTimestamp())
        ->and($request['params']['metadata'])->toBe(['tenant_id' => 'tenant-1', 'order_id' => 'order-1']);
});

it('creates Brazilian Express accounts, once per store', function (): void {
    $this->http->reply = ['id' => 'acct_new', 'object' => 'account'];
    $store = new Tenant(['name' => 'Bella']);
    $store->id = 'tenant-1';

    expect($this->gateway->createConnectedAccount($store, 'owner@bella.test'))->toBe('acct_new');

    $request = $this->http->requests[0];
    expect($request['params'])->toMatchArray(['type' => 'express', 'country' => 'BR', 'email' => 'owner@bella.test'])
        ->and($request['headers'])->toContain('Idempotency-Key: connect-account-tenant-1');
});
