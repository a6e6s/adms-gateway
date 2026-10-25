<?php

namespace App\Filament\Resources\DeviceCommands;

use App\Filament\Resources\CompanyScopedResource;
use App\Filament\Resources\DeviceCommands\Pages\ManageDeviceCommands;
use App\Models\Company;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use App\Services\CompanyAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class DeviceCommandResource extends CompanyScopedResource
{
    protected static ?string $model = DeviceCommand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/device-commands.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/device-commands.plural_model_label');
    }

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
            ->defaultSort('requested_at', 'desc')
            ->paginationPageOptions([25])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('device.company.name')->label(__('filament/resources/device-commands.columns.company'))->searchable(),
                TextColumn::make('device.serial_number')->label(__('filament/resources/device-commands.columns.device'))->searchable(),
                TextColumn::make('type')->label(__('filament/resources/device-commands.columns.type'))
                    ->badge()
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'request_attendance' => __('filament/resources/device-commands.types.request_attendance'),
                        'force_resend_attendance' => __('filament/resources/device-commands.types.force_resend_attendance'),
                        default => str($state)->replace('guide_', '')->headline()->toString(),
                    }),
                TextColumn::make('wire_command_id')->label(__('filament/resources/device-commands.columns.wire_command_id'))->searchable(),
                TextColumn::make('query_start_time')
                    ->label(__('filament/resources/device-commands.columns.query_start_time'))
                    ->placeholder(__('filament/resources/device-commands.not_applicable')),
                TextColumn::make('query_end_time')
                    ->label(__('filament/resources/device-commands.columns.query_end_time'))
                    ->placeholder(__('filament/resources/device-commands.not_applicable')),
                TextColumn::make('status')->label(__('filament/resources/device-commands.columns.status'))
                    ->badge()
                    ->state(fn (DeviceCommand $record): string => $record->status === 'pending' && $record->expires_at->isPast()
                        ? 'expired'
                        : $record->status)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => __('filament/resources/device-commands.statuses.pending'),
                        'offered' => __('filament/resources/device-commands.statuses.offered'),
                        'acknowledged' => __('filament/resources/device-commands.statuses.acknowledged'),
                        'attendance_received' => __('filament/resources/device-commands.statuses.attendance_received'),
                        'unknown' => __('filament/resources/device-commands.statuses.unknown'),
                        'expired' => __('filament/resources/device-commands.statuses.expired'),
                        'cancelled' => __('filament/resources/device-commands.statuses.cancelled'),
                        'failed' => __('filament/resources/device-commands.statuses.failed'),
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('requested_at')->label(__('filament/resources/device-commands.columns.requested_at'))->dateTime()->sortable(),
                TextColumn::make('offered_at')->label(__('filament/resources/device-commands.columns.offered_at'))->dateTime(),
                TextColumn::make('wake_sent_at')->label(__('filament/resources/device-commands.columns.wake_sent_at'))->dateTime()->placeholder(__('filament.common.not_sent')),
                TextColumn::make('wake_error')->label(__('filament/resources/device-commands.columns.wake_error'))->limit(60)->tooltip(fn (DeviceCommand $record): ?string => $record->wake_error)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result_received_at')->label(__('filament/resources/device-commands.columns.result_received_at'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('raw_result')
                    ->label(__('filament/resources/device-commands.columns.raw_result'))
                    ->limit(80)
                    ->tooltip(fn (DeviceCommand $record): ?string => $record->raw_result)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attendance_uploads_count')->label(__('filament/resources/device-commands.columns.attendance_uploads_count'))->counts('attendanceUploads')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')->label(__('filament/resources/device-commands.columns.expires_at'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('device'))
            ->filters([
                SelectFilter::make('company_id')
                    ->label(__('filament/resources/device-commands.filters.company'))
                    ->options(fn (): array => Company::query()
                        ->whereIn('id', CompanyAccess::scope(Company::query())->select('id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $companyId): Builder => $query->whereHas('device', fn (Builder $deviceQuery): Builder => $deviceQuery->where('company_id', $companyId)),
                    )),
                SelectFilter::make('device_id')
                    ->label(__('filament/resources/device-commands.filters.device'))
                    ->relationship('device', 'serial_number', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))
                    ->searchable(),
                SelectFilter::make('type')
                    ->label(__('filament/resources/device-commands.filters.type'))
                    ->options(fn (): array => DeviceCommand::query()
                        ->select('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type')
                        ->mapWithKeys(fn (string $type): array => [$type => str($type)->replace('guide_', '')->headline()->toString()])
                        ->all()),
                SelectFilter::make('status')
                    ->label(__('filament/resources/device-commands.filters.status'))
                    ->options([
                        'pending' => __('filament/resources/device-commands.statuses.pending'),
                        'offered' => __('filament/resources/device-commands.statuses.offered'),
                        'acknowledged' => __('filament/resources/device-commands.statuses.acknowledged'),
                        'attendance_received' => __('filament/resources/device-commands.statuses.attendance_received'),
                        'unknown' => __('filament/resources/device-commands.statuses.unknown'),
                        'expired' => __('filament/resources/device-commands.statuses.expired'),
                        'cancelled' => __('filament/resources/device-commands.statuses.cancelled'),
                        'failed' => __('filament/resources/device-commands.statuses.failed'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;
                        if (! is_string($status) || $status === '') {
                            return $query;
                        }

                        if ($status === 'expired') {
                            return $query->where(fn (Builder $query): Builder => $query
                                ->where('status', 'expired')
                                ->orWhere(fn (Builder $query): Builder => $query
                                    ->where('status', 'pending')
                                    ->where('expires_at', '<=', now())));
                        }

                        return $query->where('status', $status)
                            ->when($status === 'pending', fn (Builder $query): Builder => $query->where('expires_at', '>', now()));
                    }),
                Filter::make('requested_at')
                    ->label(__('filament/resources/device-commands.filters.requested_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('filament/resources/device-commands.filters.from')),
                        DatePicker::make('until')->label(__('filament/resources/device-commands.filters.until')),
                    ])
                    ->columns(2)->columnSpan(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('requested_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('requested_at', '<=', $date));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('cancelPending')
                    ->label(__('filament/resources/device-commands.actions.cancel_pending'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('filament/resources/device-commands.actions.cancel_pending_heading'))
                    ->modalDescription(__('filament/resources/device-commands.actions.cancel_pending_description'))
                    ->visible(fn (DeviceCommand $record): bool => $record->status === 'pending')
                    ->action(function (DeviceCommand $record, DeviceCommandService $commands): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        if (! $commands->cancelPendingCommand($record, $user)) {
                            Notification::make()
                                ->title(__('filament/resources/device-commands.actions.cancel_pending_unavailable'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('filament/resources/device-commands.actions.cancel_pending_success'))
                            ->success()
                            ->send();
                    }),
                Action::make('viewResult')
                    ->label(__('filament/resources/device-commands.actions.view_result'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(__('filament/resources/device-commands.actions.view_result_heading'))
                    ->modalContent(fn (DeviceCommand $record): HtmlString => self::formatResultContent($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament/resources/device-commands.actions.close')),
                Action::make('downloadQueryData')
                    ->label(__('filament/resources/device-commands.actions.download_query_data'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (DeviceCommand $record): bool => auth()->user()?->isSuperAdmin()
                        && in_array($record->type, ['get_device_options', 'guide_query_users', 'guide_query_templates'], true)
                        && is_string($record->encrypted_payload)
                        && $record->encrypted_payload !== '')
                    ->action(function (DeviceCommand $record): StreamedResponse {
                        abort_unless(auth()->user()?->isSuperAdmin(), 403);

                        $fileName = sprintf(
                            'device-%s-command-%d.txt',
                            preg_replace('/[^A-Za-z0-9_-]/', '_', $record->device->serial_number),
                            $record->wire_command_id,
                        );
                        $payload = $record->encrypted_payload;

                        return response()->streamDownload(
                            static function () use ($payload): void {
                                echo $payload;
                            },
                            $fileName,
                            ['Content-Type' => 'text/plain; charset=utf-8'],
                        );
                    }),
            ])
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

    public static function formatResultContent(DeviceCommand $record): HtmlString
    {
        if ($record->type !== 'get_device_options') {
            return new HtmlString('<pre>'.e($record->raw_result ?? __('filament/resources/device-commands.no_result')).'</pre>');
        }

        $options = self::parseDeviceOptions($record->encrypted_payload ?? '');
        if ($options === []) {
            return new HtmlString('<pre>'.e($record->raw_result ?? __('filament/resources/device-commands.no_result')).'</pre>');
        }

        $rows = '';
        foreach ($options as $key => $value) {
            $normalizedKey = ltrim($key, '~');
            $label = match ($normalizedKey) {
                'SerialNumber' => __('filament/resources/device-commands.option_fields.serial_number'),
                'FirmVer' => __('filament/resources/device-commands.option_fields.firmware_version'),
                'DeviceName' => __('filament/resources/device-commands.option_fields.device_name'),
                'IPAddress' => __('filament/resources/device-commands.option_fields.ip_address'),
                'NetMask' => __('filament/resources/device-commands.option_fields.network_mask'),
                'GATEIPAddress' => __('filament/resources/device-commands.option_fields.gateway_ip_address'),
                'ComPwd' => __('filament/resources/device-commands.option_fields.communication_password'),
                default => str($normalizedKey)->headline()->toString(),
            };
            $displayValue = $normalizedKey === 'ComPwd'
                ? __('filament/resources/device-commands.sensitive_value_hidden')
                : $value;

            $rows .= '<tr><th scope="row">'.e($label).'</th><td>'.e($displayValue).'</td></tr>';
        }

        return new HtmlString('<table><tbody>'.$rows.'</tbody></table>');
    }

    /** @return array<string, string> */
    private static function parseDeviceOptions(string $payload): array
    {
        preg_match_all('/(?:^|[,\r\n])\s*(~?[A-Za-z][A-Za-z0-9_]*)=([^,\r\n]*)/', $payload, $matches, PREG_SET_ORDER);

        $options = [];
        foreach ($matches as $match) {
            $key = trim($match[1]);
            if (in_array($key, ['ID', 'Return', 'CMD'], true)) {
                continue;
            }

            $options[$key] = trim($match[2]);
        }

        return $options;
    }
}
