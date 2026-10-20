<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostReport>
 */
class FeedPostReportFactory extends Factory
{
    protected $model = FeedPostReport::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'reporter_user_id' => User::factory(),
            'reason' => fake()->sentence(4),
            'status' => fake()->randomElement(['pending', 'actioned', 'dismissed']),
            'reviewed_by' => User::factory(),
            'reviewed_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'resolution_notes' => fake()->sentence(4),
        ];
    }
}
