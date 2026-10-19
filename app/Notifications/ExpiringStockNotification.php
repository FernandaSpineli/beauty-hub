<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StockLot;
use App\Tenancy\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Daily note to the store's team: lots already out of sale (inside the
 * minimum shelf life, still on the shelf) and lots leaving sale soon.
 */
final class ExpiringStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** How far ahead "leaving sale soon" looks. */
    public const int WINDOW_DAYS = 30;

    /**
     * @param  Collection<int, StockLot>  $outOfSale
     * @param  Collection<int, StockLot>  $leavingSoon
     */
    public function __construct(
        public readonly Collection $outOfSale,
        public readonly Collection $leavingSoon,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = StoreMail::message('Validade dos lotes')->greeting('Bom dia!');

        if ($this->outOfSale->isNotEmpty()) {
            $mail->line('**Fora de venda** (dentro da validade mínima, ainda em estoque):');
            $this->outOfSale->each(fn (StockLot $lot) => $mail->line(self::describe($lot)));
        }

        if ($this->leavingSoon->isNotEmpty()) {
            $mail->line('**Saem de venda nos próximos '.self::WINDOW_DAYS.' dias:**');
            $this->leavingSoon->each(fn (StockLot $lot) => $mail->line(self::describe($lot)));
        }

        return $mail
            ->line('Considere uma promoção, um kit ou a baixa dos itens vencidos.')
            ->action('Ver estoque', StoreUrl::to(StoreMail::store(), 'admin/estoque'));
    }

    private static function describe(StockLot $lot): string
    {
        return "{$lot->variant->product->name} ({$lot->variant->name}), lote {$lot->lot_code}: "
            ."{$lot->quantity_on_hand} un., validade {$lot->expires_on->format('d/m/Y')}";
    }
}
