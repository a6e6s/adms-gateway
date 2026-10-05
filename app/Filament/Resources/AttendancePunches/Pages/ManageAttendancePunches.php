<?php

namespace App\Filament\Resources\AttendancePunches\Pages;

use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendancePunches extends ManageRecords
{
    protected static string $resource = AttendancePunchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
