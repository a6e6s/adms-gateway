<?php

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\Adms\DashboardQuery;
use App\Services\Adms\DeviceCommandService;
use App\Services\CompanyAccess;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function companyPanelUser(array $companies): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('panel_user', 'web'));
    $user->companies()->sync(array_map(fn (Company $company): int => $company->id, $companies));
    foreach (['Device', 'Employee', 'Company', 'AttendancePunch', 'AttendanceUpload', 'DeviceCommand'] as $subject) {
        foreach (['ViewAny', 'View', 'Create', 'Update'] as $action) {
            $user->givePermissionTo(Permission::findOrCreate($action.':'.$subject, 'web'));
        }
    }

    return $user;
}

it('limits resources and dashboard queries to all assigned companies', function () {
    $companies = Company::factory()->count(3)->create();
    $devices = $companies->map(fn (Company $company): Device => Device::factory()->for($company)->create());
    $user = companyPanelUser([$companies[0], $companies[1]]);
    $this->actingAs($user);
    Livewire::test(ManageDevices::class)->assertCanSeeTableRecords([$devices[0], $devices[1]])->assertCanNotSeeTableRecords([$devices[2]]);
    expect(CompanyAccess::scope(Company::query())->pluck('id')->all())->toEqual([$companies[0]->id, $companies[1]->id]);
    expect((new DashboardQuery)->devices()->pluck('id')->all())->toEqual([$devices[0]->id, $devices[1]->id]);
    expect((new DashboardQuery(['company_id' => $companies[2]->id]))->devices()->count())->toBe(0);
    expect(DeviceResource::getEloquentQuery()->find($devices[2]->id))->toBeNull();
    expect(Gate::forUser($user)->allows('update', $devices[2]))->toBeFalse();
    expect(Gate::forUser($user)->allows('update', $devices[0]))->toBeTrue();
});

it('restricts attendance and command widgets when filtering by an unauthorized company', function () {
    $own = Device::factory()->create();
    $other = Device::factory()->create();
    $uploads = collect([$own, $other])->map(fn (Device $device) => AttendanceUpload::factory()->for($device)->create());
    foreach ($uploads as $upload) {
        AttendancePunch::factory()->for($upload, 'upload')->create();
    }
    DeviceCommand::factory()->for($own)->create();
    DeviceCommand::factory()->for($other)->create();
    $this->actingAs(companyPanelUser([$own->company]));
    expect((new DashboardQuery)->uploads()->pluck('id')->all())->toBe([$uploads[0]->id]);
    expect((new DashboardQuery)->commands()->count())->toBe(1);
    expect((new DashboardQuery(['company_id' => $other->company_id]))->uploads()->count())->toBe(0);
    expect((new DashboardQuery(['company_id' => $other->company_id]))->commands()->count())->toBe(0);
});

it('rejects forged company assignments on device and employee create forms', function (string $pageClass) {
    $own = Company::factory()->create();
    $other = Company::factory()->create();
    $this->actingAs(companyPanelUser([$own]));
    Livewire::test($pageClass)->callAction('create', data: [
        'company_id' => $other->id, 'serial_number' => 'FORGED-SN', 'employee_number' => 'FORGED-PIN',
        'name' => 'Forbidden record', 'timezone' => 'Asia/Riyadh', 'protocol_profile' => 'push-2.4-attlog-v1', 'is_enabled' => true, 'is_active' => true,
    ])->assertHasFormErrors(['company_id']);
    $this->assertDatabaseMissing('devices', ['serial_number' => 'FORGED-SN']);
    $this->assertDatabaseMissing('employees', ['employee_number' => 'FORGED-PIN']);
})->with([ManageDevices::class, ManageEmployees::class]);

it('allows companyless super admins across companies while quarantining unassigned nonadmins', function () {
    $device = Device::factory()->create();
    $this->actingAs(User::factory()->superAdmin()->create());
    expect(DeviceResource::getEloquentQuery()->find($device->id)?->id)->toBe($device->id);
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('panel_user', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Device', 'web'));
    $this->actingAs($user);
    expect((new DashboardQuery)->devices()->count())->toBe(0);
    expect($user->isSuperAdmin())->toBeFalse();
});

it('does not treat a super admin role with company memberships as unrestricted', function () {
    $own = Company::factory()->create();
    $other = Device::factory()->create();
    $user = User::factory()->superAdmin()->create();
    $user->companies()->attach($own);
    $this->actingAs($user);
    expect($user->isSuperAdmin())->toBeFalse();
    expect(DeviceResource::getEloquentQuery()->find($other->id))->toBeNull();
    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBeFalse();
});

it('denies cross company attendance commands without queuing them', function () {
    $own = Company::factory()->create();
    $other = Device::factory()->create();
    $user = companyPanelUser([$own]);
    expect(fn () => app(DeviceCommandService::class)->requestAttendance($other, $user, '2026-10-01 00:00:00', '2026-10-10 23:59:59'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('device_commands', 0);
});

it('prevents deleting a company with user memberships rather than promoting its users', function () {
    $company = Company::factory()->create();
    $user = companyPanelUser([$company]);
    expect(fn () => $company->delete())->toThrow(QueryException::class);
    expect($user->fresh()->isSuperAdmin())->toBeFalse();
    $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $user->id]);
});

it('rejects editing another company device through a forged livewire table action', function () {
    $own = Company::factory()->create();
    $other = Device::factory()->create();
    $this->actingAs(companyPanelUser([$own]));
    expect(fn () => Livewire::test(ManageDevices::class)
        ->callAction(TestAction::make('edit')->table($other), data: ['name' => 'Unauthorized change']))
        ->toThrow(ActionNotResolvableException::class);
    expect($other->fresh()->name)->not->toBe('Unauthorized change');
});
