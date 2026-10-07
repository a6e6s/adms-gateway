<?php

namespace App\Filament\Resources\DiscoveredDevices;

use App\Filament\Resources\DiscoveredDevices\Pages\ManageDiscoveredDevices;
use App\Models\Company;
use App\Models\Device;
use App\Models\DiscoveredDevice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DiscoveredDeviceResource extends Resource
{
    protected static ?string $model = DiscoveredDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.groups.devices');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/discovered-devices.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/discovered-devices.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('serial_number', Device::query()->select('serial_number')))
            ->defaultSort('last_seen_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading(__('filament/resources/discovered-devices.empty'))
            ->emptyStateDescription(__('filament/resources/discovered-devices.empty_description'))
            ->columns([
                TextColumn::make('serial_number')->label(__('filament/resources/devices.fields.serial_number'))->searchable(),
                TextColumn::make('last_seen_ip')->label(__('filament/resources/discovered-devices.ip')),
                TextColumn::make('first_seen_at')->label(__('filament/resources/discovered-devices.first_seen'))->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->label(__('filament/resources/devices.columns.last_seen_at'))->dateTime()->sortable(),
                TextColumn::make('attempt_count')->label(__('filament/resources/discovered-devices.attempts'))->numeric(),
                TextColumn::make('push_version')->label(__('filament/resources/devices.columns.push_version')),
                TextColumn::make('device_type')->label(__('filament/resources/discovered-devices.device_type')),
            ])
            ->recordActions([
                Action::make('register')
                    ->label(__('filament/resources/discovered-devices.register'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (DiscoveredDevice $record): bool => Gate::allows('register', $record))
                    ->modalDescription(__('filament/resources/discovered-devices.description'))
                    ->fillForm(fn (DiscoveredDevice $record): array => ['name' => $record->serial_number, 'timezone' => 'Asia/Riyadh'])
                    ->schema([
                        Select::make('company_id')->label(__('filament/resources/devices.fields.company'))
                            ->options(fn () => Company::query()->where('is_active', true)->pluck('name', 'id'))
                            ->searchable()->required()->exists('companies', 'id'),
                        TextInput::make('name')->label(__('filament/resources/devices.fields.name'))->required()->maxLength(255),
                        TextInput::make('location')->label(__('filament/resources/devices.fields.location'))->maxLength(255),
                        TextInput::make('timezone')->label(__('filament/resources/devices.fields.timezone'))->required()->rule('timezone'),
                    ])
                    ->action(function (DiscoveredDevice $record, array $data): void {
                        Gate::authorize('register', $record);

                        DB::transaction(function () use ($record, $data): void {
                            $discovery = DiscoveredDevice::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                            if (Device::query()->where('serial_number', $discovery->serial_number)->exists()) {
                                throw ValidationException::withMessages(['name' => __('filament/resources/discovered-devices.already_registered')]);
                            }
                            if (! Company::query()->whereKey($data['company_id'])->where('is_active', true)->exists()) {
                                throw ValidationException::withMessages(['company_id' => __('filament/resources/discovered-devices.active_company')]);
                            }

                            Device::query()->create([
                                'serial_number' => $discovery->serial_number,
                                'company_id' => $data['company_id'],
                                'name' => $data['name'],
                                'location' => $data['location'] ?? null,
                                'timezone' => $data['timezone'],
                                'protocol_profile' => 'push-2.4-attlog-v1',
                                'push_version' => $discovery->push_version,
                                'device_type' => $discovery->device_type,
                                'is_enabled' => false,
                            ]);
                        });

                        Notification::make()->title(__('filament/resources/discovered-devices.registered'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDiscoveredDevices::route('/')];
    }
}
