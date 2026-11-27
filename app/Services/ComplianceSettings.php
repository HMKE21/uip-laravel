<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** مخزن إعدادات صغير (key → JSON) لإعدادات الامتثال: الاحتفاظ بالسجلات + دورية مراجعة الصلاحيات. */
class ComplianceSettings
{
    public const DEFAULTS = [
        'audit_retention' => [
            'enabled'               => false, // مفيش مسح تلقائي إلا لو الأدمن فعّله صراحة
            'audit_days'            => 730,
            'security_log_days'     => 365,
            'archive_before_delete' => true,
            'legal_hold'            => false,
            'legal_hold_reason'     => '',
        ],
        'access_review' => [
            'interval_days' => 90,
            'dormant_days'  => 90,
        ],
    ];

    public const MIN_AUDIT_DAYS = 180;
    public const MIN_LOG_DAYS = 30;

    /** @return array<string,mixed> */
    public function get(string $key): array
    {
        $defaults = self::DEFAULTS[$key] ?? [];
        $row = DB::table('compliance_settings')->where('skey', $key)->first();
        $stored = $row ? (json_decode((string) $row->value, true) ?: []) : [];

        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    /** @param array<string,mixed> $value */
    public function put(string $key, array $value, ?int $userId): void
    {
        DB::table('compliance_settings')->updateOrInsert(
            ['skey' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $userId, 'updated_at' => now()]
        );
    }
}
