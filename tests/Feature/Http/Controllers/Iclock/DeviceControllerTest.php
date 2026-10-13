<?php

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Filament\Resources\DeviceCommands\DeviceCommandResource;
use App\Filament\Resources\DeviceCommands\Pages\ManageDeviceCommands;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceCommand;
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
    config()->set('app.timezone', 'UTC');
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user, '2000-01-01 00:00:00', '2099-12-31 23:59:59');

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent("C:1:DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59\n");
    expect($command->fresh()->status)->toBe('offered');
    expect($device->fresh()->last_getrequest_at)->not->toBeNull();
});

it('offers a read-only device information command', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestDeviceInfo($device, $user);

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent("C:1:INFO\n");
    expect($command->fresh()->status)->toBe('offered');
});

it('requests and securely stores device options through the PUSH query endpoint', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestDeviceOptions($device, $user);
    $options = '~SerialNumber,FirmVer,~DeviceName,LockCount,ReaderCount,AuxInCount,AuxOutCount,MachineType,~IsOnlyRFMachine,~MaxUserCount,~MaxAttLogCount,~MaxFingerCount,~MaxUserFingerCount,MThreshold,NetMask,GATEIPAddress,~ZKFPVersion,SimpleEventType,VerifyStyles,EventTypes,ComPwd';

    expect($command->wire_payload)->toBe('GET OPTIONS '.$options);

    $pollResponse = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $pollResponse->assertOk()->assertContent('C:1:GET OPTIONS '.$options."\n");

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=0&CMD=GET OPTIONS\n~SerialNumber={$device->serial_number},FirmVer=Ver 8.0.4.5,NetMask=255.255.255.0,ComPwd=secret",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
    expect($command->fresh()->encrypted_payload)->toContain('ComPwd=secret');
    expect($command->fresh()->raw_result)->not->toContain('ComPwd=secret');
    expect($command->fresh()->result_history[0])->not->toHaveKey('body');

    $payload = "DeviceName=P160/ID\nNetMask=255.255.255.0\nGATEIPAddress=192.168.100.1\nDHCP=0\n";
    $uploadResponse = $this->call(
        'POST',
        '/iclock/querydata?SN='.$device->serial_number.'&type=options&cmdid='.$command->wire_command_id,
        content: $payload,
    );

    $uploadResponse->assertOk()->assertContent('OK');
    expect($command->fresh()->encrypted_payload)
        ->toContain('ComPwd=secret')
        ->toContain('DeviceName=P160/ID')
        ->toContain('GATEIPAddress=192.168.100.1');
    expect($command->fresh()->raw_result)->toContain('Encrypted OPTIONS upload received:');
});

it('rejects a server URL containing a path', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();

    expect(fn () => app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'set_web_server', [
        'web_server_ip' => 'https://adms.example.com/iclock',
        'web_server_port' => 8000,
    ]))->toThrow(InvalidArgumentException::class);
});

it('accepts an http or https server URL and sends only its hostname', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'set_web_server', [
        'web_server_ip' => 'https://bs.jit.sa',
        'web_server_port' => 80,
    ]);

    expect($command->wire_payload)->toBe('SET OPTIONS WebServerIP=bs.jit.sa,WebServerPort=80');
});

it('queues the server address and port in the supported PUSH format', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'set_web_server', [
        'web_server_ip' => '192.168.100.109',
        'web_server_port' => 8000,
    ]);

    expect($command->wire_payload)->toBe('SET OPTIONS WebServerIP=192.168.100.109,WebServerPort=8000');
});

it('queues the network gateway when one is provided', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'set_network', [
        'ip_address' => '192.168.100.14',
        'netmask' => '255.255.255.0',
        'gateway' => '192.168.100.1',
    ]);

    expect($command->wire_payload)->toBe('SET OPTIONS DHCP=0,IPAddress=192.168.100.14,GATEIPAddress=192.168.100.1,NetMask=255.255.255.0');
});

it('queues a clock sync using the server time and device timezone', function () {
    $this->travelTo('2026-10-12 07:50:00 UTC');
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->syncClockToServer($device, $user);

    expect($command->type)->toBe('sync_clock');
    expect($command->wire_payload)->toBe('SET OPTIONS TimeZone=+03:00,DateTime=2026-10-12 10:50:00');
});

it('records a clock sync response without a command name', function () {
    $device = Device::factory()->create(['timezone' => 'Asia/Riyadh']);
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->syncClockToServer($device, $user);

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: 'ID=1&Return;=0',
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
});

it('records the device information response', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestDeviceInfo($device, $user);

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID=1&Return=0&CMD=INFO\nDeviceName=P160/ID\nPlatform=ZMM120_TFT\n",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
    $this->assertDatabaseHas('device_commands', [
        'id' => $command->id,
        'raw_result' => "ID=1&Return=0&CMD=INFO\nDeviceName=P160/ID\nPlatform=ZMM120_TFT\n",
    ]);
});

it('records a rejected guide command result with an empty command name', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'unlock');

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=-1002&CMD=",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('failed');
    expect($command->fresh()->result_received_at)->not->toBeNull();
    $this->assertDatabaseHas('device_commands', [
        'id' => $command->id,
        'raw_result' => "ID={$command->wire_command_id}&Return=-1002&CMD=",
    ]);
});

it('records a failed command when the device reports a generic command name', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->requestDeviceInfo($device, $user);

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=-1002&CMD=DATA",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('failed');
    expect($command->fresh()->result_received_at)->not->toBeNull();
});

it('accepts a successful door unlock result with the device command name', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'unlock');

    expect($command->wire_payload)->toBe('AC_UNLOCK');

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=0&CMD=AC_UNLOCK",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
    expect($command->fresh()->result_received_at)->not->toBeNull();
    $this->assertDatabaseHas('device_commands', [
        'id' => $command->id,
        'raw_result' => "ID={$command->wire_command_id}&Return=0&CMD=AC_UNLOCK",
    ]);
});

it('queues a user update using tab-separated DATA USER fields and protects its password', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();

    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'update_user', [
        'pin' => '1005',
        'name' => 'John Doe',
        'privilege' => 0,
        'password' => '5566',
    ]);

    expect($command->wire_payload)->toBe('Sensitive command payload is stored encrypted.');
    expect($command->encrypted_payload)->toBe("DATA USER PIN=1005\tName=John Doe\tCard=\tPri=0\tPasswd=5566\tGrp=1");
});

it('rejects a user privilege code that the PUSH protocol does not define', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();

    expect(fn () => app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'update_user', [
        'pin' => '1005',
        'name' => 'John Doe',
        'privilege' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'The user privilege is invalid.');
});

it('accepts the successful DATA result for a user update', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'update_user', [
        'pin' => '1005',
        'name' => 'John Doe',
        'privilege' => 0,
    ]);

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=0&CMD=DATA",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
});

it('includes an empty card and password field when creating a user without credentials', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();

    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'update_user', [
        'pin' => '1005',
        'name' => 'John Doe',
        'privilege' => 0,
    ]);

    expect($command->encrypted_payload)->toBe("DATA USER PIN=1005\tName=John Doe\tCard=\tPri=0\tPasswd=\tGrp=1");
});

it('queues user deletion using the USERINFO table and accepts its DATA result', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'delete_user', ['pin' => '1005']);

    expect($command->wire_payload)->toBe('DATA DELETE USERINFO PIN=1005');

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=0&CMD=DATA",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
});

it('stores user query results posted through the OPERLOG table', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'query_users', ['pin' => '1005']);
    $payload = "USER PIN=1005\tName=John Doe\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000000000000\n";

    expect($command->wire_payload)->toBe('DATA QUERY USERINFO PIN=1005');

    $this->get('/iclock/getrequest?SN='.$device->serial_number)
        ->assertOk()
        ->assertContent("C:1:DATA QUERY USERINFO PIN=1005\n");

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=OPERLOG',
        content: $payload,
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('acknowledged');
    expect($command->fresh()->encrypted_payload)->toContain($payload);
});

it('does not associate an OPERLOG upload with a user query that was not sent', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'query_users', ['pin' => '1005']);

    $this->call(
        'POST',
        '/iclock/cdata?SN='.$device->serial_number.'&table=OPERLOG',
        content: "OPLOG 4\t14\t2026-10-13 10:00:00\t0\t0\t0\t0\n",
    )->assertOk()->assertContent('OK');

    expect($command->fresh()->status)->toBe('pending');
    expect($command->fresh()->encrypted_payload)->toBeNull();
});

it('requires a PIN for a device user query', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();

    expect(fn () => app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'query_users'))
        ->toThrow(InvalidArgumentException::class, 'The pin value is required.');
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

it('acknowledges a late result for an expired command without reopening it', function () {
    $device = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $command = app(DeviceCommandService::class)->queueGuideOperation($device, $user, 'query_users', ['pin' => '1005']);
    $command->forceFill(['status' => 'expired'])->save();

    $response = $this->call(
        'POST',
        '/iclock/devicecmd?SN='.$device->serial_number,
        content: "ID={$command->wire_command_id}&Return=0&CMD=DATA",
    );

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('expired');
});

it('converts a requested range to the device timezone before creating the command', function () {
    config()->set('app.timezone', 'UTC');
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
    config()->set('app.timezone', 'UTC');
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
    expect($command->expires_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-07 12:00:00');
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
    config()->set('app.timezone', 'UTC');
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

it('queues a read-only device information command for selected devices', function () {
    $user = User::factory()->superAdmin()->create();
    $devices = Device::factory()->count(2)->create();
    $this->actingAs($user);

    Livewire::test(ManageDevices::class)
        ->selectTableRecords($devices->modelKeys())
        ->callAction(TestAction::make('deviceCommands')->table()->bulk(), data: [
            'operation' => 'request_device_info',
        ])
        ->assertHasNoFormErrors();

    $this->assertDatabaseCount('device_commands', 2);
    $this->assertDatabaseHas('device_commands', [
        'device_id' => $devices->first()->id,
        'type' => 'get_device_info',
        'wire_payload' => 'INFO',
    ]);
});

it('shows device options as readable values while hiding the communication password', function () {
    $user = User::factory()->superAdmin()->create();
    $command = DeviceCommand::factory()->create([
        'type' => 'get_device_options',
        'status' => 'acknowledged',
        'wire_command_id' => 8,
        'raw_result' => 'ID=8&Return=0&CMD=GET OPTIONS'."\nDevice options response stored encrypted.",
        'encrypted_payload' => "ID=8&Return=0&CMD=GET OPTIONS\n~SerialNumber=A39N203960051,FirmVer=Ver 8.0.4.5-20200729,~DeviceName=P160/ID,NetMask=255.255.255.0,GATEIPAddress=192.168.100.1,MachineType=<script>alert(1)</script>,ComPwd=secret",
        'result_received_at' => now(),
    ]);
    $this->actingAs($user);
    app()->setLocale('en');

    $result = DeviceCommandResource::formatResultContent($command)->toHtml();

    expect($result)
        ->toContain('Serial Number')
        ->toContain('P160/ID')
        ->toContain('255.255.255.0')
        ->toContain('192.168.100.1')
        ->toContain('Hidden')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('ComPwd=secret');

    Livewire::test(ManageDeviceCommands::class)->assertSee('View result');
});

it('filters device commands by status', function () {
    $user = User::factory()->superAdmin()->create();
    $matchingCommand = DeviceCommand::factory()->create(['status' => 'acknowledged']);
    $otherCommand = DeviceCommand::factory()->create(['status' => 'failed']);
    $expiredCommand = DeviceCommand::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->subMinute(),
    ]);
    $this->actingAs($user);

    Livewire::test(ManageDeviceCommands::class)
        ->assertTableFilterVisible('device_id')
        ->assertTableFilterVisible('type')
        ->assertTableFilterVisible('status')
        ->assertTableFilterVisible('requested_at')
        ->filterTable('status', 'acknowledged')
        ->assertCanSeeTableRecords([$matchingCommand])
        ->assertCanNotSeeTableRecords([$otherCommand]);

    Livewire::test(ManageDeviceCommands::class)
        ->filterTable('status', 'expired')
        ->assertCanSeeTableRecords([$expiredCommand])
        ->assertCanNotSeeTableRecords([$matchingCommand]);
});

it('cancels a pending command from the device commands table', function () {
    $user = User::factory()->superAdmin()->create();
    $command = DeviceCommand::factory()->create([
        'status' => 'pending',
        'expires_at' => now()->addDay(),
    ]);
    $this->actingAs($user);
    app()->setLocale('en');

    Livewire::test(ManageDeviceCommands::class)
        ->callAction(TestAction::make('cancelPending')->table($command))
        ->assertNotified();

    expect($command->fresh()->status)->toBe('cancelled');
});

it('does not cancel a command already offered to the device', function () {
    $user = User::factory()->superAdmin()->create();
    $command = DeviceCommand::factory()->create([
        'status' => 'offered',
        'expires_at' => now()->addDay(),
    ]);

    expect(app(DeviceCommandService::class)->cancelPendingCommand($command, $user))->toBeFalse();
    expect($command->fresh()->status)->toBe('offered');
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
