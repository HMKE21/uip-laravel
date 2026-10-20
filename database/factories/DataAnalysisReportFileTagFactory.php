<?php

namespace Database\Factories;

use App\Models\DataAnalysisReportFile;
use App\Models\DataAnalysisReportFileTag;
use App\Models\DataAnalysisReportTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataAnalysisReportFileTag>
 */
class DataAnalysisReportFileTagFactory extends Factory
{
    protected $model = DataAnalysisReportFileTag::class;

    public function definition(): array
    {
        return [
            'data_analysis_report_file_id' => DataAnalysisReportFile::factory(),
            'tag_id' => DataAnalysisReportTag::factory(),
        ];
    }
}
