<?php

namespace Database\Factories;

use App\Models\University;
use App\Models\UniversityVerificationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UniversityVerificationRequest>
 */
class UniversityVerificationRequestFactory extends Factory
{
    protected $model = UniversityVerificationRequest::class;

    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'document_path' => fake()->filePath(),
            'notes' => fake()->paragraph(),
            'status' => fake()->randomElement(['pending', 'approved', 'rejected']),
            'reviewed_by' => User::factory(),
            'reviewed_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
