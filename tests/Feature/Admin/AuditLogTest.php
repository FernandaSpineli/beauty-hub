<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\FeatureTestCase;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    $this->product = asStore($this->store, fn () => Product::factory()->create(['name' => 'Batom', 'status' => 'draft']));
    $this->owner = storeUser($this->store, ['role' => UserRole::Owner, 'name' => 'Bia']);
    $this->staff = storeUser($this->store, ['role' => UserRole::Staff, 'name' => 'Caio']);
});

function actAs(User $user): FeatureTestCase
{
    app('auth')->forgetGuards();

    return test()->withToken(asStore(test()->store, fn () => $user->createToken('t')->plainTextToken));
}

function auditLog(): TestResponse
{
    return actAs(test()->owner)->getJson(test()->storeUrl('bella', 'admin/audit-log'));
}

it('records who changed what, from what to what', function (): void {
    actAs($this->staff)->patchJson($this->storeUrl('bella', "admin/products/{$this->product->id}"), ['status' => 'active', 'name' => 'Batom Matte'])
        ->assertOk();

    auditLog()->assertOk()
        ->assertJsonPath('data.0.action', 'updated')
        ->assertJsonPath('data.0.subject', ['type' => 'product', 'id' => $this->product->id])
        ->assertJsonPath('data.0.changes.status', ['draft', 'active'])
        ->assertJsonPath('data.0.changes.name', ['Batom', 'Batom Matte'])
        ->assertJsonPath('data.0.by.name', 'Caio');
});

it('records team changes without ever logging a password', function (): void {
    actAs($this->owner)->postJson($this->storeUrl('bella', 'admin/team'), ['name' => 'Duda', 'email' => 'duda@bella.test', 'role' => 'staff'])
        ->assertCreated();

    $entry = asStore($this->store, fn () => AuditLog::query()->where('subject_type', 'user')->firstOrFail());
    expect($entry->action)->toBe('created')
        ->and($entry->changes['role'])->toBe([null, 'staff'])
        ->and($entry->changes)->not->toHaveKey('password');
});

it('leaves customers\' own actions out', function (): void {
    $this->postJson($this->storeUrl('bella', 'auth/register'), [
        'name' => 'Ana', 'email' => 'ana@x.test', 'password' => 'batom2026', 'password_confirmation' => 'batom2026',
    ])->assertCreated();

    expect(asStore($this->store, fn () => AuditLog::query()->count()))->toBe(0);
});

it('is visible to owners only, and only for their store', function (): void {
    actAs($this->staff)->getJson($this->storeUrl('bella', 'admin/audit-log'))->assertForbidden();

    actAs($this->owner)->patchJson($this->storeUrl('bella', 'admin/store'), ['min_shelf_life_days' => 45])->assertOk();
    auditLog()->assertJsonPath('data.0.subject.type', 'tenant')->assertJsonPath('data.0.changes.min_shelf_life_days', [30, 45]);

    $glow = Tenant::factory()->create(['slug' => 'glow']);
    $glowOwner = storeUser($glow, ['role' => UserRole::Owner]);
    app('auth')->forgetGuards();
    $this->withToken(asStore($glow, fn () => $glowOwner->createToken('t')->plainTextToken))
        ->getJson($this->storeUrl('glow', 'admin/audit-log'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
