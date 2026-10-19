<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->store = Tenant::factory()->create(['slug' => 'bella', 'name' => 'Bella']);
    $this->owner = storeUser($this->store, ['role' => UserRole::Owner, 'name' => 'Bia']);
    $this->withToken(asStore($this->store, fn () => $this->owner->createToken('t')->plainTextToken));
    $this->team = fn (string $path = ''): string => $this->storeUrl('bella', 'admin/team'.$path);
});

it('invites a new team member, who sets a password from the email', function (): void {
    $member = $this->postJson(($this->team)(), ['name' => 'Caio', 'email' => ' Caio@Bella.test ', 'role' => 'staff'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'staff')
        ->assertJsonPath('data.email', 'caio@bella.test')
        ->json('data');

    $token = null;
    $invited = asStore($this->store, fn () => User::query()->findOrFail($member['id']));
    Notification::assertSentTo($invited, TeamInvitationNotification::class, function (TeamInvitationNotification $n) use ($invited, &$token): bool {
        $mail = asStore($this->store, fn () => $n->toMail($invited));
        parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];

        return in_array('Bia convidou você para ser parte da equipe da Bella no Beauty Hub.', $mail->introLines, true);
    });

    $this->postJson($this->storeUrl('bella', 'auth/password/reset'), [
        'email' => 'caio@bella.test', 'token' => $token, 'password' => 'equipe2026', 'password_confirmation' => 'equipe2026',
    ])->assertNoContent();
    $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'caio@bella.test', 'password' => 'equipe2026'])->assertOk();
});

it('promotes an existing customer instead of creating a second account', function (): void {
    $customer = storeUser($this->store, ['email' => 'ana@bella.test']);

    $this->postJson(($this->team)(), ['name' => 'Ana', 'email' => 'ana@bella.test', 'role' => 'staff'])
        ->assertCreated()->assertJsonPath('data.id', $customer->id);
    $this->postJson(($this->team)(), ['name' => 'Ana', 'email' => 'ana@bella.test', 'role' => 'staff'])
        ->assertConflict()->assertJsonPath('error.code', 'ALREADY_IN_TEAM');
});

it('removes a member from the team, signing them out', function (): void {
    $staff = storeUser($this->store, ['role' => UserRole::Staff]);
    $staffToken = asStore($this->store, fn () => $staff->createToken('t')->plainTextToken);

    $this->deleteJson(($this->team)("/{$staff->id}"))->assertNoContent();

    expect(asStore($this->store, fn () => $staff->fresh()?->role))->toBe(UserRole::Customer);
    app('auth')->forgetGuards();
    $this->withToken($staffToken)->getJson($this->storeUrl('bella', 'admin/products'))->assertUnauthorized();
});

it('lets owners manage other owners but not their own role', function (): void {
    $this->patchJson(($this->team)("/{$this->owner->id}"), ['role' => 'staff'])
        ->assertConflict()->assertJsonPath('error.code', 'CANNOT_CHANGE_OWN_ROLE');
    $this->deleteJson(($this->team)("/{$this->owner->id}"))
        ->assertConflict()->assertJsonPath('error.code', 'CANNOT_CHANGE_OWN_ROLE');

    $second = storeUser($this->store, ['role' => UserRole::Owner]);
    $this->patchJson(($this->team)("/{$second->id}"), ['role' => 'staff'])->assertOk()->assertJsonPath('data.role', 'staff');
    $this->patchJson(($this->team)("/{$second->id}"), ['role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'owner');

    $customer = storeUser($this->store);
    $this->patchJson(($this->team)("/{$customer->id}"), ['role' => 'owner'])->assertNotFound();
});

it('is for owners only', function (): void {
    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->store, UserRole::Staff));

    $this->getJson(($this->team)())->assertForbidden();
    $this->postJson(($this->team)(), ['name' => 'X', 'email' => 'x@x.test', 'role' => 'owner'])->assertForbidden();
    $this->patchJson($this->storeUrl('bella', 'admin/store'), ['name' => 'Outra'])->assertForbidden();
    $this->getJson($this->storeUrl('bella', 'admin/store'))->assertOk();
});

it('lets owners change the store\'s name and minimum shelf life', function (): void {
    $this->patchJson($this->storeUrl('bella', 'admin/store'), ['name' => 'Bella Beauty', 'min_shelf_life_days' => 60])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bella Beauty')
        ->assertJsonPath('data.min_shelf_life_days', 60);

    $this->patchJson($this->storeUrl('bella', 'admin/store'), ['min_shelf_life_days' => 400])->assertUnprocessable();
});
