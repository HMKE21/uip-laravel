<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataAnalysisReportFileVersion extends Model
{
    use HasFactory;

    protected $table = 'data_analysis_report_file_versions';
    const UPDATED_AT = null;

    protected $fillable = [
        'data_analysis_report_file_id',
        'version_number',
        'storage_path',
        'original_filename',
        'file_extension',
        'file_size_bytes',
        'notes',
        'uploaded_by',
    ];

    // Relationships

    public function dataAnalysisReportFile()
    {
        return $this->belongsTo(DataAnalysisReportFile::class, 'data_analysis_report_file_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
