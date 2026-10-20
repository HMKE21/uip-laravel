<?php

namespace Database\Factories;

use App\Models\SecurityReportTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityReportTag>
 */
class SecurityReportTagFactory extends Factory
{
    protected $model = SecurityReportTag::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'slug' => fake()->unique()->slug(),
        ];
    }
}
