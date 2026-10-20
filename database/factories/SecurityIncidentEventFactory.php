<?php

namespace Database\Factories;

use App\Models\SecurityIncident;
use App\Models\SecurityIncidentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityIncidentEvent>
 */
class SecurityIncidentEventFactory extends Factory
{
    protected $model = SecurityIncidentEvent::class;

    public function definition(): array
    {
        return [
            'incident_id' => SecurityIncident::factory(),
            'user_id' => User::factory(),
            'event_type' => fake()->sentence(4),
            'note' => fake()->paragraph(),
        ];
    }
}
