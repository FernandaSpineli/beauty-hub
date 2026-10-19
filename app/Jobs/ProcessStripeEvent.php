<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Payments\StripeEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a stored Stripe event outside the webhook request, with retries.
 */
final class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('payments');
    }

    public function handle(StripeEventHandler $handler): void
    {
        $handler->handle($this->eventId);
    }
}
