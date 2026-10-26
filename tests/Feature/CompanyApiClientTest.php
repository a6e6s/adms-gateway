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
            'bioTimeClients' => ['new-client' => [
                'username' => 'company-erp', 'password' => 'connector-password',
                'password_confirmation' => 'connector-password', 'is_active' => true,
            ]],
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
        ->assertHasFormErrors(["bioTimeClients.new-client.{$field}"]);

    expect($company->bioTimeClients()->count())->toBe(0);
})->with([
    'missing username' => [['bioTimeClients' => ['new-client' => ['password' => 'connector-password', 'password_confirmation' => 'connector-password']]], 'username'],
    'whitespace username' => [['bioTimeClients' => ['new-client' => ['username' => 'bad name', 'password' => 'connector-password', 'password_confirmation' => 'connector-password']]], 'username'],
    'duplicate username' => [['bioTimeClients' => ['new-client' => ['username' => 'existing', 'password' => 'connector-password', 'password_confirmation' => 'connector-password']]], 'username'],
    'short password' => [['bioTimeClients' => ['new-client' => ['username' => 'erp', 'password' => 'short', 'password_confirmation' => 'short']]], 'password'],
    'mismatched confirmation' => [['bioTimeClients' => ['new-client' => ['username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'different-password']]], 'password'],
]);

it('rejects client creation for inaccessible inactive or read only companies', function (string $restriction) {
    $company = Company::factory()->create(['is_active' => $restriction !== 'inactive']);
    $assigned = $restriction === 'foreign' ? Company::factory()->create() : $company;
    $this->actingAs(apiClientCompanyUser($assigned, $restriction !== 'read only'));

    $page = Livewire::test(ManageCompanies::class);
    $action = TestAction::make('createApiClient')->table($company);
    if ($restriction === 'foreign') {
        expect(fn () => $page->callAction($action, data: [
            'bioTimeClients' => ['new-client' => ['username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password', 'is_active' => true]],
        ]))->toThrow(ActionNotResolvableException::class);
    } elseif ($restriction === 'inactive') {
        $page->assertActionVisible($action);
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
        'bioTimeClients' => ['new-client' => ['username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password', 'is_active' => true]],
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
        'bioTimeClients' => ['new-client' => ['username' => 'erp', 'password' => 'connector-password', 'password_confirmation' => 'connector-password', 'is_active' => true]],
    ])->callMountedAction();

    $this->assertDatabaseCount('bio_time_clients', 0);
});

it('allows separate API clients for the same company', function () {
    $company = Company::factory()->create();
    BioTimeClient::factory()->for($company)->create(['username' => 'first-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'bioTimeClients' => ['new-client' => [
                'username' => 'second-erp', 'password' => 'connector-password',
                'password_confirmation' => 'connector-password', 'is_active' => true,
            ]],
        ])->assertHasNoFormErrors();

    expect($company->bioTimeClients()->count())->toBe(2);
});

it('updates existing API client details without replacing its password', function () {
    $company = Company::factory()->create();
    $client = BioTimeClient::factory()->for($company)->create([
        'username' => 'old-erp',
        'password' => 'old-connector-password',
        'is_active' => true,
    ]);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'bioTimeClients' => [
                $client->id => [
                    'id' => $client->id,
                    'username' => 'renamed-erp',
                    'is_active' => false,
                    'password' => null,
                    'password_confirmation' => null,
                ],
            ],
        ])
        ->assertHasNoFormErrors();

    $client->refresh();
    expect($client->username)->toBe('renamed-erp');
    expect($client->is_active)->toBeFalse();
    expect(Hash::check('old-connector-password', $client->password))->toBeTrue();
});

it('shows every company credential in the management form', function () {
    $this->app->setLocale('en');
    $company = Company::factory()->create();
    BioTimeClient::factory()->for($company)->create(['username' => 'first-erp']);
    BioTimeClient::factory()->for($company)->create(['username' => 'second-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    $page = Livewire::test(ManageCompanies::class)
        ->mountAction(TestAction::make('createApiClient')->table($company));

    expect($page->get('mountedActions.0.data.bioTimeClients'))
        ->toHaveCount(2)
        ->and(collect($page->get('mountedActions.0.data.bioTimeClients'))->pluck('username')->all())
        ->toContain('first-erp', 'second-erp');

    $page->assertMountedActionModalSee('Existing API usernames: first-erp, second-erp.');
});

it('does not allow managing credentials belonging to another company', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $client = BioTimeClient::factory()->for($other)->create(['username' => 'foreign-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'bioTimeClients' => [
                $client->id => [
                    'id' => $client->id,
                    'username' => 'hijacked-erp',
                    'is_active' => false,
                ],
            ],
        ]);

    $client->refresh();
    expect($client->username)->toBe('foreign-erp');
    expect($client->is_active)->toBeTrue();
});

it('rejects a username already used by another API client', function () {
    $company = Company::factory()->create();
    $client = BioTimeClient::factory()->for($company)->create(['username' => 'first-erp']);
    BioTimeClient::factory()->for($company)->create(['username' => 'second-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'bioTimeClients' => [
                $client->id => [
                    'id' => $client->id,
                    'username' => 'second-erp',
                    'is_active' => true,
                    'password' => null,
                    'password_confirmation' => null,
                ],
            ],
        ])
        ->assertHasFormErrors(["bioTimeClients.{$client->id}.username"]);

    expect($client->refresh()->username)->toBe('first-erp');
});

it('resets an API client password when a new confirmed password is entered', function () {
    $company = Company::factory()->create();
    $client = BioTimeClient::factory()->for($company)->create([
        'username' => 'erp',
        'password' => 'old-connector-password',
    ]);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->callAction(TestAction::make('createApiClient')->table($company), data: [
            'bioTimeClients' => [
                $client->id => [
                    'id' => $client->id,
                    'username' => 'erp',
                    'is_active' => true,
                    'password' => 'new-connector-password',
                    'password_confirmation' => 'new-connector-password',
                ],
            ],
        ])
        ->assertHasNoFormErrors();

    expect(Hash::check('new-connector-password', $client->refresh()->password))->toBeTrue();
});

it('revokes a credential from the combined company credentials modal', function () {
    $company = Company::factory()->create();
    $revokedClient = BioTimeClient::factory()->for($company)->create(['username' => 'revoked-erp']);
    $retainedClient = BioTimeClient::factory()->for($company)->create(['username' => 'retained-erp']);
    $this->actingAs(apiClientCompanyUser($company));

    Livewire::test(ManageCompanies::class)
        ->mountAction(TestAction::make('createApiClient')->table($company))
        ->set('mountedActions.0.data.bioTimeClients', [
            $retainedClient->id => [
                'id' => $retainedClient->id,
                'username' => 'retained-erp',
                'is_active' => true,
                'password' => null,
                'password_confirmation' => null,
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $this->assertDatabaseMissing('bio_time_clients', ['id' => $revokedClient->id]);
    $this->assertDatabaseHas('bio_time_clients', ['id' => $retainedClient->id, 'username' => 'retained-erp']);
});
