<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewPaidOrderNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderShippedNotification;
use App\Payments\PaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePaymentGateway;

beforeEach(function (): void {
    Notification::fake();
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    app()->instance(PaymentGateway::class, new FakePaymentGateway);

    $this->store = Tenant::factory()->create(['slug' => 'bella', 'name' => 'Bella Cosméticos', 'stripe_account_id' => 'acct_b1', 'stripe_charges_enabled' => true]);
    [$this->owner, $this->staff, $this->customer] = asStore($this->store, function (): array {
        $variant = ProductVariant::factory()->create(['price_cents' => 4990]);
        $variant->product->update(['name' => 'Batom Matte']);
        $variant->update(['name' => 'Nude']);
        StockLot::factory()->create(['variant_id' => $variant->id]);
        Coupon::factory()->create(['code' => 'DEZ', 'value' => 10]);
        $this->variant = $variant;

        return [
            User::factory()->owner()->create(),
            User::factory()->staff()->create(),
            User::factory()->create(['name' => 'Ana Souza']),
        ];
    });
    // Another store's team must hear nothing.
    $this->otherOwner = storeUser(Tenant::factory()->create(), ['role' => UserRole::Owner]);

    $this->withToken(asStore($this->store, fn () => $this->customer->createToken('t')->plainTextToken));
    $this->postJson($this->storeUrl('bella', 'cart/items'), ['variant_id' => $this->variant->id, 'quantity' => 2]);
    $this->putJson($this->storeUrl('bella', 'cart/coupon'), ['code' => 'DEZ']);
    $this->orderId = $this->postJson($this->storeUrl('bella', 'orders'), ['shipping_address' => address()])->json('data.id');
});

function payByWebhook(): void
{
    test()->postJson(test()->storeUrl('bella', 'orders/'.test()->orderId.'/checkout'))->assertOk();
    $session = asStore(test()->store, fn () => Payment::query()->firstOrFail()->checkout_session_id);
    stripeEvent('checkout.session.completed', [
        'id' => $session, 'amount_total' => 8982, 'payment_status' => 'paid',
        'metadata' => ['tenant_id' => test()->store->id, 'order_id' => test()->orderId],
    ])->assertOk();
}

it('tells the customer the order was placed, in Portuguese and in the store\'s name', function (): void {
    Notification::assertSentTo($this->customer, OrderPlacedNotification::class, function (OrderPlacedNotification $n): bool {
        $mail = asStore($this->store, fn () => $n->toMail($this->customer));
        $html = (string) asStore($this->store, fn () => $mail->render());

        return $mail->subject === 'Recebemos seu pedido BH-000001 — Bella Cosméticos'
            && $mail->from === [config('mail.from.address'), 'Bella Cosméticos']
            && in_array('2 × Batom Matte (Nude) — R$ 99,80', $mail->introLines, true)
            && in_array('Desconto (DEZ): − R$ 9,98', $mail->introLines, true)
            && in_array('**Total: R$ 89,82**', $mail->introLines, true)
            && str_starts_with((string) $mail->actionUrl, 'http://bella.beautyhub.localhost:8000/pedidos/')
            && str_contains($html, 'Todos os direitos reservados')
            && str_contains($html, 'Equipe Bella Cosméticos');
    });
});

it('tells the customer and the store\'s team when the order is paid', function (): void {
    payByWebhook();

    Notification::assertSentTo($this->customer, OrderPaidNotification::class);
    Notification::assertSentTo([$this->owner, $this->staff], NewPaidOrderNotification::class);
    Notification::assertNotSentTo([$this->customer, $this->otherOwner], NewPaidOrderNotification::class);
});

it('tells the customer when the order ships, with the store\'s note', function (): void {
    payByWebhook();

    app('auth')->forgetGuards();
    $this->withToken(asStore($this->store, fn () => $this->owner->createToken('t')->plainTextToken))
        ->patchJson($this->storeUrl('bella', "admin/orders/{$this->orderId}/status"), ['status' => 'shipped', 'note' => 'Rastreio: BR123456789BR'])
        ->assertOk();

    Notification::assertSentTo($this->customer, OrderShippedNotification::class, function (OrderShippedNotification $n): bool {
        $mail = asStore($this->store, fn () => $n->toMail($this->customer));

        return in_array('Informação da loja: Rastreio: BR123456789BR', $mail->introLines, true);
    });
});

it('queues every order email', function (): void {
    foreach ([OrderPlacedNotification::class, OrderPaidNotification::class, OrderShippedNotification::class, NewPaidOrderNotification::class] as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue();
    }

    expect(asStore($this->store, fn () => Order::query()->count()))->toBe(1);
});
