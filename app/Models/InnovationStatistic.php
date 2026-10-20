<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InnovationStatistic extends Model
{
    use HasFactory;

    protected $table = 'innovation_statistics';
    const UPDATED_AT = null;

    protected $fillable = [
        'university_id',
        'category',
        'total_projects',
        'approved_projects',
        'avg_readiness_score',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'avg_readiness_score' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    // Relationships

    public function university()
    {
        return $this->belongsTo(University::class, 'university_id');
    }
}
