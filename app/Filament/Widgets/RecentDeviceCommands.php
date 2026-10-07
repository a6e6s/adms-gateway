<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeviceCommands\DeviceCommandResource;
use App\Models\DeviceCommand;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class RecentDeviceCommands extends TableWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('DeviceCommand');
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('filament/dashboard.recent_commands'))
            ->description(__('filament/dashboard.commands_description'))
            ->query(fn () => (new DashboardQuery($this->pageFilters ?? []))->commands()
                ->select(['id', 'device_id', 'status', 'requested_at', 'expires_at'])->with(['device:id,serial_number,company_id', 'device.company:id,name']))
            ->defaultSort('requested_at', 'desc')->poll('30s')->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('device.serial_number')->label(__('filament/resources/device-commands.columns.device')),
                TextColumn::make('device.company.name')->label(__('filament/dashboard.company')),
                TextColumn::make('status')->label(__('filament/resources/device-commands.columns.status'))
                    ->state(fn (DeviceCommand $record): string => $record->status === 'pending' && $record->expires_at?->isPast() ? 'expired' : $record->status)
                    ->badge()->formatStateUsing(fn (string $state): string => __('filament/resources/device-commands.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'failed', 'expired' => 'danger',
                        'attendance_received', 'acknowledged' => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('requested_at')->label(__('filament/resources/device-commands.columns.requested_at'))->dateTime()->timezone(DashboardQuery::timezone()),
            ])
            ->emptyStateHeading(__('filament/dashboard.no_commands'))
            ->headerActions([Action::make('view_commands')->label(__('filament/dashboard.view_all'))->url(DeviceCommandResource::getUrl())]);
    }
}
