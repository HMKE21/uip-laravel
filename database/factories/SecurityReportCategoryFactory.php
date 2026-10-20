<?php

namespace Database\Factories;

use App\Models\SecurityReportCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityReportCategory>
 */
class SecurityReportCategoryFactory extends Factory
{
    protected $model = SecurityReportCategory::class;

    public function definition(): array
    {
        return [
            'name_en' => fake()->name(),
            'name_ar' => fake()->name(),
            'slug' => fake()->unique()->slug(),
        ];
    }
}
