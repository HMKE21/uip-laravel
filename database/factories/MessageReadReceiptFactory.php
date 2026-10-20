<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageReadReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageReadReceipt>
 */
class MessageReadReceiptFactory extends Factory
{
    protected $model = MessageReadReceipt::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'user_id' => User::factory(),
            'delivered_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'read_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
