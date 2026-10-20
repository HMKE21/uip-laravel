<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityReportCategory extends Model
{
    use HasFactory;

    protected $table = 'security_report_categories';
    const UPDATED_AT = null;

    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
    ];

    // Relationships

    public function securityReportFiles()
    {
        return $this->hasMany(SecurityReportFile::class, 'category_id');
    }
}
