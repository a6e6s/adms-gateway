<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceUploads\AttendanceUploadResource;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class UploadsNeedingAttention extends TableWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('AttendanceUpload');
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('filament/dashboard.uploads_attention'))
            ->description(__('filament/dashboard.uploads_description', ['minutes' => max(1, (int) config('services.adms.pending_upload_warning_minutes', 5))]))
            ->query(fn () => (new DashboardQuery($this->pageFilters ?? []))->uploadsNeedingAttention()
                ->select(['id', 'company_id', 'device_id', 'status', 'received_at', 'rejected_rows'])->with(['device:id,serial_number', 'company:id,name']))
            ->defaultSort('received_at', 'asc')->poll('30s')->paginationPageOptions([5])->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('id')->label(__('filament/resources/attendance-uploads.columns.id')),
                TextColumn::make('device.serial_number')->label(__('filament/resources/attendance-uploads.columns.device')),
                TextColumn::make('company.name')->label(__('filament/dashboard.company')),
                TextColumn::make('status')->label(__('filament/resources/attendance-uploads.columns.status'))
                    ->badge()->color(fn (string $state): string => in_array($state, ['failed', 'processed_with_errors'], true) ? 'danger' : 'warning')
                    ->formatStateUsing(fn (string $state): string => __('filament/resources/attendance-uploads.statuses.'.$state)),
                TextColumn::make('received_at')->label(__('filament/resources/attendance-uploads.columns.received_at'))->dateTime()->timezone(DashboardQuery::timezone()),
                TextColumn::make('rejected_rows')->label(__('filament/resources/attendance-uploads.columns.rejected_rows'))->numeric(),
            ])
            ->emptyStateHeading(__('filament/dashboard.no_upload_issues'))
            ->headerActions([Action::make('view_uploads')->label(__('filament/dashboard.review_uploads'))->url(AttendanceUploadResource::getUrl())]);
    }
}
