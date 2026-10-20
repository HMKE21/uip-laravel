<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageHiddenForUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageHiddenForUser>
 */
class MessageHiddenForUserFactory extends Factory
{
    protected $model = MessageHiddenForUser::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'user_id' => User::factory(),
            'hidden_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
