<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AttendanceTrend;
use App\Filament\Widgets\DevicesNeedingAttention;
use App\Filament\Widgets\DiscoveredDevices;
use App\Filament\Widgets\OperationalSummary;
use App\Filament\Widgets\RecentAttendance;
use App\Filament\Widgets\RecentDeviceCommands;
use App\Filament\Widgets\UploadsNeedingAttention;
use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DiscoveredDevice;
use App\Models\User;
use App\Services\Adms\DashboardQuery;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function dashboardAdministrator(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    return $user;
}

it('renders the operational dashboard and its default filters in both languages', function (string $locale) {
    $this->actingAs(dashboardAdministrator());
    $this->travelTo('2026-10-07 23:00:00 UTC');
    app()->setLocale($locale);

    Livewire::test(Dashboard::class)
        ->assertSet('filters.start_date', '2026-10-08')
        ->assertSet('filters.end_date', '2026-10-08')
        ->assertSee(__('filament/dashboard.filters'));
    Livewire::test(OperationalSummary::class)->assertSee(__('filament/dashboard.punches'));
})->with(['en', 'ar']);

it('shows stale and never-connected enabled devices only for the selected active company', function () {
    $this->actingAs(dashboardAdministrator());
    $this->travelTo('2026-10-07 12:00:00 UTC');
    $company = Company::factory()->create();
    $stale = Device::factory()->for($company)->create(['last_seen_at' => now()->subMinutes(5)]);
    $never = Device::factory()->for($company)->create();
    $online = Device::factory()->for($company)->create(['last_seen_at' => now()->subMinute()]);
    $disabled = Device::factory()->for($company)->create(['is_enabled' => false]);
    $other = Device::factory()->create();
    $inactiveCompany = Company::factory()->create(['is_active' => false]);
    $inactive = Device::factory()->for($inactiveCompany)->create();

    Livewire::test(DevicesNeedingAttention::class, ['pageFilters' => ['company_id' => $company->id]])
        ->assertCanSeeTableRecords([$stale, $never])->assertCanNotSeeTableRecords([$online, $disabled, $other, $inactive]);
    expect((new DashboardQuery(['company_id' => $company->id]))->onlineDevices()->count())->toBe(1);
});

it('shows failed rejected and stalled uploads without hiding older issues by date', function () {
    $this->actingAs(dashboardAdministrator());
    $this->travelTo('2026-10-07 12:00:00 UTC');
    $device = Device::factory()->create();
    $failed = AttendanceUpload::factory()->for($device)->create(['status' => 'failed', 'received_at' => now()->subMonth()]);
    $partial = AttendanceUpload::factory()->for($device)->create(['status' => 'processed_with_errors']);
    $pending = AttendanceUpload::factory()->for($device)->create(['received_at' => now()->subMinutes(6)]);
    $stalled = AttendanceUpload::factory()->for($device)->create(['status' => 'processing', 'processing_lease_expires_at' => now()->subMinute()]);
    $fresh = AttendanceUpload::factory()->for($device)->create();
    $processing = AttendanceUpload::factory()->for($device)->create(['status' => 'processing', 'processing_lease_expires_at' => now()->addMinute()]);
    $processed = AttendanceUpload::factory()->for($device)->create(['status' => 'processed']);
    $other = AttendanceUpload::factory()->create(['status' => 'failed']);

    Livewire::test(UploadsNeedingAttention::class, ['pageFilters' => ['company_id' => $device->company_id, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07']])
        ->assertCanSeeTableRecords([$failed, $partial, $pending, $stalled])->assertCanNotSeeTableRecords([$fresh, $processing, $processed, $other]);
});

it('filters attendance using Saudi day boundaries and the selected company', function () {
    $this->actingAs(dashboardAdministrator());
    $device = Device::factory()->create();
    $upload = AttendanceUpload::factory()->for($device)->create();
    $start = AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-06 21:00:00']);
    $end = AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-07 20:59:59']);
    $before = AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-06 20:59:59']);
    $after = AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-07 21:00:00']);
    $other = AttendancePunch::factory()->create(['occurred_at_utc' => '2026-10-07 10:00:00']);
    $filters = ['company_id' => $device->company_id, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07'];

    Livewire::test(RecentAttendance::class, ['pageFilters' => $filters])
        ->assertCanSeeTableRecords([$start, $end])->assertCanNotSeeTableRecords([$before, $after, $other]);
    expect((new DashboardQuery($filters))->punches()->count())->toBe(2);
});

it('returns no attendance for malformed or reversed filter ranges', function (array $filters) {
    $this->actingAs(dashboardAdministrator());
    $this->travelTo('2026-10-07 12:00:00 UTC');
    AttendancePunch::factory()->create();

    expect((new DashboardQuery($filters))->punches()->count())->toBe(0);
})->with([
    'invalid date' => [['start_date' => '2026-02-30']],
    'invalid type' => [['start_date' => ['2026-10-07']]],
    'reversed dates' => [['start_date' => '2026-10-08', 'end_date' => '2026-10-07']],
    'invalid company' => [['company_id' => ['1']]],
    'unknown company' => [['company_id' => 999999]],
]);

it('reuses discovery registration on the dashboard and keeps the device disabled', function () {
    $this->actingAs(dashboardAdministrator());
    $company = Company::factory()->create();
    $discovery = DiscoveredDevice::factory()->create();
    $registered = DiscoveredDevice::factory()->create();
    Device::factory()->create(['serial_number' => $registered->serial_number]);

    Livewire::test(DiscoveredDevices::class)
        ->assertCanSeeTableRecords([$discovery])->assertCanNotSeeTableRecords([$registered])
        ->callAction(TestAction::make('register')->table($discovery), data: [
            'company_id' => $company->id, 'name' => 'Entrance', 'timezone' => 'Asia/Riyadh',
        ])->assertHasNoFormErrors()->assertNotified()->assertCanNotSeeTableRecords([$discovery]);

    $this->assertDatabaseHas('devices', ['serial_number' => $discovery->serial_number, 'is_enabled' => false]);
});

it('hides unauthorized widgets and does not return their underlying data', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'panel_user', 'guard_name' => 'web']));
    $permission = Permission::create(['name' => 'ViewAny:AttendancePunch', 'guard_name' => 'web']);
    $user->givePermissionTo($permission);
    Device::factory()->create();
    AttendanceUpload::factory()->create();
    DiscoveredDevice::factory()->create();
    $this->actingAs($user);

    expect(RecentAttendance::canView())->toBeTrue();
    expect(DevicesNeedingAttention::canView())->toBeFalse();
    expect(UploadsNeedingAttention::canView())->toBeFalse();
    expect(DiscoveredDevices::canView())->toBeFalse();
    expect(RecentDeviceCommands::canView())->toBeFalse();
    expect((new DashboardQuery)->commands()->count())->toBe(0);
    expect((new DashboardQuery)->devices()->count())->toBe(0);
    expect((new DashboardQuery)->uploads()->count())->toBe(0);
    expect((new DashboardQuery)->discoveries()->count())->toBe(0);
    Livewire::test(OperationalSummary::class)->assertSee(__('filament/dashboard.punches'))
        ->assertDontSee(__('filament/dashboard.online_devices'))->assertDontSee(__('filament/dashboard.pending_uploads'));
});

it('renders useful empty states for the operational widgets', function (string $widget, string $message) {
    $this->actingAs(dashboardAdministrator());

    Livewire::test($widget)->assertSee(__($message));
})->with([
    [DevicesNeedingAttention::class, 'filament/dashboard.devices_healthy'],
    [UploadsNeedingAttention::class, 'filament/dashboard.no_upload_issues'],
    [RecentAttendance::class, 'filament/dashboard.no_attendance'],
    [DiscoveredDevices::class, 'filament/resources/discovered-devices.empty'],
    [RecentDeviceCommands::class, 'filament/dashboard.no_commands'],
]);

it('aggregates the attendance trend by reporting day including days without punches', function () {
    $this->actingAs(dashboardAdministrator());
    $device = Device::factory()->create();
    $upload = AttendanceUpload::factory()->for($device)->create();
    AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-06 21:00:00']);
    AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-07 20:59:59']);
    AttendancePunch::factory()->for($upload, 'upload')->create(['occurred_at_utc' => '2026-10-08 21:00:00']);
    AttendancePunch::factory()->create(['occurred_at_utc' => '2026-10-07 10:00:00']);
    $filters = ['company_id' => $device->company_id, 'start_date' => '2026-10-07', 'end_date' => '2026-10-09'];

    expect((new DashboardQuery($filters))->attendanceTrend())->toBe([
        'labels' => ['2026-10-07', '2026-10-08', '2026-10-09'], 'counts' => [2, 0, 1],
    ]);
    Livewire::test(AttendanceTrend::class, ['pageFilters' => $filters])->assertSee(__('filament/dashboard.attendance_trend'));
});

it('bounds chart buckets for long ranges without losing punches at the endpoints', function () {
    $this->actingAs(dashboardAdministrator());
    AttendancePunch::factory()->create(['occurred_at_utc' => '2025-12-31 21:00:00']);
    AttendancePunch::factory()->create(['occurred_at_utc' => '2026-12-31 20:59:59']);

    $trend = (new DashboardQuery(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']))->attendanceTrend();

    expect(count($trend['labels']))->toBeLessThanOrEqual(31);
    expect(array_sum($trend['counts']))->toBe(2);
});

it('shows company scoped commands and marks pending requests past expiry as expired', function () {
    $this->actingAs(dashboardAdministrator());
    $this->travelTo('2026-10-07 12:00:00 UTC');
    $device = Device::factory()->create();
    $expired = DeviceCommand::factory()->for($device)->create(['expires_at' => now()->subMinute()]);
    $received = DeviceCommand::factory()->for($device)->create(['status' => 'attendance_received']);
    $other = DeviceCommand::factory()->create();

    Livewire::test(RecentDeviceCommands::class, ['pageFilters' => ['company_id' => $device->company_id]])
        ->assertCanSeeTableRecords([$expired, $received])->assertCanNotSeeTableRecords([$other])
        ->assertSee(__('filament/resources/device-commands.statuses.expired'))
        ->assertSee(__('filament/resources/device-commands.statuses.attendance_received'));
    expect($expired->fresh()->status)->toBe('pending');
});
