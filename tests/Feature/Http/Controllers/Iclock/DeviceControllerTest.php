<?php

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Models\Device;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;
use App\Services\Adms\DeviceCommandWaker;
use Filament\Actions\Testing\TestAction;
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
    $user = User::factory()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent("C:1:DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59\n");
    expect($command->fresh()->status)->toBe('offered');
    expect($device->fresh()->last_getrequest_at)->not->toBeNull();
});

it('accepts a replayed result for a known pending command', function () {
    $device = Device::factory()->create();
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');
    $command->forceFill(['expires_at' => now()->subMinute()])->save();

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('expired');
});

it('converts a requested range to the device timezone before creating the command', function () {
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $user = User::factory()->create();

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
    $user = User::factory()->create();

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
    $user = User::factory()->create();
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->callAction(
            TestAction::make('forceResendAllAttendance')->table($device),
            data: [
                'start_time' => '2026-09-01 00:00:00',
                'end_time' => '2026-10-06 09:36:04',
            ],
        )
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('device_commands', [
        'device_id' => $device->id,
        'query_start_time' => '2026-09-01 00:00:00',
        'query_end_time' => '2026-10-06 09:36:04',
    ]);
});

it('rejects a start time after the end time in the force history resend modal', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->callAction(
            TestAction::make('forceResendAllAttendance')->table($device),
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

it('records a successful device wake-up while keeping the command queued for polling', function () {
    $device = Device::factory()->create(['last_seen_ip' => '192.168.100.14']);
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
