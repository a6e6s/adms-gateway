<?php

namespace App\Filament\Resources\AttendancePunches;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\AttendancePunches\Pages\ManageAttendancePunches;
use App\Filament\Resources\CompanyScopedResource;
use App\Models\AttendancePunch;
use App\Services\CompanyAccess;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
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
            ->defaultSort('received_at', 'desc')
            ->paginationPageOptions([25])
            ->defaultPaginationPageOption(25)
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
            ->filters([
                SelectFilter::make('device_id')
                    ->label(__('filament/resources/attendance-punches.filters.device'))
                    ->relationship('device', 'serial_number', modifyQueryUsing: fn (Builder $query): Builder => CompanyAccess::scope($query))
                    ->searchable(),
                SelectFilter::make('status_code')
                    ->label(__('filament/resources/attendance-punches.columns.status'))
                    ->options([
                        '0' => AttendanceStatus::label('0'),
                        '1' => AttendanceStatus::label('1'),
                        '2' => AttendanceStatus::label('2'),
                        '3' => AttendanceStatus::label('3'),
                        '4' => AttendanceStatus::label('4'),
                        '5' => AttendanceStatus::label('5'),
                        'unknown' => __('filament/resources/attendance-punches.status_codes.unknown'),
                        'unmapped' => __('filament/resources/attendance-punches.status_codes.filter_unmapped'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;
                        if (! is_string($status) || $status === '') {
                            return $query;
                        }

                        if ($status === 'unknown') {
                            return $query->where(fn (Builder $query): Builder => $query->whereNull('status_code')->orWhere('status_code', ''));
                        }

                        if ($status === 'unmapped') {
                            return $query->whereNotNull('status_code')->where('status_code', '!=', '')->whereNotIn('status_code', ['0', '1', '2', '3', '4', '5']);
                        }

                        return $query->where('status_code', $status);
                    }),
                Filter::make('occurred_at_local')
                    ->label(__('filament/resources/attendance-punches.filters.device_time'))
                    ->schema([
                        DatePicker::make('from')->label(__('filament/resources/attendance-punches.filters.from')),
                        DatePicker::make('until')->label(__('filament/resources/attendance-punches.filters.until')),
                    ])
                    ->columns(2)->columnSpan(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at_local', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at_local', '<=', $date))),
            ], layout: FiltersLayout::AboveContent)
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
