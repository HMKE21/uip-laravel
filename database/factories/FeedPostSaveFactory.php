<?php

namespace Database\Factories;

use App\Models\FeedPost;
use App\Models\FeedPostSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPostSave>
 */
class FeedPostSaveFactory extends Factory
{
    protected $model = FeedPostSave::class;

    public function definition(): array
    {
        return [
            'post_id' => FeedPost::factory(),
            'user_id' => User::factory(),
        ];
    }
}
