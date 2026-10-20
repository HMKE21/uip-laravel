<?php

namespace Database\Factories;

use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityLog>
 */
class SecurityLogFactory extends Factory
{
    protected $model = SecurityLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => fake()->sentence(4),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->sentence(4),
            'severity' => fake()->randomElement(['info', 'warning', 'critical']),
            'meta' => [],
        ];
    }
}
