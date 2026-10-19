<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-queues stored Stripe events that were never processed (e.g. Redis was
 * down between storing the event and queueing its job). Processing is
 * idempotent, so a duplicate job is harmless.
 */
#[Signature('stripe:retry-events {--older-than=5 : Minutes since the event was received}')]
#[Description('Re-queue Stripe events that were stored but never processed')]
final class RetryStripeEvents extends Command
{
    public function handle(): int
    {
        $events = StripeEvent::query()
            ->whereNull('processed_at')
            ->where('received_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->orderBy('received_at')
            ->limit(500)
            ->get(['id']);

        foreach ($events as $event) {
            ProcessStripeEvent::dispatch($event->id);
        }
        $this->info("Re-queued {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
