<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            \Database\Seeders\RolesSeeder::class,
            \Database\Seeders\UsersSeeder::class,
            \Database\Seeders\AcademicRolesSeeder::class,
            \Database\Seeders\OrganizationsSeeder::class,
            \Database\Seeders\ProjectsSeeder::class,
            \Database\Seeders\DemoAiAnalysisSeeder::class,
            \Database\Seeders\DemoAnalyticsSeeder::class,
            \Database\Seeders\SecurityDataPortalsSeeder::class,
            \Database\Seeders\SecurityPortalSampleDataSeeder::class,
        ]);
    }
}
