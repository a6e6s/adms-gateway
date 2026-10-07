<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('persists assigned roles and grants their permissions to the user', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'attendance_operator', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'ViewAny:AttendancePunch', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user->assignRole($role);

    expect($user->fresh()->hasRole('attendance_operator'))->toBeTrue();
    expect($user->fresh()->can('ViewAny:AttendancePunch'))->toBeTrue();
    expect(User::factory()->create()->can('ViewAny:AttendancePunch'))->toBeFalse();
});
