<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class DevicesNeedingAttention extends TableWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('Device');
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('filament/dashboard.devices_attention'))
            ->description(__('filament/dashboard.devices_description'))
            ->query(fn () => (new DashboardQuery($this->pageFilters ?? []))->devicesNeedingAttention()->with('company:id,name'))
            ->defaultSort('last_seen_at', 'asc')->poll('30s')->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')->label(__('filament/resources/devices.fields.name')),
                TextColumn::make('serial_number')->label(__('filament/resources/devices.fields.serial_number')),
                TextColumn::make('company.name')->label(__('filament/dashboard.company')),
                TextColumn::make('connection_status')->label(__('filament/resources/devices.columns.connection_status'))
                    ->state(fn (Device $record): string => $record->last_seen_at === null ? __('filament/dashboard.never_connected') : __('filament/resources/devices.statuses.offline'))
                    ->badge()->color('warning'),
                TextColumn::make('last_seen_at')->label(__('filament/resources/devices.columns.last_seen_at'))
                    ->dateTime()->timezone(DashboardQuery::timezone())->placeholder(__('filament/dashboard.never_connected')),
            ])
            ->emptyStateHeading(__('filament/dashboard.devices_healthy'))
            ->headerActions([Action::make('view_devices')->label(__('filament/dashboard.view_all'))->url(DeviceResource::getUrl())]);
    }
}
