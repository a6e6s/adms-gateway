<?php

namespace App\Filament\Resources\DeviceCommands;

use App\Filament\Resources\DeviceCommands\Pages\ManageDeviceCommands;
use App\Models\DeviceCommand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceCommandResource extends Resource
{
    protected static ?string $model = DeviceCommand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Commands are recorded for audit and cannot be edited from this resource.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('device.serial_number')->label('Device')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('wire_command_id')->label('Wire ID'),
                TextColumn::make('status')->badge(),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('offered_at')->dateTime(),
                TextColumn::make('result_received_at')->dateTime(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('device'))
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeviceCommands::route('/'),
        ];
    }
}
