<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DeviceCommand;
use App\Models\DeviceEmployee;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyAccess
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isSuperAdmin()) {
            return $query;
        }
        $companyIds = $user->companies()->select('companies.id');
        $model = $query->getModel();
        if ($model instanceof Company) {
            return $query->whereIn($model->qualifyColumn('id'), $companyIds);
        }
        if ($model instanceof DeviceCommand || $model instanceof DeviceEmployee) {
            return $query->whereHas('device', fn (Builder $device): Builder => $device->whereIn('company_id', $companyIds));
        }

        return $query->whereIn($model->qualifyColumn('company_id'), $companyIds);
    }

    /** @param class-string<Model> $modelClass */
    public static function validationRule(string $modelClass): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($modelClass): void {
            if (! self::scope($modelClass::query())->whereKey($value)->exists()) {
                $fail(__('filament/resources/users.errors.company_access'));
            }
        };
    }

    public static function owns(User $user, Model $record): bool
    {
        if ($record instanceof Company) {
            return $user->canAccessCompany($record->getKey());
        }
        if ($record instanceof DeviceCommand || $record instanceof DeviceEmployee) {
            $companyId = $record->device?->getAttribute('company_id');

            return is_int($companyId) && $user->canAccessCompany($companyId);
        }

        $companyId = $record->getAttribute('company_id');

        return is_int($companyId) && $user->canAccessCompany($companyId);
    }
}
