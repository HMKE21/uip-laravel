<?php

namespace Database\Factories;

use App\Models\SecurityReportFile;
use App\Models\SecurityReportFileVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityReportFileVersion>
 */
class SecurityReportFileVersionFactory extends Factory
{
    protected $model = SecurityReportFileVersion::class;

    public function definition(): array
    {
        return [
            'security_report_file_id' => SecurityReportFile::factory(),
            'version_number' => fake()->numberBetween(1, 1000),
            'storage_path' => fake()->filePath(),
            'original_filename' => fake()->words(2, true),
            'file_extension' => fake()->word(),
            'file_size_bytes' => fake()->numberBetween(1, 1000),
            'notes' => fake()->paragraph(),
            'uploaded_by' => User::factory(),
        ];
    }
}
