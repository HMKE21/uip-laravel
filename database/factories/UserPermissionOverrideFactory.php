<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPermissionOverride>
 */
class UserPermissionOverrideFactory extends Factory
{
    protected $model = UserPermissionOverride::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'permission_id' => Permission::factory(),
            'effect' => fake()->randomElement(['grant', 'revoke']),
            'granted_by' => User::factory(),
        ];
    }
}
