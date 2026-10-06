<?php

namespace App\Filament\Resources\DeviceCommands;

use App\Filament\Resources\DeviceCommands\Pages\ManageDeviceCommands;
use App\Models\DeviceCommand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceCommandResource extends Resource
{
    protected static ?string $model = DeviceCommand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
                TextColumn::make('device.serial_number')->label('Device')->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'request_attendance' => 'Attendance range query',
                        'force_resend_attendance' => 'Force full history resend',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('wire_command_id')->label('Wire ID'),
                TextColumn::make('query_start_time')->label('From (device time)'),
                TextColumn::make('query_end_time')->label('Through (device time)'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (DeviceCommand $record): string => $record->status === 'pending' && $record->expires_at->isPast()
                        ? 'expired'
                        : $record->status)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Waiting for device poll',
                        'offered' => 'Sent to device',
                        'acknowledged' => 'Accepted by device',
                        'attendance_received' => 'Matching attendance received',
                        'unknown' => 'Delivery uncertain',
                        'expired' => 'Expired before delivery',
                        'failed' => 'Device rejected request',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('offered_at')->dateTime(),
                TextColumn::make('wake_sent_at')->label('UDP wake sent')->dateTime()->placeholder('Not sent'),
                TextColumn::make('wake_error')->label('Wake issue')->limit(60)->tooltip(fn (DeviceCommand $record): ?string => $record->wake_error),
                TextColumn::make('result_received_at')->dateTime(),
                TextColumn::make('attendance_uploads_count')->label('Matching uploads')->counts('attendanceUploads'),
                TextColumn::make('expires_at')->dateTime(),
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
