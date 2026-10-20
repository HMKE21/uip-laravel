<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    use HasFactory;

    protected $table = 'report_templates';

    protected $fillable = [
        'created_by',
        'name',
        'description',
        'data_source',
        'query_config',
        'is_shared',
    ];

    protected $casts = [
        'query_config' => 'array',
        'is_shared' => 'boolean',
    ];

    // Relationships

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
