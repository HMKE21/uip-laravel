<?php

namespace Database\Factories;

use App\Models\DataAnalysisReportFile;
use App\Models\DataAnalysisReportFileVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataAnalysisReportFileVersion>
 */
class DataAnalysisReportFileVersionFactory extends Factory
{
    protected $model = DataAnalysisReportFileVersion::class;

    public function definition(): array
    {
        return [
            'data_analysis_report_file_id' => DataAnalysisReportFile::factory(),
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
