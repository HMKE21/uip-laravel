<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataAnalysisReportCategory extends Model
{
    use HasFactory;

    protected $table = 'data_analysis_report_categories';
    const UPDATED_AT = null;

    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
    ];

    // Relationships

    public function dataAnalysisReportFiles()
    {
        return $this->hasMany(DataAnalysisReportFile::class, 'category_id');
    }
}
