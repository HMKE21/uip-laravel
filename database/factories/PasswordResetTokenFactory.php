<?php

namespace Database\Factories;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PasswordResetToken>
 */
class PasswordResetTokenFactory extends Factory
{
    protected $model = PasswordResetToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => fake()->sentence(4),
            'expires_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'used_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
