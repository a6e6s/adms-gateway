<?php

namespace App\Filament\Resources\DeviceEmployees\Pages;

use App\Filament\Resources\DeviceEmployees\DeviceEmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeviceEmployees extends ManageRecords
{
    protected static string $resource = DeviceEmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
