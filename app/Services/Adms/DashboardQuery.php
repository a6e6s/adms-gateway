<?php

namespace App\Services\Adms;

use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DiscoveredDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class DashboardQuery
{
    private const TREND_BUCKET_ALIASES = ['bucket_0', 'bucket_1', 'bucket_2', 'bucket_3', 'bucket_4', 'bucket_5', 'bucket_6', 'bucket_7', 'bucket_8', 'bucket_9', 'bucket_10', 'bucket_11', 'bucket_12', 'bucket_13', 'bucket_14', 'bucket_15', 'bucket_16', 'bucket_17', 'bucket_18', 'bucket_19', 'bucket_20', 'bucket_21', 'bucket_22', 'bucket_23', 'bucket_24', 'bucket_25', 'bucket_26', 'bucket_27', 'bucket_28', 'bucket_29', 'bucket_30'];

    /** @param array<string, mixed> $filters */
    public function __construct(private readonly array $filters = []) {}

    public static function canAccess(string $subject): bool
    {
        $user = auth()->user();

        return $user instanceof User && (
            $user->hasRole(config('filament-shield.super_admin.name')) || $user->can('ViewAny:'.$subject)
        );
    }

    public static function timezone(): string
    {
        return config('services.adms.dashboard_timezone', 'Asia/Riyadh');
    }

    public static function onlineCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes(max(1, (int) config('services.adms.device_online_window_minutes', 5)));
    }

    /** @return Builder<Device> */
    public function devices(): Builder
    {
        return $this->scopeCompany(Device::query(), 'Device');
    }

    /** @return Builder<AttendanceUpload> */
    public function uploads(): Builder
    {
        return $this->scopeCompany(AttendanceUpload::query(), 'AttendanceUpload');
    }

    /** @return Builder<AttendancePunch> */
    public function punches(): Builder
    {
        $query = $this->scopeCompany(AttendancePunch::query(), 'AttendancePunch');
        $range = $this->dateRange();

        if ($range === null) {
            return $query->whereRaw('1 = 0');
        }

        [$start, $end] = $range;

        return $query->where('occurred_at_utc', '>=', $start->utc())
            ->where('occurred_at_utc', '<', $end->addDay()->utc());
    }

    /** @return array{labels: list<string>, counts: list<int>} */
    public function attendanceTrend(): array
    {
        $range = $this->dateRange();
        if ($range === null || ! self::canAccess('AttendancePunch')) {
            return ['labels' => [], 'counts' => []];
        }

        [$start, $end] = $range;
        $daysPerBucket = max(1, (int) ceil(($start->diffInDays($end) + 1) / 31));
        $query = $this->punches()->toBase();
        $labels = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $next) {
            $next = $day->addDays($daysPerBucket)->min($end->addDay());
            $index = count($labels);
            $labels[] = $daysPerBucket === 1 ? $day->format('Y-m-d') : $day->format('Y-m-d').' – '.$next->subDay()->format('Y-m-d');
            $query->selectRaw(
                'COUNT(CASE WHEN occurred_at_utc >= ? AND occurred_at_utc < ? THEN 1 END) AS '.self::TREND_BUCKET_ALIASES[$index],
                [$day->utc(), $next->utc()],
            );
        }

        $totals = (array) $query->first();
        $counts = [];
        foreach ($labels as $index => $label) {
            $counts[] = (int) ($totals['bucket_'.$index] ?? 0);
        }

        return ['labels' => $labels, 'counts' => $counts];
    }

    /** @return Builder<DeviceCommand> */
    public function commands(): Builder
    {
        $query = DeviceCommand::query();
        if (! self::canAccess('DeviceCommand')) {
            return $query->whereRaw('1 = 0');
        }
        $companyId = $this->companyId();

        return $companyId === null ? $query : $query->whereHas('device', fn (Builder $device) => $device->where('company_id', $companyId));
    }

    /** @return Builder<DiscoveredDevice> */
    public function discoveries(): Builder
    {
        $query = DiscoveredDevice::query()->whereNotIn('serial_number', Device::query()->select('serial_number'));

        return self::canAccess('Device') ? $query : $query->whereRaw('1 = 0');
    }

    /** @return Builder<Device> */
    public function onlineDevices(): Builder
    {
        return $this->devices()->where('is_enabled', true)->whereHas('company', fn (Builder $query) => $query->where('is_active', true))
            ->where('last_seen_at', '>', self::onlineCutoff());
    }

    /** @return Builder<Device> */
    public function devicesNeedingAttention(): Builder
    {
        return $this->devices()->where('is_enabled', true)->whereHas('company', fn (Builder $query) => $query->where('is_active', true))
            ->where(fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', self::onlineCutoff()));
    }

    /** @return Builder<AttendanceUpload> */
    public function uploadsNeedingAttention(): Builder
    {
        $cutoff = CarbonImmutable::now()->subMinutes(max(1, (int) config('services.adms.pending_upload_warning_minutes', 5)));

        return $this->uploads()->where(fn (Builder $query) => $query
            ->whereIn('status', ['failed', 'processed_with_errors'])
            ->orWhere(fn (Builder $pending) => $pending->where('status', 'pending')->where('received_at', '<=', $cutoff))
            ->orWhere(fn (Builder $processing) => $processing->where('status', 'processing')->where('processing_lease_expires_at', '<=', now())));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeCompany(Builder $query, string $subject): Builder
    {
        if (! self::canAccess($subject)) {
            return $query->whereRaw('1 = 0');
        }

        $companyId = $this->companyId();

        return $companyId === null ? $query : $query->where('company_id', $companyId);
    }

    private function companyId(): ?int
    {
        $company = $this->filters['company_id'] ?? null;
        if ($company === null || $company === '') {
            return null;
        }

        $companyId = (is_string($company) || is_int($company)) ? filter_var($company, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $companyId === false ? -1 : $companyId;
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function dateRange(): ?array
    {
        $today = CarbonImmutable::now(self::timezone())->format('Y-m-d');
        $start = $this->parseDate($this->filters['start_date'] ?? $today);
        $end = $this->parseDate($this->filters['end_date'] ?? $today);

        return $start === null || $end === null || $start->greaterThan($end) ? null : [$start, $end];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::timezone());

            return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
