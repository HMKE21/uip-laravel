<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `risk_scores` القديم بالظبط (migration 041) — صف واحد لكل
 * (entity_type, entity_ref)، factors JSON column.
 */
class RiskScore extends Model
{
    const UPDATED_AT = null;
    const CREATED_AT = null;

    protected $table = 'risk_scores';

    protected $fillable = ['entity_type', 'entity_ref', 'score', 'level', 'factors', 'calculated_at'];

    protected $casts = [
        'factors' => 'array',
    ];
}
