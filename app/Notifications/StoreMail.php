<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Shared pieces of the emails a store sends: its name as the sender and an
 * order summary.
 */
final class StoreMail
{
    public static function store(): Tenant
    {
        return app(TenantContext::class)->require();
    }

    public static function message(string $subject): MailMessage
    {
        $store = self::store();

        return (new MailMessage)
            ->from(config()->string('mail.from.address'), $store->name)
            ->subject("{$subject} — {$store->name}")
            ->salutation("Equipe {$store->name}");
    }

    public static function withSummary(MailMessage $mail, Order $order): MailMessage
    {
        foreach ($order->items as $item) {
            $mail->line(self::itemLine($item));
        }

        if ($order->discount_cents > 0) {
            $mail->line("Desconto ({$order->coupon_code}): − ".Money::brl($order->discount_cents));
        }

        return $mail->line('**Total: '.Money::brl($order->total_cents).'**');
    }

    private static function itemLine(OrderItem $item): string
    {
        return "{$item->quantity} × {$item->product_name} ({$item->variant_name}) — ".Money::brl($item->line_total_cents);
    }
}
