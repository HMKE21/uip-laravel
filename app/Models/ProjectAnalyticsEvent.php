<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `project_analytics_events` القديم بالظبط (migration 141)
 * — بند 11 مرحلة 4 (Analytics). صف واحد لكل تفاعل قابل للتتبع على صفحة
 * مشروع منشور عامة (مشاهدة/نقرة GitHub/نقرة عرض/نقرة رابط/تنزيل ملف/
 * طلب تواصل). append-only — مفيش update/delete على الجدول ده، بس
 * INSERT (record) وتجميع (summary/trend/top)، شوف
 * ProjectAnalyticsRepository.
 */
class ProjectAnalyticsEvent extends Model
{
    const UPDATED_AT = null;

    protected $table = 'project_analytics_events';

    protected $fillable = [
        'project_id', 'event_type', 'visitor_hash', 'meta',
    ];
}
