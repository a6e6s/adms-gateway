<?php

namespace App\Filament\Resources\DeviceCommands;

use App\Filament\Resources\CompanyScopedResource;
use App\Filament\Resources\DeviceCommands\Pages\ManageDeviceCommands;
use App\Models\DeviceCommand;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            ->columns([
                TextColumn::make('device.serial_number')->label(__('filament/resources/device-commands.columns.device'))->searchable(),
                TextColumn::make('type')->label(__('filament/resources/device-commands.columns.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'request_attendance' => __('filament/resources/device-commands.types.request_attendance'),
                        'force_resend_attendance' => __('filament/resources/device-commands.types.force_resend_attendance'),
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('wire_command_id')->label(__('filament/resources/device-commands.columns.wire_command_id')),
                TextColumn::make('query_start_time')->label(__('filament/resources/device-commands.columns.query_start_time')),
                TextColumn::make('query_end_time')->label(__('filament/resources/device-commands.columns.query_end_time')),
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
                        'failed' => __('filament/resources/device-commands.statuses.failed'),
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('requested_at')->label(__('filament/resources/device-commands.columns.requested_at'))->dateTime()->sortable(),
                TextColumn::make('offered_at')->label(__('filament/resources/device-commands.columns.offered_at'))->dateTime(),
                TextColumn::make('wake_sent_at')->label(__('filament/resources/device-commands.columns.wake_sent_at'))->dateTime()->placeholder(__('filament.common.not_sent')),
                TextColumn::make('wake_error')->label(__('filament/resources/device-commands.columns.wake_error'))->limit(60)->tooltip(fn (DeviceCommand $record): ?string => $record->wake_error),
                TextColumn::make('result_received_at')->label(__('filament/resources/device-commands.columns.result_received_at'))->dateTime(),
                TextColumn::make('attendance_uploads_count')->label(__('filament/resources/device-commands.columns.attendance_uploads_count'))->counts('attendanceUploads'),
                TextColumn::make('expires_at')->label(__('filament/resources/device-commands.columns.expires_at'))->dateTime(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('device'))
            ->filters([])
            ->recordActions([])
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
}
