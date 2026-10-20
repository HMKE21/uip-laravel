<?php

namespace Database\Factories;

use App\Models\InnovationStatistic;
use App\Models\University;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InnovationStatistic>
 */
class InnovationStatisticFactory extends Factory
{
    protected $model = InnovationStatistic::class;

    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'category' => fake()->sentence(4),
            'total_projects' => fake()->numberBetween(1, 1000),
            'approved_projects' => fake()->numberBetween(1, 1000),
            'avg_readiness_score' => fake()->randomFloat(2, 0, 100),
            'period_start' => fake()->date(),
            'period_end' => fake()->date(),
        ];
    }
}
