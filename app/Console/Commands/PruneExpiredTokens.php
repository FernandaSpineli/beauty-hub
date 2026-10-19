<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalAccessToken;
use App\Tenancy\EachStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sanctum's own sanctum:prune-expired runs outside any store, where
 * row-level security hides every token; this one prunes store by store.
 */
#[Signature('tokens:prune {--hours=24 : Keep tokens expired less than this many hours ago}')]
#[Description('Delete expired API tokens, store by store')]
final class PruneExpiredTokens extends Command
{
    public function handle(EachStore $stores): int
    {
        $before = now()->subHours((int) $this->option('hours'));
        $deleted = 0;

        $stores->run(function () use ($before, &$deleted): void {
            $count = PersonalAccessToken::query()->where('expires_at', '<', $before)->delete();
            $deleted += is_int($count) ? $count : 0;
        });

        $this->info("Deleted {$deleted} expired token(s).");

        return self::SUCCESS;
    }
}
