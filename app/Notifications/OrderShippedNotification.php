<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order, public readonly ?string $note) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = StoreMail::message("Seu pedido {$this->order->reference()} foi enviado")
            ->greeting("Olá, {$this->order->customer_name}!")
            ->line('Seu pedido saiu para entrega.');

        if ($this->note !== null && $this->note !== '') {
            $mail->line("Informação da loja: {$this->note}");
        }

        return $mail->action('Acompanhar pedido', StoreUrl::to(StoreMail::store(), "pedidos/{$this->order->id}"));
    }
}
