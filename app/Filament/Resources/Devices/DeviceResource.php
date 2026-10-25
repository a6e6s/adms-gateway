<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\CompanyScopedResource;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use App\Services\Adms\ZkDeviceClockReader;
use App\Services\CompanyAccess;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25])
            ->defaultPaginationPageOption(25)
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
                TextColumn::make('device_time_at')
                    ->label(__('filament/resources/devices.columns.device_time_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->timezone(fn (Device $record): string => $record->timezone)
                    ->placeholder(__('filament.common.never'))
                    ->tooltip(fn (Device $record): ?string => $record->device_time_checked_at?->format('Y-m-d H:i:s')),
                TextColumn::make('attlog_stamp')->label(__('filament/resources/devices.columns.attlog_stamp'))->placeholder(__('filament/resources/devices.placeholders.not_received')),
                ToggleColumn::make('is_enabled')
                    ->label(__('filament/resources/devices.fields.is_enabled'))
                    ->disabled(fn (Device $record): bool => ! static::canEdit($record)),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('company'))
            ->filters([
                SelectFilter::make('company_id')
                    ->label(__('filament/resources/devices.fields.company'))
                    ->relationship('company', 'name', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_enabled')
                    ->label(__('filament/resources/devices.fields.is_enabled'))
                    ->options([
                        '1' => __('filament/resources/devices.statuses.enabled'),
                        '0' => __('filament/resources/devices.statuses.disabled'),
                    ]),
                SelectFilter::make('connection_status')
                    ->label(__('filament/resources/devices.filters.connection_status'))
                    ->options([
                        'online' => __('filament/resources/devices.statuses.online'),
                        'offline' => __('filament/resources/devices.statuses.offline'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;
                        if (! in_array($status, ['online', 'offline'], true)) {
                            return $query;
                        }

                        $onlineCutoff = now()->subMinutes(max(1, (int) config('services.adms.device_online_window_minutes', 5)));

                        return $status === 'online'
                            ? $query->where('last_seen_at', '>=', $onlineCutoff)
                            : $query->where(fn (Builder $query): Builder => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $onlineCutoff));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                self::deviceCommandsBulkAction(),
                self::attendanceBulkAction(forceResend: false),
                self::attendanceBulkAction(forceResend: true),
            ]);
    }

    private static function readDeviceClockBulkAction(): BulkAction
    {
        return BulkAction::make('readDeviceClock')
            ->label(__('filament/resources/devices.actions.read_device_clock'))
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() || (auth()->user()?->can('Update:Device') ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('filament/resources/devices.actions.read_device_clock_heading'))
            ->modalDescription(__('filament/resources/devices.actions.read_device_clock_description'))
            ->action(function (Collection $records, ZkDeviceClockReader $reader): void {
                if ($records->count() !== 1) {
                    throw ValidationException::withMessages([
                        'table' => __('filament/resources/devices.validation.single_device_operation'),
                    ]);
                }

                /** @var Device $device */
                $device = $records->first();
                try {
                    $deviceTime = $reader->read($device);
                    $checkedAt = now();
                    $device->forceFill([
                        'device_time_at' => $deviceTime,
                        'device_time_checked_at' => $checkedAt,
                    ])->save();

                    Notification::make()
                        ->title(__('filament/resources/devices.notifications.device_clock_read', [
                            'time' => $deviceTime->setTimezone($device->timezone)->format('Y-m-d H:i:s'),
                            'timezone' => $device->timezone,
                        ]))
                        ->success()
                        ->send();
                } catch (\RuntimeException $exception) {
                    Notification::make()
                        ->title(__('filament/resources/devices.notifications.device_clock_read_failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function deviceCommandsBulkAction(): BulkAction
    {
        $requiresPin = static fn (Get $get): bool => in_array($get('operation'), [
            'update_user', 'update_card', 'update_fingerprint', 'update_face', 'delete_user',
            'delete_fingerprint', 'delete_face', 'register_fingerprint', 'register_face', 'register_card',
            'query_users',
        ], true);
        $requiresConfirmationPhrase = static fn (Get $get): bool => self::guideOperationConfirmationPhrase((string) $get('operation')) !== null;

        return BulkAction::make('deviceCommands')
            ->label(__('filament/resources/devices.actions.device_commands'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() || (auth()->user()?->can('Update:Device') ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('filament/resources/devices.actions.device_commands_heading'))
            ->modalDescription(__('filament/resources/devices.actions.device_commands_description'))
            ->schema([
                Select::make('operation')
                    ->label(__('filament/resources/devices.fields.operation'))
                    ->options(collect(self::deviceCommandOperations())
                        ->mapWithKeys(fn (string $operation): array => [$operation => __('filament/resources/devices.operations.'.$operation)])
                        ->all())
                    ->live()
                    ->required(),
                TextEntry::make('operation_description')
                    ->label(__('filament/resources/devices.operation_details.description_label'))
                    ->state(fn (Get $get): ?string => self::operationDetail((string) $get('operation'), 'description'))
                    ->visible(fn (Get $get): bool => self::hasOperationDetail((string) $get('operation')))
                    ->columnSpanFull(),
                TextEntry::make('operation_effects')
                    ->label(__('filament/resources/devices.operation_details.effects_label'))
                    ->state(fn (Get $get): ?string => self::operationDetail((string) $get('operation'), 'effects'))
                    ->visible(fn (Get $get): bool => self::hasOperationDetail((string) $get('operation')))
                    ->columnSpanFull(),
                DateTimePicker::make('device_time')
                    ->label(__('filament/resources/devices.fields.device_time'))
                    ->native(false)
                    ->timezone(config('app.timezone'))
                    ->displayFormat('d/m/Y H:i:s')
                    ->seconds()
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_device_time')
                    ->required(fn (Get $get): bool => $get('operation') === 'set_device_time'),
                TextInput::make('pin')
                    ->label(__('filament/resources/devices.fields.pin'))
                    ->maxLength(32)
                    ->visible($requiresPin)
                    ->required($requiresPin),
                TextInput::make('name')
                    ->label(__('filament/resources/devices.fields.user_name'))
                    ->maxLength(48)
                    ->visible(fn (Get $get): bool => $get('operation') === 'update_user')
                    ->required(fn (Get $get): bool => $get('operation') === 'update_user'),
                Select::make('privilege')
                    ->label(__('filament/resources/devices.fields.privilege'))
                    ->options(__('filament/resources/devices.privileges'))
                    ->default(0)
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('operation') === 'update_user')
                    ->visible(fn (Get $get): bool => $get('operation') === 'update_user'),
                TextInput::make('password')
                    ->label(__('filament/resources/devices.fields.user_password'))
                    ->helperText(__('filament/resources/devices.fields.user_password_help'))
                    ->password()
                    ->maxLength(32)
                    ->visible(fn (Get $get): bool => $get('operation') === 'update_user'),
                TextInput::make('card_number')
                    ->label(__('filament/resources/devices.fields.card_number'))
                    ->maxLength(32)
                    ->visible(fn (Get $get): bool => $get('operation') === 'update_card')
                    ->required(fn (Get $get): bool => $get('operation') === 'update_card'),
                TextInput::make('version')
                    ->label(__('filament/resources/devices.fields.template_version'))
                    ->maxLength(12)
                    ->default('10.0')
                    ->visible(fn (Get $get): bool => in_array($get('operation'), ['update_fingerprint', 'update_face'], true)),
                Textarea::make('template')
                    ->label(__('filament/resources/devices.fields.template_base64'))
                    ->rows(4)
                    ->maxLength(262144)
                    ->visible(fn (Get $get): bool => in_array($get('operation'), ['update_fingerprint', 'update_face'], true))
                    ->required(fn (Get $get): bool => in_array($get('operation'), ['update_fingerprint', 'update_face'], true)),
                TextInput::make('web_server_ip')
                    ->label(__('filament/resources/devices.fields.web_server_address'))
                    ->helperText(__('filament/resources/devices.fields.web_server_domain_help'))
                    ->rules(['required', 'string', 'max:261', 'regex:/^(?:https?:\/\/)?(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*\/?$/i'])
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_web_server')
                    ->required(fn (Get $get): bool => $get('operation') === 'set_web_server'),
                TextInput::make('web_server_port')
                    ->label(__('filament/resources/devices.fields.web_server_port'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_web_server')
                    ->required(fn (Get $get): bool => $get('operation') === 'set_web_server'),
                TextInput::make('ip_address')
                    ->label(__('filament/resources/devices.fields.device_ip'))
                    ->helperText(__('filament/resources/devices.fields.static_network_help'))
                    ->ip()
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_network')
                    ->required(fn (Get $get): bool => $get('operation') === 'set_network'),
                TextInput::make('netmask')
                    ->label(__('filament/resources/devices.fields.netmask'))
                    ->ip()
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_network')
                    ->required(fn (Get $get): bool => $get('operation') === 'set_network'),
                TextInput::make('gateway')
                    ->label(__('filament/resources/devices.fields.gateway'))
                    ->ip()
                    ->visible(fn (Get $get): bool => $get('operation') === 'set_network'),
                TextInput::make('confirmation_phrase')
                    ->label(__('filament/resources/devices.fields.confirmation_phrase'))
                    ->helperText(fn (Get $get): ?string => self::guideOperationConfirmationPhrase((string) $get('operation')))
                    ->visible($requiresConfirmationPhrase)
                    ->required($requiresConfirmationPhrase)
                    ->rules(fn (Get $get): array => self::guideOperationConfirmationPhrase((string) $get('operation')) === null
                        ? []
                        : [Rule::in([self::guideOperationConfirmationPhrase((string) $get('operation'))])]),
            ])
            ->action(function (Collection $records, array $data, DeviceCommandService $commands, ZkDeviceClockReader $reader): void {
                $user = auth()->user();
                $operation = $data['operation'] ?? null;
                if (! $user instanceof User || ! is_string($operation)) {
                    return;
                }

                if (! in_array($operation, self::deviceCommandOperations(), true)) {
                    throw ValidationException::withMessages([
                        'operation' => __('filament/resources/devices.validation.operation_not_available'),
                    ]);
                }

                $requiredPhrase = self::guideOperationConfirmationPhrase($operation);
                if ($requiredPhrase !== null && ($data['confirmation_phrase'] ?? null) !== $requiredPhrase) {
                    throw ValidationException::withMessages([
                        'confirmation_phrase' => __('filament/resources/devices.validation.confirmation_phrase'),
                    ]);
                }

                if (self::guideOperationRequiresSingleDevice($operation) && $records->count() !== 1) {
                    throw ValidationException::withMessages([
                        'operation' => __('filament/resources/devices.validation.single_device_operation'),
                    ]);
                }

                if ($operation === 'read_device_clock') {
                    /** @var Device $device */
                    $device = $records->first();

                    try {
                        $deviceTime = $reader->read($device);
                        $checkedAt = now();
                        $device->forceFill([
                            'device_time_at' => $deviceTime,
                            'device_time_checked_at' => $checkedAt,
                        ])->save();

                        Notification::make()
                            ->title(__('filament/resources/devices.notifications.device_clock_read', [
                                'time' => $deviceTime->setTimezone($device->timezone)->format('Y-m-d H:i:s'),
                                'timezone' => $device->timezone,
                            ]))
                            ->success()
                            ->send();
                    } catch (\RuntimeException $exception) {
                        Notification::make()
                            ->title(__('filament/resources/devices.notifications.device_clock_read_failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }

                    return;
                }

                $queued = 0;
                $failed = 0;
                foreach ($records as $device) {
                    try {
                        self::queueSelectedDeviceCommand($device, $user, $operation, $data, $commands);
                        $queued++;
                    } catch (\DomainException|\InvalidArgumentException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('filament/resources/devices.notifications.device_commands_queued', ['count' => $queued]))
                    ->body($failed > 0 ? __('filament/resources/devices.notifications.commands_skipped', ['count' => $failed]) : null)
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** @return list<string> */
    private static function deviceCommandOperations(): array
    {
        return [
            'request_device_info', 'read_device_clock', 'request_device_options', 'sync_clock_to_server',
            'reboot', 'unlock', 'refresh_options', 'cancel_alarm', 'set_device_time',
            'disable_time_sync', 'set_web_server', 'set_network', 'update_user', 'update_card',
            'update_fingerprint', 'update_face', 'delete_user', 'delete_fingerprint', 'delete_face',
            'register_fingerprint', 'register_face', 'register_card', 'query_users',
        ];
    }

    private static function hasOperationDetail(string $operation): bool
    {
        return in_array($operation, self::deviceCommandOperations(), true);
    }

    private static function operationDetail(string $operation, string $detail): ?string
    {
        if (! self::hasOperationDetail($operation)) {
            return null;
        }

        return __("filament/resources/devices.operation_details.{$operation}.{$detail}");
    }

    /** @param array<string, mixed> $data */
    private static function queueSelectedDeviceCommand(
        Device $device,
        User $user,
        string $operation,
        array $data,
        DeviceCommandService $commands,
    ): void {
        match ($operation) {
            'request_device_info' => $commands->requestDeviceInfo($device, $user),
            'request_device_options' => $commands->requestDeviceOptions($device, $user),
            'sync_clock_to_server' => $commands->syncClockToServer($device, $user),
            default => $commands->queueGuideOperation($device, $user, $operation, $data),
        };
    }

    private static function guideOperationConfirmationPhrase(string $operation): ?string
    {
        return match ($operation) {
            'reboot' => 'REBOOT',
            'unlock' => 'UNLOCK',
            'clear_log' => 'CLEAR LOG',
            'clear_data' => 'CLEAR DATA',
            'clear_photo' => 'CLEAR PHOTO',
            'cancel_alarm' => 'CANCEL ALARM',
            'set_device_time' => 'SET DEVICE TIME',
            'set_web_server' => 'SET WEB SERVER',
            'set_network' => 'SET NETWORK',
            'disable_time_sync' => 'DISABLE TIME SYNC',
            'update_user' => 'UPDATE USER',
            'update_card' => 'UPDATE CARD',
            'update_fingerprint' => 'UPDATE FINGERPRINT',
            'update_face' => 'UPDATE FACE',
            'delete_user' => 'DELETE USER',
            'delete_fingerprint' => 'DELETE FINGERPRINT',
            'delete_face' => 'DELETE FACE',
            'register_fingerprint' => 'REGISTER FINGERPRINT',
            'register_face' => 'REGISTER FACE',
            'register_card' => 'REGISTER CARD',
            'query_users' => 'EXPORT USERS',
            default => null,
        };
    }

    private static function guideOperationRequiresSingleDevice(string $operation): bool
    {
        return in_array($operation, [
            'read_device_clock', 'reboot', 'unlock', 'clear_log', 'clear_data', 'clear_photo', 'cancel_alarm',
            'set_web_server', 'set_network', 'delete_user', 'delete_fingerprint', 'delete_face',
            'register_fingerprint', 'register_face', 'register_card',
        ], true);
    }

    private static function deviceInfoBulkAction(): BulkAction
    {
        return BulkAction::make('requestDeviceInfo')
            ->label(__('filament/resources/devices.actions.request_device_info'))
            ->icon(Heroicon::OutlinedServerStack)
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() || (auth()->user()?->can('Update:Device') ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('filament/resources/devices.actions.request_device_info_heading'))
            ->modalDescription(__('filament/resources/devices.actions.request_device_info_description'))
            ->action(function (Collection $records, DeviceCommandService $commands): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $queued = 0;
                $failed = 0;

                foreach ($records as $device) {
                    try {
                        $commands->requestDeviceInfo($device, $user);
                        $queued++;
                    } catch (\DomainException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('filament/resources/devices.notifications.device_info_queued', ['count' => $queued]))
                    ->body($failed > 0 ? __('filament/resources/devices.notifications.commands_skipped', ['count' => $failed]) : null)
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function syncClockBulkAction(): BulkAction
    {
        return BulkAction::make('syncClockToServer')
            ->label(__('filament/resources/devices.actions.sync_clock_to_server'))
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() || (auth()->user()?->can('Update:Device') ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('filament/resources/devices.actions.sync_clock_heading'))
            ->modalDescription(__('filament/resources/devices.actions.sync_clock_description'))
            ->action(function (Collection $records, DeviceCommandService $commands): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $queued = 0;
                $failed = 0;

                foreach ($records as $device) {
                    try {
                        $commands->syncClockToServer($device, $user);
                        $queued++;
                    } catch (\DomainException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('filament/resources/devices.notifications.sync_clock_queued', ['count' => $queued]))
                    ->body($failed > 0 ? __('filament/resources/devices.notifications.commands_skipped', ['count' => $failed]) : null)
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
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
