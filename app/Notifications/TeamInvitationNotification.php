<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Invites someone to the store's team. The link is a password reset link,
 * valid for the broker's lifetime, so the invitee picks their own password.
 */
final class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly string $invitedBy,
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
        $store = StoreMail::store();
        $role = $notifiable->role->value === 'owner' ? 'dona/dono' : 'parte da equipe';

        return StoreMail::message('Convite para a equipe')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("{$this->invitedBy} convidou você para ser {$role} da {$store->name} no Beauty Hub.")
            ->action('Criar minha senha', StoreUrl::to($store, 'redefinir-senha', [
                'token' => $this->token,
                'email' => $notifiable->email,
                'convite' => '1',
            ]))
            ->line('O link vale por '.config()->integer('auth.passwords.users.expire').' minutos. Depois disso, use "Esqueci minha senha".');
    }
}
