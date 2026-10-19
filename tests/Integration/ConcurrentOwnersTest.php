<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'owner', '--force' => true]);
    $this->store = Tenant::factory()->create();
    app(TenantContext::class)->set($this->store);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    DB::connection('owner')->statement('TRUNCATE tenants CASCADE');
});

it('keeps an owner when two owners demote each other at the same moment', function (): void {
    [$ana, $bia] = User::factory()->count(2)->owner()->create();
    $startAt = (string) (microtime(true) + 2.0);
    $script = base_path('tests/Integration/fixtures/demote.php');

    $running = [];
    foreach ([[$ana, $bia], [$bia, $ana]] as [$actor, $member]) {
        $process = proc_open([PHP_BINARY, $script, $this->store->id, $actor->id, $member->id, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert(is_resource($process));
        $running[] = [$process, $pipes];
    }
    $codes = [];
    foreach ($running as [$process, $pipes]) {
        $out = json_decode((string) stream_get_contents($pipes[1]), true) ?? ['ok' => false, 'code' => 'NO_OUTPUT'];
        proc_close($process);
        $codes[] = $out['ok'] ? 'ok' : $out['code'];
    }

    sort($codes);
    // The first change wins; the second finds its author no longer an owner.
    expect($codes)->toBe(['FORBIDDEN', 'ok'])
        ->and(User::query()->where('role', UserRole::Owner)->count())->toBe(1);
});
