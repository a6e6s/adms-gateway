<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AttendanceTrend;
use App\Filament\Widgets\DevicesNeedingAttention;
use App\Filament\Widgets\DiscoveredDevices;
use App\Filament\Widgets\OperationalSummary;
use App\Filament\Widgets\RecentAttendance;
use App\Filament\Widgets\RecentDeviceCommands;
use App\Filament\Widgets\UploadsNeedingAttention;
use App\Models\Company;
use App\Services\Adms\DashboardQuery;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament/dashboard.filters'))
                ->columnSpanFull()
                ->description(__('filament/dashboard.filter_description', ['timezone' => DashboardQuery::timezone()]))
                ->columns(3)
                ->schema([
                    Select::make('company_id')->label(__('filament/dashboard.company'))
                        ->placeholder(__('filament/dashboard.all_companies'))
                        ->options(fn () => (OperationalSummary::canView() || DashboardQuery::canAccess('DeviceCommand')) ? Company::query()->pluck('name', 'id') : [])->searchable(),
                    DatePicker::make('start_date')->label(__('filament/dashboard.start_date'))
                        ->default(fn () => CarbonImmutable::now(DashboardQuery::timezone())->format('Y-m-d'))->required(),
                    DatePicker::make('end_date')->label(__('filament/dashboard.end_date'))
                        ->default(fn () => CarbonImmutable::now(DashboardQuery::timezone())->format('Y-m-d'))->required()->afterOrEqual('start_date'),
                ]),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }

    public function getWidgets(): array
    {
        return [OperationalSummary::class, DevicesNeedingAttention::class, UploadsNeedingAttention::class, DiscoveredDevices::class, RecentAttendance::class, AttendanceTrend::class, RecentDeviceCommands::class];
    }
}
