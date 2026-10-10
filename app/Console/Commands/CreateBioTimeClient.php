<?php

namespace App\Console\Commands;

use App\Models\BioTimeClient;
use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('biotime:client-create {username} {company : Existing active company ID}')]
#[Description('Create a company-scoped BioTime attendance API service client')]
class CreateBioTimeClient extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive hidden password entry is required.');

            return self::FAILURE;
        }

        $company = Company::query()->where('is_active', true)->find($this->argument('company'));

        if ($company === null) {
            $this->error('Select an existing active company.');

            return self::FAILURE;
        }

        $username = $this->argument('username');
        $password = $this->secret('API client password');
        $validator = Validator::make(['username' => $username, 'password' => $password], [
            'username' => ['required', 'string', 'max:150', 'regex:/^\S+$/u', 'unique:bio_time_clients,username'],
            'password' => ['required', 'string', 'min:12', 'max:4096'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        BioTimeClient::query()->create([
            'username' => $username,
            'password' => $password,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->info('API client created. Obtain its token through /jwt-api-token-auth/.');

        return self::SUCCESS;
    }
}
