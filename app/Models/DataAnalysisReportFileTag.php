<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataAnalysisReportFileTag extends Model
{
    use HasFactory;

    protected $table = 'data_analysis_report_file_tags';
    public $incrementing = false;
    // Composite primary key (data_analysis_report_file_id, tag_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [];

    // Relationships

    public function dataAnalysisReportFile()
    {
        return $this->belongsTo(DataAnalysisReportFile::class, 'data_analysis_report_file_id');
    }

    public function tag()
    {
        return $this->belongsTo(DataAnalysisReportTag::class, 'tag_id');
    }
}
