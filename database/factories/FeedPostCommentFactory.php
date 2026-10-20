<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostComment>
 */
class FeedPostCommentFactory extends Factory
{
    protected $model = FeedPostComment::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'deleted_reason' => fake()->sentence(4),
        ];
    }
}
