<?php

namespace Database\Factories;

use App\Models\BioTimeClient;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BioTimeClient>
 */
class BioTimeClientFactory extends Factory
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
            'username' => fake()->unique()->userName(),
            'password' => 'connector-password',
            'is_active' => true,
        ];
    }
}
