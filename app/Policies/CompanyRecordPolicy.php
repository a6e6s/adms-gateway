<?php

namespace App\Policies;

use App\Models\User;
use App\Services\CompanyAccess;
use Illuminate\Database\Eloquent\Model;

abstract class CompanyRecordPolicy
{
    protected string $subject;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('ViewAny:'.$this->subject);
    }

    public function view(User $user, Model $record): bool
    {
        return CompanyAccess::owns($user, $record) && ($user->isSuperAdmin() || $user->can('View:'.$this->subject));
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || ($user->companies()->exists() && $user->can('Create:'.$this->subject));
    }

    public function update(User $user, Model $record): bool
    {
        return CompanyAccess::owns($user, $record) && ($user->isSuperAdmin() || $user->can('Update:'.$this->subject));
    }

    public function delete(User $user, Model $record): bool
    {
        return CompanyAccess::owns($user, $record) && ($user->isSuperAdmin() || $user->can('Delete:'.$this->subject));
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
