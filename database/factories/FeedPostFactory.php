<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\FeedPost;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPost>
 */
class FeedPostFactory extends Factory
{
    protected $model = FeedPost::class;

    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'author_user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_event' => fake()->boolean(),
            'event_starts_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'event_ends_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'event_location' => fake()->sentence(4),
            'is_pinned' => fake()->boolean(),
            'pinned_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'likes_count' => fake()->numberBetween(0, 1000),
            'comments_count' => fake()->numberBetween(0, 1000),
            'shares_count' => fake()->numberBetween(0, 1000),
            'saves_count' => fake()->numberBetween(0, 1000),
            'deleted_reason' => fake()->sentence(4),
            'status' => fake()->boolean(),
            'is_edited' => fake()->boolean(),
            'edited_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'target_faculty_id' => Faculty::factory(),
            'target_department_id' => Department::factory(),
            'target_academic_year' => fake()->numberBetween(1, 1000),
        ];
    }
}
