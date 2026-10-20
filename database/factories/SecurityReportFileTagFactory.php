<?php

namespace Database\Factories;

use App\Models\SecurityReportFile;
use App\Models\SecurityReportFileTag;
use App\Models\SecurityReportTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityReportFileTag>
 */
class SecurityReportFileTagFactory extends Factory
{
    protected $model = SecurityReportFileTag::class;

    public function definition(): array
    {
        return [
            'security_report_file_id' => SecurityReportFile::factory(),
            'tag_id' => SecurityReportTag::factory(),
        ];
    }
}
