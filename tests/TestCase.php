<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function storeUrl(string $slug, string $path): string
    {
        $central = config('tenancy.central_domain');
        assert(is_string($central));

        return 'http://'.$slug.'.'.$central.'/api/v1/'.ltrim($path, '/');
    }
}
