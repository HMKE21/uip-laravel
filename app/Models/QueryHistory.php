<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/QueryHistory.php القديمة — بند 24 batch 4 (SQL
 * Query Builder، enhancement spec section 3). تطابق جدول `query_history`
 * (migration 082) — سجل تشغيل append-only، نفس اتفاقية
 * DataExplorerActivity/SecurityLog. صف واحد لكل تشغيل، نجح أو فشل.
 * executed_at ليها DEFAULT CURRENT_TIMESTAMP على مستوى الجدول، فمفيش
 * updated_at ولا created_at قياسي.
 */
class QueryHistory extends Model
{
    protected $table = 'query_history';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'saved_query_id', 'sql_text', 'status',
        'error_message', 'row_count', 'execution_time_ms',
    ];
}
