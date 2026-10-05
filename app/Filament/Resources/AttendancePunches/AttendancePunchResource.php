<?php

namespace App\Filament\Resources\AttendancePunches;

use App\Filament\Resources\AttendancePunches\Pages\ManageAttendancePunches;
use App\Models\AttendancePunch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendancePunchResource extends Resource
{
    protected static ?string $model = AttendancePunch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Source punches are immutable and are created by the upload processor.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at_local')->label('Device time')->sortable()->searchable(),
                TextColumn::make('pin')->searchable()->sortable(),
                TextColumn::make('employee.name')->label('Employee')->placeholder('Unmapped'),
                TextColumn::make('device.serial_number')->label('Device')->searchable(),
                TextColumn::make('status_code')->label('Status'),
                TextColumn::make('verification_code')->label('Verify'),
                TextColumn::make('timezone'),
                TextColumn::make('received_at')->dateTime()->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['device', 'employee']))
            ->filters([SelectFilter::make('device_id')->relationship('device', 'serial_number')])
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
            'index' => ManageAttendancePunches::route('/'),
        ];
    }
}
