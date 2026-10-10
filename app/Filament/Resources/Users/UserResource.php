<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Company;
use App\Models\User;
use App\Services\ManageUserAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament/resources/users.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/users.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/users.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('filament/resources/users.fields.name'))->required()->maxLength(255),
            TextInput::make('email')->label(__('filament/resources/users.fields.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')->label(__('filament/resources/users.fields.password'))->password()->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')->minLength(8)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(__('filament/resources/users.password_hint')),
            Select::make('company_ids')->label(__('filament/resources/users.fields.companies'))->multiple()->searchable()->preload()->default([])
                ->options(fn (): array => Company::query()->pluck('name', 'id')->all())
                ->helperText(__('filament/resources/users.super_admin_warning')),
            Select::make('role_ids')->label(__('filament/resources/users.fields.roles'))->multiple()->searchable()->preload()->default([])
                ->options(fn (): array => Role::query()->where('guard_name', 'web')
                    ->whereNotIn('name', [config('filament-shield.super_admin.name'), config('filament-shield.panel_user.name')])
                    ->pluck('name', 'id')->all())
                ->helperText(__('filament/resources/users.roles_hint')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['companies', 'roles']))
            ->columns([
                TextColumn::make('name')->label(__('filament/resources/users.fields.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('filament/resources/users.fields.email'))->searchable()->sortable(),
                TextColumn::make('companies.name')->label(__('filament/resources/users.fields.companies'))->badge(),
                TextColumn::make('roles.name')->label(__('filament/resources/users.fields.roles'))->badge(),
                TextColumn::make('created_at')->label(__('filament/resources/users.fields.created_at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make()->fillForm(fn (User $record): array => [
                    'name' => $record->name,
                    'email' => $record->email,
                    'company_ids' => $record->companies()->pluck('companies.id')->all(),
                    'role_ids' => $record->roles()->whereNotIn('name', [config('filament-shield.super_admin.name'), config('filament-shield.panel_user.name')])->pluck('roles.id')->all(),
                ])->using(fn (User $record, array $data, ManageUsers $livewire): User => $livewire->saveUser($record, $data)),
                DeleteAction::make()->using(fn (User $record): bool => app(ManageUserAccess::class)->delete(auth()->user(), $record)),
            ])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
