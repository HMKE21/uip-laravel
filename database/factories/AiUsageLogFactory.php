<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    protected $model = AiUsageLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'conversation_id' => AiConversation::factory(),
            'portal' => fake()->sentence(4),
            'model' => fake()->sentence(4),
            'prompt_tokens' => fake()->numberBetween(1, 1000),
            'completion_tokens' => fake()->numberBetween(1, 1000),
            'latency_ms' => fake()->latitude(),
            'was_error' => fake()->boolean(),
            'error_message' => fake()->sentence(4),
        ];
    }
}
