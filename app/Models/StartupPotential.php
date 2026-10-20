<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `startup_potential` القديم (migration 014). صف واحد لكل مشروع (project_id UNIQUE). */
class StartupPotential extends Model
{
    protected $table = 'startup_potential';

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'potential_score', 'market_size_estimate',
        'competitive_edge', 'risk_factors', 'is_demo_data',
    ];

    protected $casts = [
        'potential_score' => 'decimal:2',
        'risk_factors'    => 'array',
        'is_demo_data'    => 'boolean',
    ];

    /** @return string[] */
    public function risks(): array
    {
        return is_array($this->risk_factors) ? array_values($this->risk_factors) : [];
    }
}