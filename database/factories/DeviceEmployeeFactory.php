<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceEmployee;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceEmployee>
 */
class DeviceEmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'employee_id' => Employee::factory(),
            'pin' => (string) fake()->numberBetween(1, 99999),
        ];
    }
}
