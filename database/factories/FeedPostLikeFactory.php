<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostLike>
 */
class FeedPostLikeFactory extends Factory
{
    protected $model = FeedPostLike::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'user_id' => User::factory(),
        ];
    }
}
