<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\Device;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')->relationship('company', 'name')->required()->searchable()->preload(),
                TextInput::make('serial_number')->required()->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('location')->nullable()->maxLength(255),
                TextInput::make('expected_ip')->nullable()->ip()->maxLength(45),
                TextInput::make('timezone')->required()->rule('timezone')->default('UTC'),
                TextInput::make('protocol_profile')->required()->default('push-2.4-attlog-v1'),
                Toggle::make('is_enabled')->required()->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('company.name')->sortable(),
                TextColumn::make('push_version')->label('PUSH'),
                TextColumn::make('last_seen_at')->dateTime()->sortable()->placeholder('Never'),
                TextColumn::make('last_getrequest_at')->label('Last command poll')->dateTime()->sortable()->placeholder('Never'),
                TextColumn::make('attlog_stamp')->label('Attendance stamp')->placeholder('Not received'),
                IconColumn::make('is_enabled')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('company'))
            ->filters([])
            ->recordActions([
                EditAction::make(),
                Action::make('requestAttendance')
                    ->label('Request stored attendance')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->requiresConfirmation()
                    ->modalDescription('Choose a range in the device local timezone. The default range requests stored attendance from 2000 through now. The command is delivered on the next /iclock/getrequest poll; matching uploaded records are linked to this request and processed asynchronously.')
                    ->fillForm(fn (Device $record): array => [
                        'start_time' => now($record->timezone)->setDate(2000, 1, 1)->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                        'end_time' => now()->format('Y-m-d H:i:s'),
                    ])
                    ->schema([
                        DateTimePicker::make('start_time')
                            ->label(fn (Device $record): string => "Start time ({$record->timezone})")
                            ->native(false)
                            ->timezone(fn (Device $record): string => $record->timezone)
                            ->displayFormat('d/m/Y H:i:s')
                            ->seconds()
                            ->required()
                            ->beforeOrEqual('end_time'),
                        DateTimePicker::make('end_time')
                            ->label(fn (Device $record): string => "End time ({$record->timezone})")
                            ->native(false)
                            ->timezone(fn (Device $record): string => $record->timezone)
                            ->displayFormat('d/m/Y H:i:s')
                            ->seconds()
                            ->required()
                            ->afterOrEqual('start_time'),
                    ])
                    ->action(function (array $data, Device $record, DeviceCommandService $commands): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            $command = $commands->requestAttendance($record, $user, $data['start_time'], $data['end_time']);
                            $notification = Notification::make()->title($command->wake_sent_at !== null
                                ? 'Wake-up packet sent; waiting for device poll'
                                : 'Attendance request queued; waiting for device poll');

                            if ($command->wake_sent_at !== null) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        } catch (\DomainException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('forceResendAllAttendance')
                    ->label('Force full history resend')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Resend stored attendance for this date range?')
                    ->modalDescription('Queues an ATTLOG query in the device timezone. The device receives it on its next /iclock/getrequest poll. Existing attendance punches are deduplicated during processing.')
                    ->fillForm(fn (Device $record): array => [
                        'start_time' => now($record->timezone)->setDate(2000, 1, 1)->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                        'end_time' => now()->format('Y-m-d H:i:s'),
                    ])
                    ->schema([
                        DateTimePicker::make('start_time')
                            ->label(fn (Device $record): string => "Start time ({$record->timezone})")
                            ->native(false)
                            ->timezone(fn (Device $record): string => $record->timezone)
                            ->displayFormat('d/m/Y H:i:s')
                            ->seconds()
                            ->required()
                            ->beforeOrEqual('end_time'),
                        DateTimePicker::make('end_time')
                            ->label(fn (Device $record): string => "End time ({$record->timezone})")
                            ->native(false)
                            ->timezone(fn (Device $record): string => $record->timezone)
                            ->displayFormat('d/m/Y H:i:s')
                            ->seconds()
                            ->required()
                            ->afterOrEqual('start_time'),
                    ])
                    ->action(function (array $data, Device $record, DeviceCommandService $commands): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            $command = $commands->forceResendAllAttendance($record, $user, $data['start_time'], $data['end_time']);
                            $notification = Notification::make()->title($command->wake_sent_at !== null
                                ? 'Wake-up packet sent; waiting for device poll'
                                : 'Attendance resend queued; waiting for device poll');

                            if ($command->wake_sent_at !== null) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        } catch (\DomainException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDevices::route('/'),
        ];
    }
}
