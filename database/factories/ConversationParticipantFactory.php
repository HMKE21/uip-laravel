<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'joined_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'last_read_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'role' => fake()->word(),
            'is_favorite' => fake()->boolean(),
            'is_pinned' => fake()->boolean(),
            'is_muted' => fake()->boolean(),
            'is_archived' => fake()->boolean(),
            'category' => fake()->sentence(4),
            'last_typing_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
