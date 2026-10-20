<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `saved_dashboards` القديم بالظبط (migration 042 +
 * is_shared/is_archived عبر migration 080) — تخطيط widgets محفوظ خاص
 * بمحلل بيانات واحد. `layout` عمود JSON، بيتعامل معاه الريبو
 * (SavedDashboardRepository) يدويًا encode/decode.
 */
class SavedDashboard extends Model
{
    protected $table = 'saved_dashboards';

    protected $fillable = ['user_id', 'name', 'layout', 'is_default', 'is_shared', 'is_archived'];
}
