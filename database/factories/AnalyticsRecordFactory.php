<?php

namespace Database\Factories;

use App\Models\AnalyticsRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsRecord>
 */
class AnalyticsRecordFactory extends Factory
{
    protected $model = AnalyticsRecord::class;

    public function definition(): array
    {
        return [
            'metric_key' => fake()->sentence(4),
            'metric_value' => fake()->randomFloat(2, 0, 1000),
            'dimension' => fake()->sentence(4),
            'recorded_for_date' => fake()->date(),
        ];
    }
}
