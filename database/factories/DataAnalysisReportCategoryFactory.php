<?php

namespace Database\Factories;

use App\Models\DataAnalysisReportCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataAnalysisReportCategory>
 */
class DataAnalysisReportCategoryFactory extends Factory
{
    protected $model = DataAnalysisReportCategory::class;

    public function definition(): array
    {
        return [
            'name_en' => fake()->name(),
            'name_ar' => fake()->name(),
            'slug' => fake()->unique()->slug(),
        ];
    }
}
