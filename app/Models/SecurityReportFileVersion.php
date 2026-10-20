<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityReportFileVersion extends Model
{
    use HasFactory;

    protected $table = 'security_report_file_versions';
    const UPDATED_AT = null;

    protected $fillable = [
        'security_report_file_id',
        'version_number',
        'storage_path',
        'original_filename',
        'file_extension',
        'file_size_bytes',
        'notes',
        'uploaded_by',
    ];

    // Relationships

    public function securityReportFile()
    {
        return $this->belongsTo(SecurityReportFile::class, 'security_report_file_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
