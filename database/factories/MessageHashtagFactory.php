<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageHashtag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageHashtag>
 */
class MessageHashtagFactory extends Factory
{
    protected $model = MessageHashtag::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'tag' => fake()->sentence(4),
        ];
    }
}
