<?php

namespace App\Filament\Widgets;

use App\Services\Adms\DashboardQuery;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class AttendanceTrend extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return DashboardQuery::canAccess('AttendancePunch');
    }

    public function getHeading(): string
    {
        return __('filament/dashboard.attendance_trend');
    }

    public function getDescription(): string
    {
        return __('filament/dashboard.trend_description');
    }

    protected function getData(): array
    {
        $trend = (new DashboardQuery($this->pageFilters ?? []))->attendanceTrend();

        return [
            'datasets' => [['label' => __('filament/dashboard.punches'), 'data' => $trend['counts'], 'borderRadius' => 4]],
            'labels' => $trend['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
