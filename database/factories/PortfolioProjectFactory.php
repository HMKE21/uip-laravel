<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioProject;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioProject>
 */
class PortfolioProjectFactory extends Factory
{
    protected $model = PortfolioProject::class;

    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'project_id' => Project::factory(),
            'display_order' => fake()->numberBetween(0, 100),
        ];
    }
}
