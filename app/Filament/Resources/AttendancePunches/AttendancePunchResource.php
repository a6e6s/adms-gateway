<?php

namespace App\Filament\Resources\AttendancePunches;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\AttendancePunches\Pages\ManageAttendancePunches;
use App\Filament\Resources\CompanyScopedResource;
use App\Models\AttendancePunch;
use App\Services\CompanyAccess;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AttendancePunchResource extends CompanyScopedResource
{
    protected static ?string $model = AttendancePunch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/attendance-punches.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/attendance-punches.plural_model_label');
    }

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
                TextColumn::make('occurred_at_local')->label(__('filament/resources/attendance-punches.columns.device_time'))->sortable()->searchable(),
                TextColumn::make('pin')->label(__('filament/resources/attendance-punches.columns.pin'))->searchable()->sortable(),
                TextColumn::make('employee.name')->label(__('filament/resources/attendance-punches.columns.employee'))->placeholder(__('filament.common.unmapped')),
                TextColumn::make('device.serial_number')->label(__('filament/resources/attendance-punches.columns.device'))->searchable(),
                TextColumn::make('status_code')->label(__('filament/resources/attendance-punches.columns.status'))->formatStateUsing(fn (?string $state): string => AttendanceStatus::label($state)),
                TextColumn::make('verification_code')->label(__('filament/resources/attendance-punches.columns.verification_code')),
                TextColumn::make('timezone')->label(__('filament/resources/attendance-punches.columns.timezone')),
                TextColumn::make('received_at')->label(__('filament/resources/attendance-punches.columns.received_at'))->dateTime()->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['device', 'employee']))
            ->filters([SelectFilter::make('device_id')->label(__('filament/resources/attendance-punches.filters.device'))->relationship('device', 'serial_number', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))])
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
