<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\Device;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
                    ->modalDescription('The request is delivered the next time the device polls /iclock/getrequest. Any returned records are saved and processed asynchronously.')
                    ->action(function (Device $record, DeviceCommandService $commands): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            $commands->requestAttendance($record, $user);
                            Notification::make()->title('Attendance request waiting for device poll')->success()->send();
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
