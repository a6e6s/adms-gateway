<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CompanyPolicy extends CompanyRecordPolicy
{
    protected string $subject = 'Company';

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Model $record): bool
    {
        return $record instanceof Company && parent::delete($user, $record) && ! $record->users()->exists();
    }
}
