<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataExplorerActivity.php القديمة — بند 24
 * batch 4 (Data Explorer، enhancement spec section 2). تطابق جدول
 * `data_explorer_activity` (migration 074) — سجل append-only، صف واحد
 * لكل حدث "فتح dataset". "Recently opened" بيتقرا كـ
 * MAX(opened_at) GROUP BY dataset_key ORDER BY الماكس ده DESC (شوف
 * DataExplorerRepository::recentlyOpened())، نفس اتفاقية
 * security_logs/audit_logs. opened_at ليها DEFAULT CURRENT_TIMESTAMP
 * على مستوى الجدول، فمفيش updated_at ولا created_at قياسي.
 */
class DataExplorerActivity extends Model
{
    protected $table = 'data_explorer_activity';

    public $timestamps = false;

    protected $fillable = ['user_id', 'dataset_key'];
}
