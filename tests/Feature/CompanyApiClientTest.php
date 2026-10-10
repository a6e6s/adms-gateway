<?php

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Models\BioTimeClient;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function apiClientCompanyUser(Company $company, bool $canUpdate = true): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('panel_user', 'web'));
    $user->companies()->attach($company);
    foreach (['ViewAny:Company', 'View:Company', ...($canUpdate ? ['Update:Company'] : [])] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

it('creates company API credentials that can log in', function (bool $superAdmin) {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $this->actingAs($superAdmin ? User::factory()->superAdmin()->create() : apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'username' => 'company-erp', 'password' => 'connector-password',
            'password_confirmation' => 'connector-password', 'company_id' => $other->id,
        ])->assertHasNoFormErrors()->assertNotified();

    $client = $company->bioTimeClients()->sole();
    expect($client->username)->toBe('company-erp');
    expect($client->is_active)->toBeTrue();
    expect(Hash::check('connector-password', $client->password))->toBeTrue();
    expect($client->toArray())->not->toHaveKeys(['password', 'token', 'token_hash']);
    $this->assertDatabaseMissing('bio_time_clients', ['company_id' => $other->id]);
    $this->postJson('/jwt-api-token-auth/', ['username' => 'company-erp', 'password' => 'connector-password'])
        ->assertOk()->assertExactJson(['token' => $client->token]);
})->with([false, true]);

it('validates API client credentials before saving', function (array $data, string $field) {
    $company = Company::factory()->create();
    BioTimeClient::factory()->create(['username' => 'existing']);
    $this->actingAs(User::factory()->superAdmin()->create());

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: $data)
        ->assertHasFormErrors([$field]);

    expect($company->bioTimeClients()->count())->toBe(0);
})->with([
    'missing username' => [['password' => 'connector-password', 'password_confirmation' => 'connector-password'], 'username'],
    'whitespace username' => [['username' => 'bad name', 'password' => 'connector-password', 'password_confirmation' => 'connector-password'], 'username'],
    'duplicate username' => [['username' => 'existing', 'password' => 'connector-password', 'password_confirmation' => 'connector-password'], 'username'],
    'short password' => [['username' => 'erp', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatched confirmation' => [['username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'different-password'], 'password'],
]);

it('rejects client creation for inaccessible inactive or read only companies', function (string $restriction) {
    $company = Company::factory()->create(['is_active' => $restriction !== 'inactive']);
    $assigned = $restriction === 'foreign' ? Company::factory()->create() : $company;
    $this->actingAs(apiClientCompanyUser($assigned, $restriction !== 'read only'));

    $page = Livewire::test(ManageCompanies::class);
    $action = TestAction::make('createApiClient')->table($company);
    if ($restriction === 'foreign') {
        expect(fn () => $page->callAction($action, data: [
            'username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password',
        ]))->toThrow(ActionNotResolvableException::class);
    } else {
        $page->assertActionHidden($action);
    }

    $this->assertDatabaseCount('bio_time_clients', 0);
})->with(['foreign', 'inactive', 'read only']);

it('rechecks company activity when a mounted action is submitted', function () {
    $company = Company::factory()->create();
    $this->actingAs(apiClientCompanyUser($company));
    $page = Livewire::test(ManageCompanies::class)
        ->mountAction(TestAction::make('createApiClient')->table($company));
    $company->update(['is_active' => false]);

    $page->setActionData([
        'username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password',
    ])->callMountedAction();

    $this->assertDatabaseCount('bio_time_clients', 0);
});

it('rechecks update permission when a mounted action is submitted', function () {
    $company = Company::factory()->create();
    $user = apiClientCompanyUser($company);
    $this->actingAs($user);
    $page = Livewire::test(ManageCompanies::class)
        ->mountAction(TestAction::make('createApiClient')->table($company));
    $user->revokePermissionTo('Update:Company');

    $page->setActionData([
        'username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password',
    ])->callMountedAction();

    $this->assertDatabaseCount('bio_time_clients', 0);
});

it('allows separate API clients for the same company', function () {
    $company = Company::factory()->create();
    BioTimeClient::factory()->for($company)->create(['username' => 'first-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'username' => 'second-erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password',
        ])->assertHasNoFormErrors();

    expect($company->bioTimeClients()->count())->toBe(2);
});
