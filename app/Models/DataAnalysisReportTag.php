<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataAnalysisReportTag extends Model
{
    use HasFactory;

    protected $table = 'data_analysis_report_tags';
    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'slug',
    ];

    // Relationships

    public function dataAnalysisReportFiles()
    {
        return $this->belongsToMany(DataAnalysisReportFile::class, 'data_analysis_report_file_tags', 'tag_id', 'data_analysis_report_file_id');
    }
}
