<?php

use App\Models\User;
use Filament\Panel;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['app.env' => 'production']);
});

it('allows authorized users into the admin panel in production', function (string $roleName) {
    $user = User::factory()->create();
    $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk();
})->with(['super_admin', 'panel_user']);

it('returns 403 for users without a panel access role in production', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();
});

it('redirects guests to the admin login in production', function () {
    $this->get(route('filament.admin.pages.dashboard'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('denies access to unconfigured panels even for a super admin', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    expect($user->canAccessPanel(Panel::make()->id('other')))->toBeFalse();
});
