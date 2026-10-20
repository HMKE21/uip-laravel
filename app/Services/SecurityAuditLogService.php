<?php

namespace App\Services;

/**
 * منقولة من app/Services/SecurityAuditLogService.php القديمة حرفيًا —
 * بند 25 batch 4 (Logs). بتقدّم شاشة Security & Audit Logs (استريم
 * واحد مفلتر/مرقّم) فوق مصدرين حقيقيين موجودين فعلًا:
 *   - audit_logs (جدول DB، عبر AuditLogService::searchAll — فلترة/ترقيم SQL)
 *   - storage/logs/security/*.log (ملفات، عبر SecurityLogService::search)
 *
 * مفيش query واحد يقدر يرقّم على المصدرين مع بعض، فالسيرفيس ده بيجيب
 * الصفوف *المفلترة* (مش مرقّمة بعد) من كل مصدر، يدمجهم، يرتبهم
 * الأحدث أول، وبعدين يرقّم القائمة المدموجة في PHP. $days/الحدود لكل
 * مصدر بتخلي ده محدود — نفس منطق القديمة بالظبط.
 */
class SecurityAuditLogService
{
    public function __construct(
        private AuditLogService $auditLogs,
        private SecurityLogService $securityLogs
    ) {
    }

    public const EVENT_TYPES = [
        'login' => ['en' => 'Login', 'ar' => 'تسجيل دخول'],
        'login_failed' => ['en' => 'Failed Login', 'ar' => 'فشل تسجيل الدخول'],
        'logout' => ['en' => 'Logout', 'ar' => 'تسجيل خروج'],
        'account_lockout' => ['en' => 'Account Lockout', 'ar' => 'قفل الحساب'],
        'password_change' => ['en' => 'Password Change', 'ar' => 'تغيير كلمة المرور'],
        'permission_change' => ['en' => 'Permission Change', 'ar' => 'تغيير الصلاحيات'],
        'file_upload' => ['en' => 'File Upload', 'ar' => 'رفع ملف'],
        'file_download' => ['en' => 'File Download', 'ar' => 'تنزيل ملف'],
        'report_export' => ['en' => 'Report Export', 'ar' => 'تصدير تقرير'],
        'project_update' => ['en' => 'Project Update', 'ar' => 'تحديث مشروع'],
        'ai_analysis' => ['en' => 'AI Analysis', 'ar' => 'تحليل ذكاء اصطناعي'],
        'security_policy_change' => ['en' => 'Security Policy Change', 'ar' => 'تغيير سياسة أمنية'],
        'other' => ['en' => 'Other', 'ar' => 'أخرى'],
    ];

    public const SEVERITIES = ['info', 'warning', 'critical'];
    public const STATUSES = ['success', 'failed', 'warning'];
    public const ROLES = [
        'student', 'university', 'admin',
        'security_admin', 'security_officer', 'data_analyst',
    ];

    /**
     * @param array $filters زي SecurityLogService::search()/AuditLogRepository::searchPaginated()
     *   بالإضافة لـ 'source' => 'all'|'audit'|'security'
     * @return array{rows:array<int,array<string,mixed>>,total:int,totalPages:int,page:int}
     */
    public function search(array $filters, int $page, int $perPage, int $securityDays = 90): array
    {
        $source = $filters['source'] ?? 'all';
        $merged = [];

        if ($source !== 'security') {
            $merged = array_merge($merged, $this->auditLogs->searchAll($filters, 3000));
        }

        if ($source !== 'audit') {
            $merged = array_merge($merged, $this->securityLogs->search($filters, $securityDays));
        }

        $merged = $this->postFilter($merged, $filters);
        usort($merged, fn ($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));

        $total = count($merged);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        return [
            'rows'       => array_slice($merged, $offset, $perPage),
            'total'      => $total,
            'totalPages' => $totalPages,
            'page'       => $page,
        ];
    }

    /** نفس الفلاتر، كل الصفوف المطابقة (بحد أقصى) — للتصدير CSV/JSON والطباعة. */
    public function searchAll(array $filters, int $securityDays = 90, int $cap = 5000): array
    {
        $source = $filters['source'] ?? 'all';
        $merged = [];

        if ($source !== 'security') {
            $merged = array_merge($merged, $this->auditLogs->searchAll($filters, $cap));
        }
        if ($source !== 'audit') {
            $merged = array_merge($merged, $this->securityLogs->search($filters, $securityDays));
        }

        $merged = $this->postFilter($merged, $filters);
        usort($merged, fn ($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));

        return array_slice($merged, 0, $cap);
    }

    /**
     * event_type/severity/status/device/browser/os موجودة بس كحقول
     * *مشتقة* — مصدر audit الـ DB مفيهوش أعمدة تتفلتر عليها في SQL،
     * فالميثود دي بتلقط الفلاتر دي بشكل موحّد على المصدرين بعد
     * التشكيل. زيادة-بس-مش-ضارة لصفوف security اللي SecurityLogService
     * فلترها أصلًا على نفس الحقول.
     */
    private function postFilter(array $rows, array $filters): array
    {
        $checks = ['event_type', 'severity', 'status', 'device', 'browser', 'os'];
        $active = array_filter($checks, fn ($k) => !empty($filters[$k]));
        if (!$active) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($row) use ($active, $filters) {
            foreach ($active as $key) {
                if (($row[$key] ?? null) !== $filters[$key]) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * بتجمّع صفوف حسب اليوم لعرض Timeline View — الصفوف المدموجة
     * مرتبة أصلًا الأحدث أول من search()، فالتجميع مرور واحد بس.
     * @return array<string,array<int,array<string,mixed>>> تاريخ (Y-m-d) => صفوف
     */
    public function groupByDay(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $day = substr($row['time'], 0, 10);
            $groups[$day] ??= [];
            $groups[$day][] = $row;
        }
        return $groups;
    }
}
