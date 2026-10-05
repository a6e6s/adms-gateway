<?php

namespace App\Filament\Resources\DeviceEmployees;

use App\Filament\Resources\DeviceEmployees\Pages\ManageDeviceEmployees;
use App\Models\DeviceEmployee;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceEmployeeResource extends Resource
{
    protected static ?string $model = DeviceEmployee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('device_id')->relationship('device', 'serial_number')->required()->searchable()->preload(),
                Select::make('employee_id')->relationship('employee', 'name')->required()->searchable()->preload(),
                TextInput::make('pin')->required()->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pin')->searchable()->sortable(),
                TextColumn::make('device.serial_number')->label('Device')->searchable(),
                TextColumn::make('employee.name')->label('Employee')->searchable(),
                TextColumn::make('employee.company.name')->label('Company'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['device', 'employee.company']))
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeviceEmployees::route('/'),
        ];
    }
}
