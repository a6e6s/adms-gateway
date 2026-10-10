<?php

namespace App\Filament\Resources\AttendanceUploads;

use App\Filament\Resources\AttendanceUploads\Pages\ManageAttendanceUploads;
use App\Filament\Resources\CompanyScopedResource;
use App\Jobs\ProcessAttendanceUpload;
use App\Models\AttendanceUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class AttendanceUploadResource extends CompanyScopedResource
{
    protected static ?string $model = AttendanceUpload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('filament/resources/attendance-uploads.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament/resources/attendance-uploads.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Upload receipts are intentionally read-only.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label(__('filament/resources/attendance-uploads.columns.id'))->sortable(),
                TextColumn::make('device.serial_number')->label(__('filament/resources/attendance-uploads.columns.device'))->searchable(),
                TextColumn::make('received_at')->label(__('filament/resources/attendance-uploads.columns.received_at'))->dateTime()->sortable(),
                TextColumn::make('byte_count')->label(__('filament/resources/attendance-uploads.columns.byte_count'))->numeric()->sortable(),
                TextColumn::make('status')->label(__('filament/resources/attendance-uploads.columns.status'))->badge()->sortable()->formatStateUsing(fn (string $state): string => __("filament/resources/attendance-uploads.statuses.{$state}")),
                TextColumn::make('total_rows')->label(__('filament/resources/attendance-uploads.columns.total_rows'))->numeric(),
                TextColumn::make('inserted_rows')->label(__('filament/resources/attendance-uploads.columns.inserted_rows'))->numeric(),
                TextColumn::make('duplicate_rows')->label(__('filament/resources/attendance-uploads.columns.duplicate_rows'))->numeric(),
                TextColumn::make('rejected_rows')->label(__('filament/resources/attendance-uploads.columns.rejected_rows'))->numeric(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('device'))
            ->filters([SelectFilter::make('status')->label(__('filament/resources/attendance-uploads.columns.status'))->options([
                'pending' => __('filament/resources/attendance-uploads.statuses.pending'),
                'processing' => __('filament/resources/attendance-uploads.statuses.processing'),
                'processed' => __('filament/resources/attendance-uploads.statuses.processed'),
                'processed_with_errors' => __('filament/resources/attendance-uploads.statuses.processed_with_errors'),
                'failed' => __('filament/resources/attendance-uploads.statuses.failed'),
            ])])
            ->recordActions([
                Action::make('retry')
                    ->label(__('filament/resources/attendance-uploads.actions.retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->visible(fn (AttendanceUpload $record): bool => static::canEdit($record) && in_array($record->status, ['failed', 'processed_with_errors'], true))
                    ->action(function (AttendanceUpload $record): void {
                        Gate::authorize('update', $record);
                        $record->forceFill([
                            'status' => 'pending', 'processing_token' => null,
                            'processing_lease_expires_at' => null, 'last_dispatched_at' => null,
                            'checkpoint_line' => 0, 'total_rows' => 0, 'inserted_rows' => 0,
                            'duplicate_rows' => 0, 'rejected_rows' => 0, 'errors' => null,
                            'processed_at' => null,
                        ])->save();
                        ProcessAttendanceUpload::dispatch($record->id)->afterCommit();
                        Notification::make()->title(__('filament/resources/attendance-uploads.notifications.retry_queued'))->success()->send();
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
            'index' => ManageAttendanceUploads::route('/'),
        ];
    }
}
