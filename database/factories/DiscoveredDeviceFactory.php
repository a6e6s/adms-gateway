<?php

namespace Database\Factories;

use App\Models\DiscoveredDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscoveredDevice> */
class DiscoveredDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'serial_number' => fake()->unique()->bothify('DISCOVERED-########'),
            'last_seen_ip' => fake()->ipv4(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'attempt_count' => 1,
        ];
    }
}
