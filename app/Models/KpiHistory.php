<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/KpiHistory.php القديمة — بند 24 batch 3. تطابق
 * جدول `kpi_history` (migration 075) — سجل append-only، صف واحد لكل
 * قيمة مسجّلة. الـ trend وgrowth rate وachievement% والرسم البياني في
 * صفحة KPI Management كلهم محسوبين من الجدول ده بواسطة KpiRepository،
 * عمرهم مش من عمود مخزّن/مشتق. recorded_at ليها DEFAULT CURRENT_TIMESTAMP
 * على مستوى الجدول، فمفيش updated_at ولا عمود created_at قياسي.
 */
class KpiHistory extends Model
{
    protected $table = 'kpi_history';

    public $timestamps = false;

    protected $fillable = ['kpi_id', 'value', 'note', 'recorded_by'];
}
