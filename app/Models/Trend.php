<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trend extends Model
{
    use HasFactory;

    protected $table = 'trends';
    const UPDATED_AT = null;

    protected $fillable = [
        'topic',
        'trend_score',
        'project_count',
        'period_month',
    ];

    protected $casts = [
        'trend_score' => 'decimal:2',
        'period_month' => 'date',
    ];
}
