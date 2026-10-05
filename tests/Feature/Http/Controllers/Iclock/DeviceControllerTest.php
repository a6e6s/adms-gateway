<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Adms\DeviceCommandService;

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

it('records command polling and offers the pending attendance request', function () {
    $device = Device::factory()->create();
    $user = User::factory()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user);

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent("C:1:DATA QUERY ATTLOG\n");
    expect($command->fresh()->status)->toBe('offered');
    expect($device->fresh()->last_getrequest_at)->not->toBeNull();
});

it('expires an overdue attendance request when the device polls', function () {
    $device = Device::factory()->create();
    $user = User::factory()->create();
    $command = app(DeviceCommandService::class)->requestAttendance($device, $user);
    $command->forceFill(['expires_at' => now()->subMinute()])->save();

    $response = $this->get('/iclock/getrequest?SN='.$device->serial_number);

    $response->assertOk()->assertContent('OK');
    expect($command->fresh()->status)->toBe('expired');
});
