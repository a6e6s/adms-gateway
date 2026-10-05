<?php

namespace App\Filament\Resources\DeviceCommands\Pages;

use App\Filament\Resources\DeviceCommands\DeviceCommandResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDeviceCommands extends ManageRecords
{
    protected static string $resource = DeviceCommandResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
