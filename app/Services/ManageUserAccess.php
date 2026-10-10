<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ManageUserAccess
{
    /** @param array<string, mixed> $data */
    public function save(User $actor, ?User $record, array $data): User
    {
        Gate::forUser($actor)->authorize($record === null ? 'create' : 'update', $record ?? User::class);
        $superRole = config('filament-shield.super_admin.name');
        $panelRole = config('filament-shield.panel_user.name');
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($record)],
            'password' => [$record === null ? 'required' : 'nullable', 'string', 'min:8'],
            'company_ids' => ['present', 'array'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')->whereNotIn('name', [$superRole, $panelRole])],
        ])->validate();

        return DB::transaction(function () use ($actor, $record, $data, $superRole, $panelRole): User {
            User::role($superRole)->orderBy('id')->lockForUpdate()->get();
            $actor = User::query()->findOrFail($actor->id);
            abort_unless($actor->isSuperAdmin(), 403);
            $user = $record === null ? new User : User::query()->lockForUpdate()->findOrFail($record->id);
            if ($actor->is($user) && $data['company_ids'] !== []) {
                throw ValidationException::withMessages(['company_ids' => __('filament/resources/users.errors.self_demote')]);
            }
            if ($user->exists && $user->isSuperAdmin() && $data['company_ids'] !== []
                && ! User::role($superRole)->whereDoesntHave('companies')->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['company_ids' => __('filament/resources/users.errors.last_admin')]);
            }
            $attributes = ['name' => $data['name'], 'email' => $data['email']];
            if (filled($data['password'] ?? null)) {
                $attributes['password'] = $data['password'];
            }
            if ($user->exists && $user->email !== $data['email']) {
                $user->email_verified_at = null;
            }
            $user->fill($attributes)->save();
            $user->companies()->sync($data['company_ids']);
            $automaticRole = $data['company_ids'] === [] ? $superRole : $panelRole;
            Role::findOrCreate($automaticRole, 'web');
            $roles = $data['company_ids'] === [] ? [] : Role::query()->whereIn('id', $data['role_ids'] ?? [])->pluck('name')->all();
            $roles[] = $automaticRole;
            $user->syncRoles($roles);
            $user->unsetRelation('companies');

            return $user;
        });
    }

    public function delete(User $actor, User $record): bool
    {
        return DB::transaction(function () use ($actor, $record): bool {
            User::role(config('filament-shield.super_admin.name'))->orderBy('id')->lockForUpdate()->get();
            $actor = User::query()->findOrFail($actor->id);
            $record = User::query()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('delete', $record);

            return $record->delete();
        });
    }
}
