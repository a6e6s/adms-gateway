<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use App\Filament\Resources\AttendanceUploads\AttendanceUploadResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Services\Adms\DashboardQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalSummary extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('AttendancePunch') || DashboardQuery::canAccess('Device') || DashboardQuery::canAccess('AttendanceUpload');
    }

    protected function getStats(): array
    {
        $query = new DashboardQuery($this->pageFilters ?? []);
        $stats = [];

        if (DashboardQuery::canAccess('AttendancePunch')) {
            $stats[] = Stat::make(__('filament/dashboard.punches'), $query->punches()->count())
                ->description(__('filament/dashboard.selected_period'))->descriptionIcon(Heroicon::OutlinedClock)
                ->color('primary')->url(AttendancePunchResource::getUrl());
        }
        if (DashboardQuery::canAccess('Device')) {
            $stats[] = Stat::make(__('filament/dashboard.online_devices'), $query->onlineDevices()->count())
                ->description(__('filament/dashboard.online_window', ['minutes' => max(1, (int) config('services.adms.device_online_window_minutes', 5))]))
                ->descriptionIcon(Heroicon::OutlinedSignal)->color('success')->url(DeviceResource::getUrl());
        }
        if (DashboardQuery::canAccess('AttendanceUpload')) {
            $stats[] = Stat::make(__('filament/dashboard.pending_uploads'), $query->uploads()->where('status', 'pending')->count())
                ->description(__('filament/dashboard.current_state'))->descriptionIcon(Heroicon::OutlinedCloudArrowDown)
                ->color('warning')->url(AttendanceUploadResource::getUrl());
            $stats[] = Stat::make(__('filament/dashboard.upload_errors'), $query->uploads()->whereIn('status', ['failed', 'processed_with_errors'])->count())
                ->description(__('filament/dashboard.current_state'))->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')->url(AttendanceUploadResource::getUrl());
        }

        return $stats;
    }
}
