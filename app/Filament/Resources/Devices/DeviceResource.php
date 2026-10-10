<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\CompanyScopedResource;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use App\Services\CompanyAccess;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class DeviceResource extends CompanyScopedResource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Devices';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.devices');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/devices.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/devices.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')->label(__('filament/resources/devices.fields.company'))->relationship('company', 'name', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))->rules([fn (): Closure => CompanyAccess::validationRule(Company::class)])->required()->searchable()->preload(),
                TextInput::make('serial_number')->label(__('filament/resources/devices.fields.serial_number'))->required()->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('name')->label(__('filament/resources/devices.fields.name'))->required()->maxLength(255),
                TextInput::make('location')->label(__('filament/resources/devices.fields.location'))->nullable()->maxLength(255),
                TextInput::make('expected_ip')->label(__('filament/resources/devices.fields.expected_ip'))->nullable()->ip()->maxLength(45),
                TextInput::make('timezone')->label(__('filament/resources/devices.fields.timezone'))->required()->rule('timezone')->default('UTC'),
                TextInput::make('protocol_profile')->label(__('filament/resources/devices.fields.protocol_profile'))->required()->default('push-2.4-attlog-v1'),
                Toggle::make('is_enabled')->label(__('filament/resources/devices.fields.is_enabled'))->required()->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')->label(__('filament/resources/devices.columns.serial_number'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('filament/resources/devices.columns.name'))->searchable(),
                TextColumn::make('company.name')->label(__('filament/resources/devices.columns.company'))->sortable(),
                TextColumn::make('connection_status')
                    ->label(__('filament/resources/devices.columns.connection_status'))
                    ->state(fn (Device $record): string => $record->isOnline() ? 'online' : 'offline')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'online' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => __('filament/resources/devices.statuses.'.$state)),
                TextColumn::make('push_version')->label(__('filament/resources/devices.columns.push_version')),
                TextColumn::make('last_seen_at')->label(__('filament/resources/devices.columns.last_seen_at'))->dateTime()->sortable()->placeholder(__('filament.common.never')),
                TextColumn::make('last_getrequest_at')->label(__('filament/resources/devices.columns.last_getrequest_at'))->dateTime()->sortable()->placeholder(__('filament.common.never')),
                TextColumn::make('attlog_stamp')->label(__('filament/resources/devices.columns.attlog_stamp'))->placeholder(__('filament/resources/devices.placeholders.not_received')),
                ToggleColumn::make('is_enabled')
                    ->label(__('filament/resources/devices.fields.is_enabled'))
                    ->disabled(fn (Device $record): bool => ! static::canEdit($record)),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('company'))
            ->filters([])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                self::attendanceBulkAction(forceResend: false),
                self::attendanceBulkAction(forceResend: true),
            ]);
    }

    private static function attendanceBulkAction(bool $forceResend): BulkAction
    {
        $actionName = $forceResend ? 'forceResendAllAttendance' : 'requestAttendance';

        return BulkAction::make($actionName)
            ->label(__($forceResend ? 'filament/resources/devices.actions.force_resend' : 'filament/resources/devices.actions.request_attendance'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color($forceResend ? 'warning' : 'primary')
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() || (auth()->user()?->can('Update:Device') ?? false))
            ->requiresConfirmation()
            ->modalHeading(__($forceResend ? 'filament/resources/devices.actions.force_resend_heading' : 'filament/resources/devices.actions.request_attendance_heading'))
            ->modalDescription(__('filament/resources/devices.actions.bulk_attendance_description'))
            ->fillForm([
                'start_time' => now()->setDate(2000, 1, 1)->startOfDay()->format('Y-m-d H:i:s'),
                'end_time' => now()->format('Y-m-d H:i:s'),
            ])
            ->schema([
                DateTimePicker::make('start_time')
                    ->label(__('filament/resources/devices.fields.start_time'))
                    ->native(false)
                    ->timezone(config('app.timezone'))
                    ->displayFormat('d/m/Y H:i:s')
                    ->seconds()
                    ->required()
                    ->beforeOrEqual('end_time'),
                DateTimePicker::make('end_time')
                    ->label(__('filament/resources/devices.fields.end_time'))
                    ->native(false)
                    ->timezone(config('app.timezone'))
                    ->displayFormat('d/m/Y H:i:s')
                    ->seconds()
                    ->required()
                    ->afterOrEqual('start_time'),
            ])
            ->action(function (Collection $records, array $data, DeviceCommandService $commands) use ($forceResend): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $queued = 0;
                $failed = 0;

                foreach ($records as $device) {
                    try {
                        $method = $forceResend ? 'forceResendAllAttendance' : 'requestAttendance';
                        $commands->{$method}($device, $user, $data['start_time'], $data['end_time']);
                        $queued++;
                    } catch (\DomainException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('filament/resources/devices.notifications.commands_queued', ['count' => $queued]))
                    ->body($failed > 0 ? __('filament/resources/devices.notifications.commands_skipped', ['count' => $failed]) : null)
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDevices::route('/'),
        ];
    }
}
