<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyticsRecord extends Model
{
    use HasFactory;

    protected $table = 'analytics_records';
    const UPDATED_AT = null;

    protected $fillable = [
        'metric_key',
        'metric_value',
        'dimension',
        'recorded_for_date',
    ];

    protected $casts = [
        'metric_value' => 'decimal:2',
        'recorded_for_date' => 'date',
    ];
}
