<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `user_sessions` القديم بالظبط (migration 041) — حالة
 * الجلسة الحالية (على عكس security log الملف-محور)، بتتسجل عند كل لوجين
 * ناجح عشان Security Officer يقدر يشوف ويلغي الجلسات النشطة.
 *
 * ⚠️ ملحوظة مهمة: AuthService الحالي في اللارافيل مش بيكتب لسه على
 * الجدول ده عند اللوجين (نفس الفجوة الموثّقة في SecurityLogRepository
 * بتاع بند 25 batch 1) — يعني الجدول هيفضل فاضي لحد ما حد يوصّل
 * تسجيل الجلسة بالفعل جوه AuthService::login(). الكنترولر/الريبو هنا
 * بيقروا/يعدّلوا الجدول الحقيقي بالظبط زي القديم، مفيش بيانات مختلقة.
 */
class UserSession extends Model
{
    const UPDATED_AT = null;

    protected $table = 'user_sessions';

    protected $fillable = [
        'user_id', 'session_token', 'ip_address', 'user_agent', 'device_label',
        'location_label', 'is_active', 'revoked_by', 'revoked_at', 'last_activity_at', 'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
