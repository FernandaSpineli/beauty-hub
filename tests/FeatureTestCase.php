<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Each test runs inside a transaction that is rolled back afterwards.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as defaultMigrateFreshUsing;
    }

    /**
     * Migrations run as the schema owner; tests then talk to the database as
     * the application role, so row-level security applies just as in
     * production.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [...$this->defaultMigrateFreshUsing(), '--database' => 'owner'];
    }
}
