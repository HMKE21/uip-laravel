<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserPresence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPresence>
 */
class UserPresenceFactory extends Factory
{
    protected $model = UserPresence::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'last_seen_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'is_typing_in' => Conversation::factory(),
            'typing_started_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
