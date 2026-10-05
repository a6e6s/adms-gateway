<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'serial_number' => fake()->unique()->bothify('DEVICE-########'),
            'name' => fake()->words(2, true),
            'location' => fake()->optional()->city(),
            'expected_ip' => null,
            'timezone' => 'UTC',
            'protocol_profile' => 'push-2.4-attlog-v1',
            'is_enabled' => true,
        ];
    }
}
