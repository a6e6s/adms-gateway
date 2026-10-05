<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCommand>
 */
class DeviceCommandFactory extends Factory
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
            'type' => 'request_attendance',
            'wire_command_id' => fake()->unique()->numberBetween(1, 1000000),
            'wire_payload' => 'DATA QUERY ATTLOG',
            'status' => 'pending',
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
