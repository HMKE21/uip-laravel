<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataAnalysisReportFile.php القديمة — بند 24
 * batch 5 (Report Management، enhancement spec section 1). تطابق جدول
 * `data_analysis_report_files` (migration 073) — ملف تقرير مرفوع فعليًا
 * (تحليلات مكتوبة، ملخصات تنفيذية، توقعات...)، بعكس `Report`
 * (App\Models\Report) اللي دايمًا متولّد أوتوماتيكيًا من بيانات المنصة
 * عن طريق ReportService. نفس شكل App\Models\SecurityReportFile.
 */
class DataAnalysisReportFile extends Model
{
    protected $table = 'data_analysis_report_files';

    protected $fillable = [
        'uploaded_by', 'category_id', 'title', 'description',
        'original_filename', 'file_extension', 'mime_type', 'file_size_bytes',
        'storage_path', 'download_count', 'view_count', 'is_archived',
    ];
}
