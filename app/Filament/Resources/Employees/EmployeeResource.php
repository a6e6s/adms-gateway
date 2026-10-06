<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/employees.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/employees.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')->label(__('filament/resources/employees.fields.company'))->relationship('company', 'name')->required()->searchable()->preload(),
                TextInput::make('employee_number')->label(__('filament/resources/employees.fields.employee_number'))->required()->maxLength(100),
                TextInput::make('name')->label(__('filament/resources/employees.fields.name'))->required()->maxLength(255),
                Toggle::make('is_active')->label(__('filament/resources/employees.fields.is_active'))->required()->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_number')->label(__('filament/resources/employees.columns.employee_number'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('filament/resources/employees.columns.name'))->searchable()->sortable(),
                TextColumn::make('company.name')->label(__('filament/resources/employees.columns.company'))->sortable(),
                ToggleColumn::make('is_active')
                    ->label(__('filament/resources/employees.fields.is_active'))
                    ->disabled(fn (Employee $record): bool => ! static::canEdit($record)),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('company'))
            ->filters([])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployees::route('/'),
        ];
    }
}
