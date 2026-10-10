<?php

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use App\Services\Adms\DeviceCommandWaker;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationGroup;
use Livewire\Livewire;

it('returns the device attendance stamp during initialization', function () {
    $device = Device::factory()->create(['attlog_stamp' => '9999']);

    $response = $this->get('/iclock/cdata?SN='.$device->serial_number.'&options=all');

    $response->assertOk()->assertSeeText('ATTLOGStamp=9999');
});

it('stores an accepted attendance upload stamp before acknowledging the upload', function () {
    $device = Device::factory()->create();
    $payload = "12\t2026-10-05 11:49:53\t1\t1\t\t0\t0\t\n";

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=9999',
        content: $payload,
    );

    $response->assertOk()->assertContent('OK');
    $this->assertDatabaseHas('attendance_uploads', [
        'device_id' => $device->id,
        'source_stamp' => '9999',
        'raw_payload' => $payload,
    ]);
    expect($device->fresh()->attlog_stamp)->toBe('9999');
});

it('creates a placeholder employee and device mapping for a new attendance PIN', function () {
    $device = Device::factory()->create();
    $payload = "9123\t2026-10-05 11:49:53\t1\t1\t\t0\t0\t\n";

    $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=100',
        content: $payload,
    )->assertOk();

    $employee = Employee::query()
        ->where('company_id', $device->company_id)
        ->where('employee_number', '9123')
        ->firstOrFail();
    $deviceEmployee = DeviceEmployee::query()
        ->where('device_id', $device->id)
        ->where('pin', '9123')
        ->firstOrFail();

    expect($employee->name)->toBe('Device PIN 9123');
    expect($deviceEmployee->employee_id)->toBe($employee->id);
    $this->assertDatabaseHas('attendance_punches', [
        'device_id' => $device->id,
        'pin' => '9123',
        'device_employee_id' => $deviceEmployee->id,
    ]);

    $deviceEmployee->delete();

    $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=101',
        content: $payload,
    )->assertOk();

    $this->assertDatabaseCount('employees', 1);
    $this->assertDatabaseCount('device_employees', 1);
    $this->assertDatabaseCount('attendance_punches', 1);
    $this->assertDatabaseHas('attendance_punches', [
        'device_id' => $device->id,
        'pin' => '9123',
        'device_employee_id' => DeviceEmployee::query()->where('device_id', $device->id)->value('id'),
    ]);
    $this->assertDatabaseHas('attendance_uploads', [
        'device_id' => $device->id,
        'duplicate_rows' => 1,
        'status' => 'processed',
    ]);
});

it('records command polling and offers the pending attendance request', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent("C:1:DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59\n");
    expect($command->fresh()->status)->toBe('offered');
    expect($device->fresh()->last_getrequest_at)->not->toBeNull();
});

it('accepts a replayed result for a known pending command', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: 'ID=1&Return=0&CMD=DATA'."\n",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
    expect($command->fresh()->result_received_at)->not->toBeNull();
    $this->assertDatabaseHas('device_commands', [
        'id' => $command->id,
        'raw_result' => "ID=1&Return=0&CMD=DATA\n",
    ]);
});

it('expires an overdue attendance request when the device polls', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');
    $command->forceFill(['expires_at' => now()->subMinute()])->save();

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('expired');
});

it('converts a requested range to the device timezone before creating the command', function () {
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $user = User::factory()->superAdmin()->create();

    $command = app(DeviceCommandService::class)->requestAttendance(
        $device,
        $user,
        '2026-10-04 21:00:00',
        '2026-10-05 20:59:59',
    );

    expect($command->query_start_time)->toBe('2026-10-05 00:00:00');
    expect($command->query_end_time)->toBe('2026-10-05 23:59:59');
    expect($command->wire_payload)->toBe("DATA QUERY ATTLOG StartTime=2026-10-05 00:00:00\tEndTime=2026-10-05 23:59:59");
});

it('queues a date-ranged resend for the device to fetch on its next poll', function () {
    $this->travelTo('2026-10-06 12:00:00 UTC');
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $user = User::factory()->superAdmin()->create();

    $command = app(DeviceCommandService::class)->forceResendAllAttendance(
        $device,
        $user,
        '2026-10-04 21:00:00',
        '2026-10-05 20:59:59',
    );
    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    expect($command->type)->toBe('force_resend_attendance');
    expect($command->query_start_time)->toBe('2026-10-05 00:00:00');
    expect($command->query_end_time)->toBe('2026-10-05 23:59:59');
    expect($command->expires_at->format('Y-m-d H:i:s'))->toBe('2026-10-07 12:00:00');
    expect($command->wake_error)->toBe('No valid last-seen device IP is available for the wake-up packet.');
    $response->assertOk()->assertContent("C:1:DATA QUERY ATTLOG StartTime=2026-10-05 00:00:00\tEndTime=2026-10-05 23:59:59\n");
    expect($command->fresh()->status)->toBe('offered');

    $uploadResponse = $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=100',
        content: "12\t2026-10-05 11:49:53\t1\t1\t\t0\t0\t\n",
    );

    $uploadResponse->assertOk();
    $this->assertDatabaseHas('attendance_uploads', [
        'device_id' => $device->id,
        'device_command_id' => $command->id,
        'status' => 'processed',
    ]);
    expect($command->fresh()->status)->toBe('attendance_received');

    $resultResponse = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: 'ID=1&Return=0&CMD=DATA',
    );

    $resultResponse->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('attendance_received');
    expect($command->fresh()->result_received_at)->not->toBeNull();
});

it('accepts an earlier start time in the force history resend modal', function () {
    $this->travelTo('2026-10-06 09:36:04 Asia/Riyadh');
    $user = User::factory()->superAdmin()->create();
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->selectTableRecords([$device->id])
        ->callAction(
            TestAction::make('forceResendAllAttendance')->table()->bulk(),
            data: [
                'start_time' => '2026-09-01 00:00:00',
                'end_time' => '2026-10-06 09:36:04',
            ],
        )
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('device_commands', [
        'device_id' => $device->id,
        'query_start_time' => '2026-09-01 03:00:00',
        'query_end_time' => '2026-10-06 12:36:04',
    ]);
});

it('rejects a start time after the end time in the force history resend modal', function () {
    $user = User::factory()->superAdmin()->create();
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->selectTableRecords([$device->id])
        ->callAction(
            TestAction::make('forceResendAllAttendance')->table()->bulk(),
            data: [
                'start_time' => '2026-10-07 09:36:04',
                'end_time' => '2026-10-06 09:36:04',
            ],
        )
        ->assertHasFormErrors([
            'start_time' => 'before_or_equal',
            'end_time' => 'after_or_equal',
        ]);

    $this->assertDatabaseCount('device_commands', 0);
});

it('queues an attendance command for every selected device', function () {
    $user = User::factory()->superAdmin()->create();
    $devices = Device::factory()->count(2)->create();
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->selectTableRecords($devices->modelKeys())
        ->callAction(TestAction::make('requestAttendance')->table()->bulk(), data: [
            'start_time' => '2026-10-01 00:00:00',
            'end_time' => '2026-10-06 12:00:00',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseCount('device_commands', 2);
});

it('updates boolean resource columns through table switches', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    $company = Company::factory()->create(['is_active' => true]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    $device = Device::factory()->create(['company_id' => $company->id, 'is_enabled' => true]);

    Livewire::test(ManageCompanies::class)
        ->call('updateTableColumnState', 'is_active', (string) $company->id, false);
    Livewire::test(ManageEmployees::class)
        ->call('updateTableColumnState', 'is_active', (string) $employee->id, false);
    Livewire::test(ManageDevices::class)
        ->call('updateTableColumnState', 'is_enabled', (string) $device->id, false);

    expect($company->fresh()->is_active)->toBeFalse()
        ->and($employee->fresh()->is_active)->toBeFalse()
        ->and($device->fresh()->is_enabled)->toBeFalse();
});

it('renders the Arabic panel in right-to-left mode when Arabic is selected', function () {
    $response = $this->withSession(['filament_locale' => 'ar'])->get('/admin/login');

    $response->assertOk()
        ->assertSee('بوابة ADMS')
        ->assertSee('lang="ar"', escape: false)
        ->assertSee('dir="rtl"', escape: false);
});

it('shows a visible language switcher in the authenticated admin top bar', function () {
    app()->setLocale('en');
    $this->actingAs(User::factory()->superAdmin()->create());

    $html = view('filament.partials.language-switcher')->render();

    expect($html)->toContain('adms-language-switcher')
        ->and($html)->toContain('name="locale" value="en"')
        ->and($html)->toContain('name="locale" value="ar"')
        ->and($html)->toContain('>EN</button>')
        ->and($html)->toContain('>عربي</button>');
});

it('stores the selected admin language in the session', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->from('/admin')
        ->post('/admin/language', ['locale' => 'ar'])
        ->assertRedirect('/admin')
        ->assertSessionHas('filament_locale', 'ar');
});

it('derives device online status from the configurable last seen window', function () {
    config(['services.adms.device_online_window_minutes' => 5]);

    $onlineDevice = Device::factory()->create();
    $onlineDevice->forceFill(['last_seen_at' => now()->subMinutes(4)])->save();
    $offlineDevice = Device::factory()->create();
    $offlineDevice->forceFill(['last_seen_at' => now()->subMinutes(6)])->save();
    $neverSeenDevice = Device::factory()->create();

    expect($onlineDevice->isOnline())->toBeTrue()
        ->and($offlineDevice->isOnline())->toBeFalse()
        ->and($neverSeenDevice->isOnline())->toBeFalse();
});

it('localizes resource labels and navigation in Arabic', function () {
    app()->setLocale('ar');
    $groups = collect(filament()->getPanel('admin')->getNavigationGroups())
        ->map(fn ($group): ?string => $group instanceof NavigationGroup ? $group->getLabel() : $group);

    expect($groups)->toContain('الأجهزة')
        ->and(DeviceResource::getPluralModelLabel())->toBe('الأجهزة')
        ->and(__('filament/resources/devices.columns.serial_number'))->toBe('الرقم التسلسلي');
});

it('translates attendance status codes using the active panel language', function () {
    app()->setLocale('en');

    expect(AttendanceStatus::label('0'))->toBe('Check-in');

    app()->setLocale('ar');

    expect(AttendanceStatus::label('0'))->toBe('تسجيل حضور')
        ->and(AttendanceStatus::label('99'))->toBe('حالة غير مصنفة (99)');
});

it('records a successful device wake-up while keeping the command queued for polling', function () {
    $device = Device::factory()->create(['last_seen_ip' => '192.168.100.14']);
    $user = User::factory()->superAdmin()->create();
    $waker = Mockery::mock(DeviceCommandWaker::class);
    $waker->shouldReceive('wake')->once()->with($device);
    $this->app->instance(DeviceCommandWaker::class, $waker);

    $command = app(DeviceCommandService::class)->forceResendAllAttendance(
        $device,
        $user,
        '2026-10-05 00:00:00',
        '2026-10-05 23:59:59',
    );

    expect($command->status)->toBe('pending');
    expect($command->wake_sent_at)->not->toBeNull();
    expect($command->wake_error)->toBeNull();
});

it('links matching historical attendance uploads to the offered query', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance(
        $device,
        $user,
        '2000-01-01 00:00:00',
        '2000-01-31 23:59:59',
    );
    $command->forceFill(['status' => 'offered', 'offered_at' => now()])->save();

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=9999',
        content: "12\t2000-01-15 11:49:53\t1\t1\t\t0\t0\t\n",
    );

    $response->assertOk();
    $this->assertDatabaseHas('attendance_uploads', [
        'device_id' => $device->id,
        'device_command_id' => $command->id,
        'status' => 'processed',
    ]);
    expect($command->fresh()->status)->toBe('attendance_received');
    $this->assertDatabaseHas('attendance_punches', [
        'device_id' => $device->id,
        'pin' => '12',
        'occurred_at_local' => '2000-01-15 11:49:53',
    ]);
});

it('does not link an attendance upload outside the requested time range', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance(
        $device,
        $user,
        '2000-01-01 00:00:00',
        '2000-01-31 23:59:59',
    );
    $command->forceFill(['status' => 'offered', 'offered_at' => now()])->save();

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=ATTLOG&Stamp=9999',
        content: "12\t2001-01-15 11:49:53\t1\t1\t\t0\t0\t\n",
    );

    $response->assertOk();
    $this->assertDatabaseHas('attendance_uploads', [
        'device_id' => $device->id,
        'device_command_id' => null,
        'status' => 'processed',
    ]);
    expect($command->fresh()->status)->toBe('offered');
});
