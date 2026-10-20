<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityReportTag extends Model
{
    use HasFactory;

    protected $table = 'security_report_tags';
    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'slug',
    ];

    // Relationships

    public function securityReportFiles()
    {
        return $this->belongsToMany(SecurityReportFile::class, 'security_report_file_tags', 'tag_id', 'security_report_file_id');
    }
}
