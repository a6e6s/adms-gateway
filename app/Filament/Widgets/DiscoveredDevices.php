<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DiscoveredDevices\DiscoveredDeviceResource;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class DiscoveredDevices extends TableWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return DiscoveredDeviceResource::table($table)
            ->heading(__('filament/dashboard.discovered_devices'))
            ->description(__('filament/dashboard.discoveries_description'))
            ->query(fn () => (new DashboardQuery)->discoveries())
            ->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->headerActions([Action::make('view_discoveries')->label(__('filament/dashboard.view_all'))->url(DiscoveredDeviceResource::getUrl())]);
    }
}
