<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageMention>
 */
class MessageMentionFactory extends Factory
{
    protected $model = MessageMention::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'mentioned_user_id' => User::factory(),
        ];
    }
}
