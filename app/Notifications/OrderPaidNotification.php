<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderPaidNotification extends Notification implements ShouldQueue
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
        $mail = StoreMail::message("Pagamento confirmado — pedido {$order->reference()}")
            ->greeting("Olá, {$order->customer_name}!")
            ->line('Recebemos o pagamento do seu pedido. Agora é com a gente: avisaremos quando ele for enviado.');

        return StoreMail::withSummary($mail, $order)
            ->action('Acompanhar pedido', StoreUrl::to(StoreMail::store(), "pedidos/{$order->id}"));
    }
}
