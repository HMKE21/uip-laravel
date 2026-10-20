<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostShare>
 */
class FeedPostShareFactory extends Factory
{
    protected $model = FeedPostShare::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'user_id' => User::factory(),
            'conversation_id' => fake()->numberBetween(1, 1000),
        ];
    }
}
