<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the store's team that an order is paid and can be prepared.
 */
final class NewPaidOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing('items');
        $mail = StoreMail::message("Novo pedido pago: {$order->reference()} (".Money::brl($order->total_cents).')')
            ->greeting('Novo pedido para separar!')
            ->line("Cliente: {$order->customer_name} <{$order->customer_email}>");

        return StoreMail::withSummary($mail, $order)
            ->action('Abrir no painel', StoreUrl::to(StoreMail::store(), "admin/pedidos/{$order->id}"));
    }
}
