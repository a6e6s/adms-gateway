<?php

namespace App\Filament\Resources;

use App\Services\CompanyAccess;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

abstract class CompanyScopedResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return CompanyAccess::scope(parent::getEloquentQuery());
    }
}
