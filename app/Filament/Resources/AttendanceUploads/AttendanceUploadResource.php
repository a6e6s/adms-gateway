<?php

namespace App\Filament\Resources\AttendanceUploads;

use App\Filament\Resources\AttendanceUploads\Pages\ManageAttendanceUploads;
use App\Jobs\ProcessAttendanceUpload;
use App\Models\AttendanceUpload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceUploadResource extends Resource
{
    protected static ?string $model = AttendanceUpload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
                TextColumn::make('id')->sortable(),
                TextColumn::make('device.serial_number')->label('Device')->searchable(),
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('byte_count')->numeric()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->numeric(),
                TextColumn::make('inserted_rows')->numeric(),
                TextColumn::make('duplicate_rows')->numeric(),
                TextColumn::make('rejected_rows')->numeric(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('device'))
            ->filters([SelectFilter::make('status')->options([
                'pending' => 'Pending', 'processing' => 'Processing', 'processed' => 'Processed',
                'processed_with_errors' => 'Processed with errors', 'failed' => 'Failed',
            ])])
            ->recordActions([
                Action::make('retry')
                    ->requiresConfirmation()
                    ->visible(fn (AttendanceUpload $record): bool => in_array($record->status, ['failed', 'processed_with_errors'], true))
                    ->action(function (AttendanceUpload $record): void {
                        $record->forceFill([
                            'status' => 'pending', 'processing_token' => null,
                            'processing_lease_expires_at' => null, 'last_dispatched_at' => null,
                            'checkpoint_line' => 0, 'total_rows' => 0, 'inserted_rows' => 0,
                            'duplicate_rows' => 0, 'rejected_rows' => 0, 'errors' => null,
                            'processed_at' => null,
                        ])->save();
                        ProcessAttendanceUpload::dispatch($record->id)->afterCommit();
                        Notification::make()->title('Upload queued for processing')->success()->send();
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
