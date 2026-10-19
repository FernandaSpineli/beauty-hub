<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests talk to PostgreSQL with raw SQL, bypassing Eloquent and its
 * global scope, to prove that the database itself keeps tenants apart.
 */

beforeEach(function (): void {
    createProbeTable();

    $this->context = app(TenantContext::class);
    $this->bella = Tenant::factory()->create(['slug' => 'bella']);
    $this->glow = Tenant::factory()->create(['slug' => 'glow']);

    $this->context->run($this->bella, fn () => DB::insert("INSERT INTO rls_probes (tenant_id, note) VALUES (?, 'bella row')", [$this->bella->id]));
    $this->context->run($this->glow, fn () => DB::insert("INSERT INTO rls_probes (tenant_id, note) VALUES (?, 'glow row')", [$this->glow->id]));
});

it('shows each tenant only its own rows, even to raw SQL', function (): void {
    $notes = fn (): array => array_column(DB::select('SELECT note FROM rls_probes'), 'note');

    expect($this->context->run($this->bella, $notes))->toBe(['bella row'])
        ->and($this->context->run($this->glow, $notes))->toBe(['glow row']);
});

it('shows nothing when no tenant is set', function (): void {
    expect(DB::select('SELECT * FROM rls_probes'))->toBe([]);
});

it('rejects writing a row into another tenant', function (): void {
    $this->context->run($this->bella, function (): void {
        expect(fn () => DB::transaction(fn () => DB::insert(
            "INSERT INTO rls_probes (tenant_id, note) VALUES (?, 'sneaky')",
            [$this->glow->id],
        )))->toThrow(QueryException::class, 'row-level security');
    });
});

it('cannot update or delete another tenant\'s rows', function (): void {
    $this->context->run($this->bella, function (): void {
        expect(DB::update("UPDATE rls_probes SET note = 'changed'"))->toBe(1)
            ->and(DB::delete('DELETE FROM rls_probes'))->toBe(1);
    });

    expect($this->context->run($this->glow, fn () => DB::select('SELECT note FROM rls_probes')))
        ->toHaveCount(1);
});

it('cannot move a row to another tenant', function (): void {
    $this->context->run($this->bella, function (): void {
        expect(fn () => DB::transaction(fn () => DB::update(
            'UPDATE rls_probes SET tenant_id = ?',
            [$this->glow->id],
        )))->toThrow(QueryException::class, 'row-level security');
    });
});
