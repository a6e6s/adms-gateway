<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.people');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/companies.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/companies.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('filament/resources/companies.fields.name'))->required()->maxLength(255),
                TextInput::make('code')->label(__('filament/resources/companies.fields.code'))->nullable()->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('timezone')->label(__('filament/resources/companies.fields.timezone'))->required()->rule('timezone')->default('UTC'),
                Toggle::make('is_active')->label(__('filament/resources/companies.fields.is_active'))->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('filament/resources/companies.columns.name'))->searchable()->sortable(),
                TextColumn::make('code')->label(__('filament/resources/companies.columns.code'))->searchable(),
                TextColumn::make('timezone')->label(__('filament/resources/companies.columns.timezone')),
                ToggleColumn::make('is_active')
                    ->label(__('filament/resources/companies.fields.is_active'))
                    ->disabled(fn (Company $record): bool => ! static::canEdit($record)),
                TextColumn::make('devices_count')->counts('devices')->label(__('filament/resources/companies.columns.devices_count')),
            ])
            ->filters([
                //
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanies::route('/'),
        ];
    }
}
