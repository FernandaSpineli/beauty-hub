<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\NoTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $note
 */
final class Probe extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'rls_probes';

    protected $guarded = [];
}

beforeEach(function (): void {
    createProbeTable();
    $this->context = app(TenantContext::class);
    $this->bella = Tenant::factory()->create();
    $this->glow = Tenant::factory()->create();
});

it('stamps new rows with the current tenant', function (): void {
    $probe = $this->context->run($this->bella, fn () => Probe::query()->create(['note' => 'lipstick']));

    expect($probe->tenant_id)->toBe($this->bella->id);
});

it('queries only the current tenant\'s rows, and none without a tenant', function (): void {
    $this->context->run($this->bella, fn () => Probe::query()->create(['note' => 'bella']));
    $this->context->run($this->glow, fn () => Probe::query()->create(['note' => 'glow']));

    expect($this->context->run($this->bella, fn () => Probe::query()->pluck('note')->all()))->toBe(['bella'])
        ->and(Probe::query()->count())->toBe(0);
});

it('refuses to create rows outside a tenant or for another tenant', function (): void {
    expect(fn () => Probe::query()->create(['note' => 'orphan']))->toThrow(NoTenantContext::class);

    $this->context->run($this->bella, function (): void {
        expect(fn () => Probe::query()->create(['note' => 'x', 'tenant_id' => $this->glow->id]))
            ->toThrow(NoTenantContext::class);
    });
});

it('refuses to move a row to another tenant', function (): void {
    $this->context->run($this->bella, function (): void {
        $probe = Probe::query()->create(['note' => 'serum']);
        $probe->tenant_id = $this->glow->id;

        expect(fn () => $probe->save())->toThrow(NoTenantContext::class);
    });
});

it('restores the previous tenant after running as another one', function (): void {
    $this->context->set($this->bella);
    $this->context->run($this->glow, fn () => null);

    expect($this->context->id())->toBe($this->bella->id);
    $this->context->clear();
});

it('surfaces the original database error from inside a store, not "transaction aborted"', function (): void {
    expect(fn () => DB::transaction(fn () => $this->context->run($this->bella, fn () => DB::select('SELECT * FROM no_such_table'))))
        ->toThrow(QueryException::class, 'no_such_table');

    expect($this->context->current())->toBeNull();
});
