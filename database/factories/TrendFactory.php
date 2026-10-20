<?php

namespace Database\Factories;

use App\Models\Trend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trend>
 */
class TrendFactory extends Factory
{
    protected $model = Trend::class;

    public function definition(): array
    {
        return [
            'topic' => fake()->sentence(4),
            'trend_score' => fake()->randomFloat(2, 0, 100),
            'project_count' => fake()->numberBetween(0, 1000),
            'period_month' => fake()->date(),
        ];
    }
}
