<?php

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class RecentAttendance extends TableWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('AttendancePunch');
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('filament/dashboard.recent_attendance'))
            ->description(__('filament/dashboard.attendance_description'))
            ->query(fn () => (new DashboardQuery($this->pageFilters ?? []))->punches()
                ->select(['id', 'company_id', 'device_id', 'device_employee_id', 'pin', 'occurred_at_utc', 'status_code', 'received_at'])
                ->with(['device:id,serial_number', 'company:id,name', 'employee']))
            ->defaultSort('received_at', 'desc')->poll('30s')->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('employee.name')->label(__('filament/resources/attendance-punches.columns.employee'))->placeholder(__('filament.common.unmapped')),
                TextColumn::make('pin')->label(__('filament/resources/attendance-punches.columns.pin')),
                TextColumn::make('device.serial_number')->label(__('filament/resources/attendance-punches.columns.device')),
                TextColumn::make('company.name')->label(__('filament/dashboard.company')),
                TextColumn::make('occurred_at_utc')->label(__('filament/dashboard.punch_time'))->dateTime()->timezone(DashboardQuery::timezone()),
                TextColumn::make('status_code')->label(__('filament/resources/attendance-punches.columns.status'))
                    ->formatStateUsing(fn (?string $state): string => AttendanceStatus::label($state)),
            ])
            ->emptyStateHeading(__('filament/dashboard.no_attendance'))
            ->headerActions([Action::make('view_attendance')->label(__('filament/dashboard.view_all'))->url(AttendancePunchResource::getUrl())]);
    }
}
