<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use Throwable;

/**
 * Makes GET /up fail when PostgreSQL or Redis is unreachable, so the
 * container healthcheck reflects whether the app can actually serve.
 * The messages stay generic because the health page is public.
 */
final readonly class CheckDependencies
{
    public function __construct(
        private DatabaseManager $db,
        private RedisManager $redis,
    ) {}

    public function handle(DiagnosingHealth $event): void
    {
        try {
            $this->db->connection()->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException('Database unreachable.', previous: $e);
        }

        try {
            $this->redis->connection()->ping();
        } catch (Throwable $e) {
            throw new RuntimeException('Redis unreachable.', previous: $e);
        }
    }
}
