<?php

namespace App\Filament\Resources\DeviceEmployees;

use App\Filament\Resources\CompanyScopedResource;
use App\Filament\Resources\DeviceEmployees\Pages\ManageDeviceEmployees;
use App\Models\Device;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use App\Services\CompanyAccess;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DeviceEmployeeResource extends CompanyScopedResource
{
    protected static ?string $model = DeviceEmployee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/device-employees.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/device-employees.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('device_id')->label(__('filament/resources/device-employees.fields.device'))->relationship('device', 'serial_number', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))->rules([fn (): Closure => CompanyAccess::validationRule(Device::class)])->required()->searchable()->preload(),
                Select::make('employee_id')->label(__('filament/resources/device-employees.fields.employee'))->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))->rules([fn (): Closure => CompanyAccess::validationRule(Employee::class)])->required()->searchable()->preload(),
                TextInput::make('pin')->label(__('filament/resources/device-employees.fields.pin'))->required()->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pin')->label(__('filament/resources/device-employees.columns.pin'))->searchable()->sortable(),
                TextColumn::make('device.serial_number')->label(__('filament/resources/device-employees.columns.device'))->searchable(),
                TextColumn::make('employee.name')->label(__('filament/resources/device-employees.columns.employee'))->searchable(),
                TextColumn::make('employee.company.name')->label(__('filament/resources/device-employees.columns.company')),
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
