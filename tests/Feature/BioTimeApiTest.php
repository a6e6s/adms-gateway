<?php

use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use App\Models\BioTimeClient;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('returns a stable general token using connector credentials without browser headers', function () {
    $client = BioTimeClient::factory()->create(['username' => 'erp']);

    $response = $this->post('/api-token-auth/', ['username' => 'erp', 'password' => 'connector-password']);

    $response->assertOk()->assertExactJson(['token' => $client->token])->assertHeader('Cache-Control', 'no-store, private');
    expect(Hash::check('connector-password', $client->password))->toBeTrue();
    expect(DB::table('bio_time_clients')->where('id', $client->id)->value('token'))->not->toBe($client->token);
    expect($client->toArray())->not->toHaveKeys(['password', 'token', 'token_hash']);
});

it('returns 400 field errors for missing login credentials', function () {
    $this->post('/api-token-auth/', [])
        ->assertBadRequest()->assertExactJson([
            'username' => ['The username field is required.'],
            'password' => ['The password field is required.'],
        ]);
});

it('returns 400 for invalid credentials without returning a token', function () {
    $client = BioTimeClient::factory()->create();

    $this->postJson('/api-token-auth/', ['username' => $client->username, 'password' => 'wrong'])
        ->assertBadRequest()->assertExactJson(['non_field_errors' => ['Unable to log in with provided credentials.']]);
});

it('returns 401 with a token challenge when authentication is missing', function () {
    $this->get('/iclock/api/transactions/')
        ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Token')
        ->assertExactJson(['detail' => 'Authentication credentials were not provided.']);
});

it('returns 401 for invalid authorization', function (string $authorization) {
    $this->withHeader('Authorization', $authorization)->get('/iclock/api/transactions/')
        ->assertUnauthorized()->assertExactJson(['detail' => 'Invalid token.']);
})->with([
    'unknown token' => ['Token '.str_repeat('a', 40)],
    'wrong scheme' => ['Bearer '.str_repeat('a', 40)],
    'malformed token' => ['Token short'],
]);

it('revokes token access and login when the client or company is inactive', function (bool $inactiveCompany) {
    $client = BioTimeClient::factory()->create();
    if ($inactiveCompany) {
        $client->company->update(['is_active' => false]);
    } else {
        $client->update(['is_active' => false]);
    }

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/')
        ->assertUnauthorized();
    $this->postJson('/api-token-auth/', ['username' => $client->username, 'password' => 'connector-password'])
        ->assertBadRequest();
})->with(['inactive client' => [false], 'inactive company' => [true]]);

it('returns the captured BioTime transaction wire shape with local time and string PINs', function () {
    $punch = AttendancePunch::factory()->create([
        'pin' => '00012',
        'occurred_at_local' => '2026-10-05 11:49:53',
        'occurred_at_utc' => null,
        'time_quality' => 'unresolved',
        'timezone' => 'Asia/Riyadh',
        'received_at' => '2026-10-06 09:00:00',
        'status_code' => '1',
        'verification_code' => '1',
        'work_code' => null,
    ]);
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/')
        ->assertOk()->assertExactJson([
            'count' => 1, 'next' => null, 'previous' => null, 'msg' => '', 'code' => 0,
            'data' => [[
                'id' => $punch->id,
                'emp' => null,
                'emp_code' => '00012',
                'first_name' => null, 'last_name' => null, 'department' => null, 'position' => null,
                'punch_time' => '2026-10-05 11:49:53',
                'punch_state' => '1',
                'punch_state_display' => 'Check Out',
                'verify_type' => 1,
                'verify_type_display' => 'Fingerprint',
                'temperature' => 0,
                'is_mask' => '-',
                'work_code' => '',
                'terminal_sn' => $punch->device->serial_number,
                'terminal_alias' => $punch->device->name,
                'area_alias' => $punch->device->location ?? '',
                'gps_location' => null,
                'upload_time' => '2026-10-06 12:00:00',
            ]],
        ]);
});

it('returns consistent connector display values on list and detail responses', function (?string $status, ?string $verification, ?string $statusDisplay, ?string $verificationDisplay) {
    $punch = AttendancePunch::factory()->create(['status_code' => $status, 'verification_code' => $verification]);
    $client = BioTimeClient::factory()->for($punch->company)->create();
    app()->setLocale('ar');

    $list = $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/');
    $detail = $this->get('/iclock/api/transactions/'.$punch->id.'/');

    $detail->assertOk()->assertExactJson($list->json('data.0'))
        ->assertJsonPath('punch_state', $status)
        ->assertJsonPath('punch_state_display', $statusDisplay)
        ->assertJsonPath('verify_type_display', $verificationDisplay)
        ->assertJsonPath('temperature', 0)->assertJsonPath('is_mask', '-');
})->with([
    'check in using a password' => ['0', '3', 'Check In', 'Password'],
    'check out using a fingerprint' => ['1', '1', 'Check Out', 'Fingerprint'],
    'unconfirmed codes' => ['255', '255', 'Unknown', 'Unknown'],
    'missing codes' => [null, null, 'Unknown', 'Unknown'],
]);

it('matches all sanitized value combinations captured from jit-hr BioTime logs', function () {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/BioTime/transactions.json')), true, flags: JSON_THROW_ON_ERROR);
    $device = Device::factory()->create([
        'serial_number' => 'TEST-TERMINAL', 'name' => 'Test terminal', 'location' => 'Test Area', 'timezone' => 'UTC',
    ]);
    $upload = AttendanceUpload::factory()->for($device)->for($device->company)->create();
    $client = BioTimeClient::factory()->for($device->company)->create();
    foreach ($fixture['data'] as $record) {
        AttendancePunch::factory()->recycle($upload)->create([
            'id' => $record['id'],
            'pin' => $record['emp_code'],
            'occurred_at_local' => $record['punch_time'],
            'status_code' => $record['punch_state'],
            'verification_code' => (string) $record['verify_type'],
            'work_code' => $record['work_code'],
            'received_at' => $record['upload_time'],
            'timezone' => 'UTC',
            'biotime_metadata' => [
                'gps_location' => $record['gps_location'],
                'area_alias' => $record['area_alias'],
                'temperature' => $record['temperature'],
                'is_mask' => $record['is_mask'],
            ],
        ]);
    }

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?page_size=100')
        ->assertOk()->assertExactJson($fixture);
    foreach ($fixture['data'] as $record) {
        $this->get('/iclock/api/transactions/'.$record['id'].'/')->assertOk()->assertExactJson($record);
    }
});

it('preserves explicit mask and sensor metadata without changing raw punch fields', function () {
    $punch = AttendancePunch::factory()->create([
        'biotime_metadata' => ['gps_location' => '24.0,46.0', 'area_alias' => 'Test Area', 'temperature' => 36.5, 'is_mask' => 'Yes'],
    ]);
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/'.$punch->id.'/')
        ->assertOk()->assertJsonPath('gps_location', '24.0,46.0')->assertJsonPath('area_alias', 'Test Area')
        ->assertJsonPath('temperature', 36.5)->assertJsonPath('is_mask', 'Yes');

    expect($punch->fresh()->raw_fields)->toBe($punch->raw_fields);
});

it('scopes lists and detail to the authenticated company despite query overrides', function () {
    $punch = AttendancePunch::factory()->create();
    $foreignPunch = AttendancePunch::factory()->create();
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)
        ->get('/iclock/api/transactions/?company_id='.$foreignPunch->company_id)
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.id', $punch->id);
    $this->get('/iclock/api/transactions/'.$foreignPunch->id.'/')
        ->assertNotFound()->assertExactJson(['detail' => 'Not found.']);
    $this->get('/iclock/api/transactions/'.$punch->id.'/')
        ->assertOk()->assertJsonPath('id', $punch->id)->assertJsonMissingPath('data');
});

it('filters employee codes using mappings and falls back to unmapped PINs', function () {
    $punch = AttendancePunch::factory()->create(['pin' => '12']);
    $employee = Employee::factory()->for($punch->company)->create(['employee_number' => '0012']);
    $mapping = DeviceEmployee::factory()->for($punch->device)->for($employee)->create(['pin' => '12']);
    $punch->update(['device_employee_id' => $mapping->id]);
    $unmapped = AttendancePunch::factory()->recycle($punch->upload)->create(['pin' => '0012']);
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?emp_code=0012')
        ->assertOk()->assertJsonPath('count', 2)->assertJsonPath('data.0.emp_code', '0012')
        ->assertJsonPath('data.0.emp', null)->assertJsonPath('data.1.id', $unmapped->id);
    $this->get('/iclock/api/transactions/?emp_code=12')->assertJsonPath('count', 0);
});

it('applies inclusive local time and terminal filters together', function () {
    $punch = AttendancePunch::factory()->create(['occurred_at_local' => '2026-10-05 08:00:00']);
    AttendancePunch::factory()->recycle($punch->upload)->create(['occurred_at_local' => '2026-10-05 08:00:01']);
    AttendancePunch::factory()->for($punch->company)->create(['occurred_at_local' => '2026-10-05 08:00:00']);
    $client = BioTimeClient::factory()->for($punch->company)->create();
    $parameters = http_build_query([
        'terminal_sn' => $punch->device->serial_number,
        'terminal_alias' => $punch->device->name,
        'start_time' => '2026-10-05 08:00:00', 'end_time' => '2026-10-05 08:00:00',
    ]);

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?'.$parameters)
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.id', $punch->id);
});

it('returns pagination links that preserve filters and can be followed', function () {
    $punch = AttendancePunch::factory()->create(['pin' => '0001']);
    $second = AttendancePunch::factory()->recycle($punch->upload)->create(['pin' => '0001']);
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $firstPage = $this->withHeader('Authorization', 'Token '.$client->token)
        ->get('/iclock/api/transactions/?emp_code=0001&page_size=1');

    $firstPage->assertOk()->assertJsonPath('count', 2)->assertJsonCount(1, 'data')->assertJsonPath('previous', null);
    expect($firstPage->json('next'))->toContain('emp_code=0001', 'page_size=1', 'page=2');
    $lastPage = $this->get($firstPage->json('next'));
    $lastPage->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('next', null);
    expect(parse_url($lastPage->json('previous'), PHP_URL_QUERY))->toBe('emp_code=0001&page_size=1');
    expect(parse_url($lastPage->json('previous'), PHP_URL_PATH))->toBe('/iclock/api/transactions/');
    $this->get($lastPage->json('previous'))->assertJsonPath('data.0.id', $punch->id);
});

it('supports legacy limits and last pages while preferring page_size', function () {
    $punch = AttendancePunch::factory()->create();
    $second = AttendancePunch::factory()->recycle($punch->upload)->create();
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?limit=1&page=last')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
    $this->get('/iclock/api/transactions/?limit=1&page_size=2')->assertJsonCount(2, 'data');
});

it('returns an empty first page and 404 for a nonexistent page', function () {
    $client = BioTimeClient::factory()->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/')
        ->assertExactJson(['count' => 0, 'next' => null, 'previous' => null, 'msg' => '', 'code' => 0, 'data' => []]);
    $this->get('/iclock/api/transactions/?page=2')->assertNotFound()->assertExactJson(['detail' => 'Invalid page.']);
});

it('returns 400 field errors for invalid transaction filters', function (string $query, string $field, string $message) {
    $client = BioTimeClient::factory()->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?'.$query)
        ->assertBadRequest()->assertExactJson([$field => [$message]]);
})->with([
    'zero page' => ['page=0', 'page', 'The page field format is invalid.'],
    'page array' => ['page[]=1', 'page', 'The page field format is invalid.'],
    'unbounded page' => ['page=9999999999', 'page', 'The page field format is invalid.'],
    'zero size' => ['page_size=0', 'page_size', 'The page size field must be at least 1.'],
    'excessive size' => ['page_size=1001', 'page_size', 'The page size field must not be greater than 1000.'],
    'invalid legacy limit' => ['limit=abc', 'limit', 'The limit must be an integer.'],
    'bad start' => ['start_time=nope', 'start_time', 'The start time field must match the format Y-m-d H:i:s.'],
    'bad end' => ['end_time=nope', 'end_time', 'The end time field must match the format Y-m-d H:i:s.'],
    'employee array' => ['emp_code[]=1', 'emp_code', 'The emp code must be a string.'],
]);

it('returns 400 when the local end time precedes the start time', function () {
    $client = BioTimeClient::factory()->create();
    $query = http_build_query(['start_time' => '2026-10-06 08:00:00', 'end_time' => '2026-10-05 08:00:00']);

    $this->withHeader('Authorization', 'Token '.$client->token)->get('/iclock/api/transactions/?'.$query)
        ->assertBadRequest()->assertExactJson(['end_time' => ['The end time must be after or equal to the start time.']]);
});

it('does not interpret employee filter content as SQL', function () {
    $punch = AttendancePunch::factory()->create();
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)
        ->get('/iclock/api/transactions/?'.http_build_query(['emp_code' => "' OR 1=1 --"]))
        ->assertJsonPath('count', 0);
});

it('returns 405 and preserves attendance when a client requests deletion', function () {
    $punch = AttendancePunch::factory()->create();
    $client = BioTimeClient::factory()->for($punch->company)->create();

    $this->withHeader('Authorization', 'Token '.$client->token)->delete('/iclock/api/transactions/'.$punch->id.'/')
        ->assertStatus(405)->assertExactJson(['detail' => 'Method "DELETE" not allowed.']);

    $this->assertModelExists($punch);
});

it('returns 429 with a DRF detail when token login is throttled', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api-token-auth/', [])->assertBadRequest();
    }

    $this->postJson('/api-token-auth/', [])->assertStatus(429)->assertHeader('Retry-After')
        ->assertExactJson(['detail' => 'Request was throttled.']);
});

it('provisions a scoped client using a hidden password without printing secrets', function () {
    $company = Company::factory()->create();

    $this->artisan('biotime:client-create', ['username' => 'erp', 'company' => $company->id])
        ->expectsQuestion('API client password', 'connector-password')
        ->expectsOutput('API client created. Obtain its token through /api-token-auth/.')
        ->assertExitCode(0);

    $this->assertDatabaseHas('bio_time_clients', ['username' => 'erp', 'company_id' => $company->id]);
});

it('refuses noninteractive credential creation without creating a default client', function () {
    $company = Company::factory()->create();

    $this->artisan('biotime:client-create', ['username' => 'erp', 'company' => $company->id, '--no-interaction' => true])
        ->expectsOutput('Interactive hidden password entry is required.')->assertExitCode(1);

    $this->assertDatabaseCount('bio_time_clients', 0);
});
