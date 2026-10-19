<?php

declare(strict_types=1);

it('reports up when PostgreSQL and Redis are reachable', function (): void {
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
});

// In production debug is off, so failures answer "down" instead of a trace.
beforeEach(fn () => config(['app.debug' => false]));

it('reports down when PostgreSQL is unreachable', function (): void {
    config(['database.connections.unreachable' => [
        ...config('database.connections.pgsql'),
        'port' => 1,
    ]]);
    config(['database.default' => 'unreachable']);

    $response = $this->getJson('/up');

    // Restore it so RefreshDatabase can roll back its transaction.
    config(['database.default' => 'pgsql']);
    $response->assertServerError()->assertExactJson(['status' => 'down']);
});

it('reports down when Redis is unreachable', function (): void {
    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    $this->getJson('/up')->assertServerError()->assertExactJson(['status' => 'down']);
});
