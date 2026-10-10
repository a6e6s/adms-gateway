<?php

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Company;
use App\Models\User;
use App\Services\ManageUserAccess;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('creates a companyless super admin through the users resource', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    Livewire::test(ManageUsers::class)->callAction('create', data: [
        'name' => 'New administrator', 'email' => 'new-admin@example.test',
        'password' => 'Test123!', 'company_ids' => [], 'role_ids' => [],
    ])->assertHasNoFormErrors();
    $user = User::query()->where('email', 'new-admin@example.test')->firstOrFail();
    expect($user->isSuperAdmin())->toBeTrue();
    expect(Hash::check('Test123!', $user->password))->toBeTrue();
    expect($user->companies)->toHaveCount(0);
});

it('creates a user assigned to multiple companies with selected roles', function () {
    $actor = User::factory()->superAdmin()->create();
    $companies = Company::factory()->count(2)->create();
    $role = Role::create(['name' => 'attendance_manager', 'guard_name' => 'web']);
    $this->actingAs($actor);
    Livewire::test(ManageUsers::class)->callAction('create', data: [
        'name' => 'Company manager', 'email' => 'manager@example.test',
        'password' => 'StrongPassword123!', 'company_ids' => $companies->modelKeys(), 'role_ids' => [$role->id],
    ])->assertHasNoFormErrors();
    $user = User::query()->where('email', 'manager@example.test')->firstOrFail();
    expect($user->companies->modelKeys())->toEqual($companies->modelKeys());
    expect($user->hasAllRoles(['panel_user', 'attendance_manager']))->toBeTrue();
    expect($user->isSuperAdmin())->toBeFalse();
});

it('keeps an existing password when editing and synchronizes company access transitions', function () {
    $actor = User::factory()->superAdmin()->create();
    $user = User::factory()->superAdmin()->create();
    $company = Company::factory()->create();
    $password = $user->password;
    $this->actingAs($actor);
    Livewire::test(ManageUsers::class)->callAction(TestAction::make('edit')->table($user), data: [
        'name' => 'Updated manager', 'email' => $user->email, 'password' => '', 'company_ids' => [$company->id], 'role_ids' => [],
    ])->assertHasNoFormErrors();
    $user->refresh();
    expect($user->password)->toBe($password);
    expect($user->isSuperAdmin())->toBeFalse();
    expect($user->hasRole('super_admin'))->toBeFalse();
    expect($user->companies->modelKeys())->toBe([$company->id]);
    app(ManageUserAccess::class)->save($actor, $user, ['name' => $user->name, 'email' => $user->email, 'company_ids' => [], 'role_ids' => []]);
    expect($user->refresh()->isSuperAdmin())->toBeTrue();
});

it('prevents self demotion without changing assignments or roles', function () {
    $actor = User::factory()->superAdmin()->create();
    $company = Company::factory()->create();
    $this->actingAs($actor);
    Livewire::test(ManageUsers::class)->callAction(TestAction::make('edit')->table($actor), data: [
        'name' => $actor->name, 'email' => $actor->email, 'password' => '', 'company_ids' => [$company->id], 'role_ids' => [],
    ])->assertHasFormErrors(['company_ids']);
    expect($actor->refresh()->isSuperAdmin())->toBeTrue();
    $this->assertDatabaseCount('company_user', 0);
});

it('denies users and role management to company users even with explicit permissions', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('panel_user', 'web'));
    $user->companies()->attach(Company::factory()->create());
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));
    $this->actingAs($user)->get(route('filament.admin.resources.users.index'))->assertForbidden();
    $this->get(RoleResource::getUrl())->assertForbidden();
    expect(fn () => app(ManageUserAccess::class)->save($user, null, [
        'name' => 'Escalation', 'email' => 'escalation@example.test', 'password' => 'StrongPassword123!', 'company_ids' => [],
    ]))->toThrow(AuthorizationException::class);
    $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
});

it('rejects duplicate email nonexistent companies and privileged role injection', function (array $overrides) {
    $actor = User::factory()->superAdmin()->create(['email' => 'existing@example.test']);
    $superRole = Role::findByName('super_admin', 'web');
    $data = ['name' => 'Invalid', 'email' => 'invalid@example.test', 'password' => 'StrongPassword123!', 'company_ids' => [], 'role_ids' => []];
    if (isset($overrides['privileged_role'])) {
        $overrides = ['role_ids' => [$superRole->id]];
    }
    expect(fn () => app(ManageUserAccess::class)->save($actor, null, array_replace($data, $overrides)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('users', 1);
})->with([
    'duplicate email' => [['email' => 'existing@example.test']],
    'nonexistent company' => [['company_ids' => [999999]]],
    'privileged role' => [['privileged_role' => true]],
    'seven character password' => [['password' => 'Test123']],
]);

it('protects the current administrator from deletion and deletes another user with their memberships', function () {
    $actor = User::factory()->superAdmin()->create();
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());
    expect(fn () => app(ManageUserAccess::class)->delete($actor, $actor))->toThrow(AuthorizationException::class);
    expect(app(ManageUserAccess::class)->delete($actor, $user))->toBeTrue();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseCount('company_user', 0);
});

it('prevents renaming or deleting the built in access roles', function () {
    $actor = User::factory()->superAdmin()->create();
    $panelRole = Role::findOrCreate('panel_user', 'web');
    foreach ([$panelRole, Role::findByName('super_admin', 'web')] as $role) {
        expect(Gate::forUser($actor)->allows('update', $role))->toBeFalse();
        expect(Gate::forUser($actor)->allows('delete', $role))->toBeFalse();
    }
});
