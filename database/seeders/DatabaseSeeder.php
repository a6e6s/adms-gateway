<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $company = Company::query()->updateOrCreate(
            ['code' => 'ADMS-TEST'],
            [
                'name' => 'ADMS Gateway Test Company',
                'timezone' => 'Asia/Riyadh',
                'is_active' => true,
            ],
        );

        Device::query()->updateOrCreate(
            ['serial_number' => 'A39N203960051'],
            [
                'company_id' => $company->id,
                'name' => 'Test Attendance Device',
                'location' => 'Local Test Network',
                'timezone' => 'Asia/Riyadh',
                'protocol_profile' => 'push-2.4-attlog-v1',
                'is_enabled' => true,
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('password'),
            ],
        );
    }
}
