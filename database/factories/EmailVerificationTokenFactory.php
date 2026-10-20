<?php

namespace Database\Factories;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailVerificationToken>
 */
class EmailVerificationTokenFactory extends Factory
{
    protected $model = EmailVerificationToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => fake()->sentence(4),
            'expires_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'used_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'purpose' => fake()->randomElement(['signup', 'email_change']),
            'new_email' => fake()->unique()->safeEmail(),
        ];
    }
}
