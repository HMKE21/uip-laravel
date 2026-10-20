<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/Kpi.php القديمة — بند 24 batch 3 (KPI
 * Management، enhancement spec section 10). تطابق جدول `kpis` (migration
 * 075). current_value بتفضل متزامنة مع آخر صف في kpi_history عن طريق
 * KpiRepository::recordValue() — موجودة كعمود مستقل (بدل ما تتحسب في كل
 * قراءة) عشان سرد/ترتيب الـ KPIs مايحتاجش correlated subquery لكل صف.
 */
class Kpi extends Model
{
    protected $table = 'kpis';

    protected $fillable = [
        'name', 'description', 'category', 'unit',
        'current_value', 'target_value', 'direction',
        'alert_enabled', 'alert_threshold', 'status',
        'created_by', 'assigned_to',
    ];
}
