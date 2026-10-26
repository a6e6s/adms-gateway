<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Filament\Resources\CompanyScopedResource;
use App\Models\BioTimeClient;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class CompanyResource extends CompanyScopedResource
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
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25])
            ->defaultPaginationPageOption(25)
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
                SelectFilter::make('is_active')
                    ->label(__('filament/resources/companies.fields.is_active'))
                    ->options([
                        '1' => __('filament/resources/companies.statuses.active'),
                        '0' => __('filament/resources/companies.statuses.inactive'),
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                EditAction::make(),
                Action::make('createApiClient')
                    ->label(__('filament/resources/companies.api_client.manage'))
                    ->modalHeading(fn (Company $record): string => __('filament/resources/companies.api_client.heading', ['company' => $record->name]))
                    ->modalSubmitActionLabel(__('filament/resources/companies.api_client.save'))
                    ->modalDescription(fn (Company $record): string => $record->bioTimeClients()->exists()
                        ? __('filament/resources/companies.api_client.existing_list', [
                            'credentials' => $record->bioTimeClients()->orderBy('username')->pluck('username')->implode(', '),
                        ])
                        : __('filament/resources/companies.api_client.none'))
                    ->visible(fn (Company $record): bool => static::canEdit($record))
                    ->schema([
                        Repeater::make('bioTimeClients')
                            ->label(__('filament/resources/companies.api_client.credentials'))
                            ->itemLabel(fn (array $state): ?string => $state['username'] ?? null)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('username')
                                    ->label(__('filament/resources/companies.api_client.username'))
                                    ->required(fn (Get $get): bool => blank($get('id')))->maxLength(150)->regex('/^\\S+$/u')
                                    ->unique(
                                        table: 'bio_time_clients',
                                        column: 'username',
                                        ignorable: fn (Get $get): ?BioTimeClient => filled($get('id')) ? BioTimeClient::query()->find($get('id')) : null,
                                        ignoreRecord: false,
                                    ),
                                Toggle::make('is_active')
                                    ->label(__('filament/resources/companies.api_client.active'))
                                    ->required(),
                                TextInput::make('password')
                                    ->label(__('filament/resources/companies.api_client.new_password'))
                                    ->password()->nullable()->required(fn (Get $get): bool => blank($get('id')))
                                    ->formatStateUsing(fn (): null => null)
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->minLength(12)->maxLength(4096)->confirmed(),
                                TextInput::make('password_confirmation')
                                    ->label(__('filament/resources/companies.api_client.password_confirmation'))
                                    ->password()->nullable()->required(fn (Get $get): bool => blank($get('id')))->dehydrated(false),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addable()
                            ->deletable()
                            ->deleteAction(fn (Action $action): Action => $action
                                ->label(__('filament/resources/companies.api_client.revoke'))
                                ->requiresConfirmation()
                                ->modalHeading(__('filament/resources/companies.api_client.revoke_heading'))
                                ->modalDescription(__('filament/resources/companies.api_client.revoke_description'))),
                    ])
                    ->fillForm(fn (Company $record): array => [
                        'bioTimeClients' => $record->bioTimeClients()
                            ->get(['id', 'username', 'is_active'])
                            ->mapWithKeys(fn ($client): array => [(string) $client->id => [
                                'id' => $client->id,
                                'username' => $client->username,
                                'is_active' => $client->is_active,
                                'password' => null,
                                'password_confirmation' => null,
                            ]])->all(),
                    ])
                    ->action(function (Company $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $record->refresh();
                            Gate::authorize('update', $record);

                            $existingClientIds = $record->bioTimeClients()->pluck('id')->all();
                            $retainedClientIds = [];

                            foreach ($data['bioTimeClients'] ?? [] as $clientData) {
                                if (blank($clientData['id'] ?? null)) {
                                    abort_unless($record->is_active, 403);

                                    $record->bioTimeClients()->create([
                                        'username' => $clientData['username'],
                                        'password' => $clientData['password'],
                                        'is_active' => true,
                                    ]);

                                    continue;
                                }

                                $client = $record->bioTimeClients()->findOrFail($clientData['id']);
                                $retainedClientIds[] = $client->id;
                                $client->username = $clientData['username'];
                                $client->is_active = $clientData['is_active'];

                                if (filled($clientData['password'] ?? null)) {
                                    $client->password = $clientData['password'];
                                }

                                $client->save();
                            }

                            $record->bioTimeClients()
                                ->whereIn('id', $existingClientIds)
                                ->whereNotIn('id', $retainedClientIds)
                                ->delete();
                        });

                        Notification::make()
                            ->title(__('filament/resources/companies.api_client.saved'))
                            ->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanies::route('/'),
        ];
    }
}
