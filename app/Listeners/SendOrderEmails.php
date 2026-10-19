<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\OrderPaid;
use App\Events\OrderPlaced;
use App\Events\OrderShipped;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewPaidOrderNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderShippedNotification;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about what. The emails themselves are queued. Each handler runs
 * as the order's store, whichever context fired the event.
 */
final readonly class SendOrderEmails
{
    public function __construct(private TenantContext $context) {}

    public function placed(OrderPlaced $event): void
    {
        $this->asStoreOf($event->order, fn () => $event->order->user->notify(new OrderPlacedNotification($event->order)));
    }

    public function paid(OrderPaid $event): void
    {
        $this->asStoreOf($event->order, function () use ($event): void {
            $event->order->user->notify(new OrderPaidNotification($event->order));

            $team = User::query()->whereIn('role', [UserRole::Owner, UserRole::Staff])->get();
            Notification::send($team, new NewPaidOrderNotification($event->order));
        });
    }

    public function shipped(OrderShipped $event): void
    {
        $this->asStoreOf($event->order, fn () => $event->order->user->notify(new OrderShippedNotification($event->order, $event->note)));
    }

    private function asStoreOf(Order $order, Closure $send): void
    {
        $store = Tenant::query()->findOrFail($order->tenant_id);

        $this->context->run($store, $send);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'placed',
            OrderPaid::class => 'paid',
            OrderShipped::class => 'shipped',
        ];
    }
}
