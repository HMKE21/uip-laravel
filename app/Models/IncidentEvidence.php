<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `incident_evidence` القديم بالظبط (migration 071). الملفات
 * نفسها متخزنة على الديسك عن طريق FileUploadService تحت فئة
 * 'incident_evidence' (config/upload.php) — الصف ده بس بيحمل stored_path
 * النسبي، مش مسار خام من الكلاينت. مفيش updated_at في الجدول القديم
 * (created_at بس).
 */
class IncidentEvidence extends Model
{
    const UPDATED_AT = null;

    protected $table = 'incident_evidence';

    protected $fillable = [
        'incident_id', 'uploaded_by', 'original_filename', 'stored_path',
        'mime_type', 'file_size_bytes', 'note',
    ];
}
