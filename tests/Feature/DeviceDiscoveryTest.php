<?php

use App\Filament\Resources\DiscoveredDevices\Pages\ManageDiscoveredDevices;
use App\Models\Company;
use App\Models\Device;
use App\Models\DiscoveredDevice;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('discovers unregistered devices without accepting their requests', function (string $method, string $endpoint) {
    $separator = str_contains($endpoint, '?') ? '&' : '?';
    $response = $this->call($method, $endpoint.$separator.'SN=NEW-001&pushver=2.4&DeviceType=Terminal');

    $response->assertForbidden()->assertContent('Unknown device');
    $this->assertDatabaseHas('discovered_devices', ['serial_number' => 'NEW-001', 'push_version' => '2.4', 'device_type' => 'Terminal', 'attempt_count' => 1]);
    $this->assertDatabaseCount('devices', 0);
    $this->assertDatabaseCount('attendance_uploads', 0);
})->with([
    ['GET', '/iclock/cdata'], ['GET', '/iclock/getrequest'], ['GET', '/iclock/ping'],
    ['POST', '/iclock/cdata?table=ATTLOG'], ['POST', '/iclock/devicecmd'],
]);

it('keeps one discovery and preserves first contact and metadata across repeated attempts', function () {
    $this->travelTo('2026-10-07 10:00:00');
    $this->get('/iclock/cdata?SN=NEW-001&pushver=2.4&DeviceType=Terminal')->assertForbidden();
    $this->travelTo('2026-10-07 10:01:00');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->get('/iclock/ping?SN=NEW-001')->assertForbidden();

    $this->assertDatabaseCount('discovered_devices', 1);
    $this->assertDatabaseHas('discovered_devices', [
        'serial_number' => 'NEW-001', 'attempt_count' => 2, 'push_version' => '2.4', 'device_type' => 'Terminal',
        'first_seen_at' => '2026-10-07 10:00:00', 'last_seen_at' => '2026-10-07 10:01:00', 'last_seen_ip' => '192.0.2.20',
    ]);
});

it('does not discover requests without a valid serial number', function (array $parameters) {
    $this->get('/iclock/ping?'.http_build_query($parameters))->assertForbidden();

    $this->assertDatabaseCount('discovered_devices', 0);
})->with([
    'missing' => [[]], 'empty' => [['SN' => '']], 'array' => [['SN' => ['invalid']]],
    'too long' => [['SN' => str_repeat('A', 101)]], 'control character' => [['SN' => "bad\nserial"]],
]);

it('does not rediscover registered disabled devices', function () {
    $device = Device::factory()->create(['is_enabled' => false]);

    $this->get('/iclock/ping?SN='.$device->serial_number)->assertForbidden();

    $this->assertDatabaseCount('discovered_devices', 0);
    expect($device->fresh()->is_enabled)->toBeFalse();
});

it('registers a discovered serial as disabled and accepts attendance only after explicit activation', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $company = Company::factory()->create();
    $discovery = DiscoveredDevice::factory()->create(['serial_number' => 'NEW-001']);
    $this->actingAs($user);

    Livewire::test(ManageDiscoveredDevices::class)
        ->callAction(TestAction::make('register')->table($discovery), data: [
            'company_id' => $company->id, 'name' => 'Main entrance', 'timezone' => 'Asia/Riyadh',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertCanNotSeeTableRecords([$discovery]);

    $device = Device::query()->where('serial_number', 'NEW-001')->firstOrFail();
    expect($device->is_enabled)->toBeFalse();
    expect($device->company_id)->toBe($company->id);
    $payload = "0012\t2026-10-07 12:00:00\t1\t1\n";
    $this->call('POST', '/iclock/cdata?SN=NEW-001&table=ATTLOG', content: $payload)->assertForbidden();
    $this->assertDatabaseCount('attendance_uploads', 0);
    $device->update(['is_enabled' => true]);

    $this->call('POST', '/iclock/cdata?SN=NEW-001&table=ATTLOG', content: $payload)->assertOk()->assertContent('OK');
    $this->assertDatabaseHas('attendance_punches', ['device_id' => $device->id, 'pin' => '0012']);
});

it('rejects registration into an inactive company', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $company = Company::factory()->create(['is_active' => false]);
    $discovery = DiscoveredDevice::factory()->create();
    $this->actingAs($user);

    Livewire::test(ManageDiscoveredDevices::class)
        ->callAction(TestAction::make('register')->table($discovery), data: [
            'company_id' => $company->id, 'name' => 'Main entrance', 'timezone' => 'Asia/Riyadh',
        ])->assertHasFormErrors(['company_id']);

    $this->assertDatabaseCount('devices', 0);
});

it('blocks discovery access and registration for users without device permissions', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'panel_user', 'guard_name' => 'web']));
    $discovery = DiscoveredDevice::factory()->create();
    $this->actingAs($user);

    $this->get(route('filament.admin.resources.discovered-devices.index'))->assertForbidden();
    expect(Gate::forUser($user)->allows('register', $discovery))->toBeFalse();
    $this->assertDatabaseCount('devices', 0);
});

it('validates the registration details before creating a device', function (array $data, string $field) {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $company = Company::factory()->create();
    $discovery = DiscoveredDevice::factory()->create();
    $this->actingAs($user);

    Livewire::test(ManageDiscoveredDevices::class)
        ->callAction(TestAction::make('register')->table($discovery), data: array_replace([
            'company_id' => $company->id, 'name' => 'Entrance', 'timezone' => 'Asia/Riyadh',
        ], $data))->assertHasFormErrors([$field]);

    $this->assertDatabaseCount('devices', 0);
})->with([
    'missing company' => [['company_id' => null], 'company_id'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'invalid timezone' => [['timezone' => 'Invalid/Zone'], 'timezone'],
    'missing name' => [['name' => ''], 'name'],
]);

it('hides discoveries that were already registered manually', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $discovery = DiscoveredDevice::factory()->create();
    Device::factory()->create(['serial_number' => $discovery->serial_number]);
    $this->actingAs($user);

    Livewire::test(ManageDiscoveredDevices::class)->assertCanNotSeeTableRecords([$discovery]);
});

it('reserves unassigned discoveries for super admins despite device permissions', function () {
    $user = User::factory()->create();
    $discovery = DiscoveredDevice::factory()->create();
    $view = Permission::create(['name' => 'ViewAny:Device', 'guard_name' => 'web']);
    $create = Permission::create(['name' => 'Create:Device', 'guard_name' => 'web']);
    $user->givePermissionTo($view);

    expect(Gate::forUser($user)->allows('viewAny', DiscoveredDevice::class))->toBeFalse();
    expect(Gate::forUser($user)->allows('register', $discovery))->toBeFalse();
    $user->givePermissionTo($create);
    expect(Gate::forUser($user)->allows('register', $discovery))->toBeFalse();
});
