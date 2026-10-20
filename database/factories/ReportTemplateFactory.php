<?php

namespace Database\Factories;

use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'name' => fake()->name(),
            'description' => fake()->paragraph(),
            'data_source' => fake()->randomElement(['projects', 'users', 'ai_analysis', 'analytics_records', 'innovation_statistics', 'security_logs', 'exams', 'exam_attempts', 'academic_staff']),
            'query_config' => [],
            'is_shared' => fake()->boolean(),
        ];
    }
}
