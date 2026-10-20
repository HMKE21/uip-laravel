<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityReportFileTag extends Model
{
    use HasFactory;

    protected $table = 'security_report_file_tags';
    public $incrementing = false;
    // Composite primary key (security_report_file_id, tag_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [];

    // Relationships

    public function securityReportFile()
    {
        return $this->belongsTo(SecurityReportFile::class, 'security_report_file_id');
    }

    public function tag()
    {
        return $this->belongsTo(SecurityReportTag::class, 'tag_id');
    }
}
