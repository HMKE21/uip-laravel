<?php

namespace Database\Factories;

use App\Models\DemoSeedLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoSeedLog>
 */
class DemoSeedLogFactory extends Factory
{
    protected $model = DemoSeedLog::class;

    public function definition(): array
    {
        return [
            'batch_id' => fake()->regexify('[A-Za-z0-9]{36}'),
            'table_name' => fake()->words(2, true),
            'record_id' => fake()->numberBetween(1, 1000),
        ];
    }
}
