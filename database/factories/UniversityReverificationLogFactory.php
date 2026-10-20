<?php

namespace Database\Factories;

use App\Models\University;
use App\Models\UniversityReverificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UniversityReverificationLog>
 */
class UniversityReverificationLogFactory extends Factory
{
    protected $model = UniversityReverificationLog::class;

    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'event' => fake()->randomElement(['notified', 'auto_renewed', 'reverted_pending', 'failed']),
            'method' => fake()->randomElement(['automatic', 'manual']),
            'notes' => fake()->paragraph(),
            'actor_id' => User::factory(),
        ];
    }
}
