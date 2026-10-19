<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email, sent in the store's name with a link back to the
 * store's own subdomain.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Tenant $store,
        private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $email = $notifiable->email;
        $minutes = config()->integer('auth.passwords.users.expire');

        return (new MailMessage)
            ->from(config()->string('mail.from.address'), $this->store->name)
            ->subject("Redefinição de senha — {$this->store->name}")
            ->greeting('Olá!')
            ->line("Recebemos um pedido para redefinir a senha da sua conta na {$this->store->name}.")
            ->action('Criar nova senha', StoreUrl::to($this->store, 'redefinir-senha', [
                'token' => $this->token,
                'email' => $email,
            ]))
            ->line("O link vale por {$minutes} minutos.")
            ->line('Se não foi você, ignore este e-mail: sua senha continua a mesma.')
            ->salutation("Equipe {$this->store->name}");
    }
}
