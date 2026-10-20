<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/SecurityReportFile.php القديمة — بند 25 batch 5
 * (Report Management). تطابق جدول `security_report_files` (migration
 * 072) — ملف تقرير مرفوع فعليًا (تقارير تدقيق، pentest، امتثال...)،
 * بعكس `Report` (App\Models\Report) اللي دايمًا متولّد أوتوماتيكيًا من
 * بيانات المنصة عن طريق ReportService. نفس شكل DataAnalysisReportFile
 * بالظبط.
 */
class SecurityReportFile extends Model
{
    protected $table = 'security_report_files';

    protected $fillable = [
        'uploaded_by', 'category_id', 'title', 'description',
        'original_filename', 'file_extension', 'mime_type', 'file_size_bytes',
        'storage_path', 'download_count', 'view_count', 'is_archived',
    ];
}
