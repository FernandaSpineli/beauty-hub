<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderPlacedNotification extends Notification implements ShouldQueue
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
        $mail = StoreMail::message("Recebemos seu pedido {$order->reference()}")
            ->greeting("Olá, {$order->customer_name}!")
            ->line("Seu pedido {$order->reference()} foi criado e os produtos estão reservados para você.");

        return StoreMail::withSummary($mail, $order)
            ->line('Conclua o pagamento até '.$order->expires_at->format('d/m/Y \à\s H:i').'; depois disso a reserva é desfeita.')
            ->action('Ver pedido', StoreUrl::to(StoreMail::store(), "pedidos/{$order->id}"));
    }
}
