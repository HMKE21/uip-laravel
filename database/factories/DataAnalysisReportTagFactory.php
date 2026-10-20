<?php

namespace Database\Factories;

use App\Models\DataAnalysisReportTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataAnalysisReportTag>
 */
class DataAnalysisReportTagFactory extends Factory
{
    protected $model = DataAnalysisReportTag::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'slug' => fake()->unique()->slug(),
        ];
    }
}
