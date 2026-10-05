<?php

namespace App\Filament\Resources\AttendanceUploads\Pages;

use App\Filament\Resources\AttendanceUploads\AttendanceUploadResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceUploads extends ManageRecords
{
    protected static string $resource = AttendanceUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
