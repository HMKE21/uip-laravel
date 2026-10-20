<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationEmailQueue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationEmailQueue>
 */
class NotificationEmailQueueFactory extends Factory
{
    protected $model = NotificationEmailQueue::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notification_id' => Notification::factory(),
            'kind' => fake()->word(),
            'to_email' => fake()->unique()->safeEmail(),
            'to_name' => fake()->words(2, true),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'link_url' => fake()->url(),
            'locale' => fake()->word(),
            'status' => fake()->word(),
            'attempts' => fake()->numberBetween(1, 1000),
            'max_attempts' => fake()->numberBetween(1, 1000),
            'next_attempt_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'last_error' => fake()->sentence(4),
            'sent_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
