<?php

namespace Database\Factories;

use App\Models\DemoSeedBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoSeedBatch>
 */
class DemoSeedBatchFactory extends Factory
{
    protected $model = DemoSeedBatch::class;

    public function definition(): array
    {
        return [
            'batch_id' => fake()->regexify('[A-Za-z0-9]{36}'),
            'label' => fake()->sentence(4),
            'students_count' => fake()->numberBetween(0, 1000),
            'status' => fake()->randomElement(['running', 'completed', 'failed', 'deleted']),
            'started_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'finished_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'notes' => fake()->paragraph(),
        ];
    }
}
