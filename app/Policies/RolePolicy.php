<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $authUser->isSuperAdmin() && ! in_array($role->name, [config('filament-shield.super_admin.name'), config('filament-shield.panel_user.name')], true);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->isSuperAdmin() && ! in_array($role->name, [config('filament-shield.super_admin.name'), config('filament-shield.panel_user.name')], true);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        return $this->delete($authUser, $role);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->isSuperAdmin();
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->isSuperAdmin();
    }
}
