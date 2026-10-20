<?php

namespace App\Services;

use App\Helpers\UserAgentParser;
use App\Repositories\SecurityLogRepository;

/**
 * منقولة من app/Services/SecurityLogService.php القديمة حرفيًا — بند 25
 * batch 4 (Logs). بتصنّف وتشكّل أحداث storage/logs/security/*.log اللي
 * SecurityLogRepository::readDays() بيقرأها. severity/event_type/status
 * مشتقة بمطابقة كلمات مفتاحية بسيطة على نص الرسالة نفسه (زي القديمة
 * بالظبط — مفيش حقول منفصلة تتوثق بيها). device/browser/os من
 * context['user_agent'] لو موجودة.
 *
 * ⚠️ نفس الفجوة الموثّقة في SecurityLogRepository: recent()/search()
 * هترجع فاضية فعليًا لحد ما AuthService يبدأ يكتب فعليًا على هذه
 * الملفات — القراءة والتصنيف هنا صحيحين 100% ومطابقين للقديم.
 */
class SecurityLogService
{
    public function __construct(private SecurityLogRepository $logs)
    {
    }

    private const SEVERITY_MAP = [
        'Blocked login'                     => 'critical',
        'Malware detected in uploaded file' => 'critical',
        'Account locked after failed login attempts' => 'critical',
        'Failed login attempt'              => 'warning',
        'Malware scan could not complete'   => 'warning',
        'Malware scan skipped'              => 'warning',
        'Login blocked - account locked'    => 'warning',
        'Blocked login for'                 => 'warning',
        'Trusted device validator mismatch' => 'warning',
    ];

    /** أطول/أدق البادئات الأول — مطابقة بـ str_starts_with(). */
    private const EVENT_TYPE_MAP = [
        'Successful login'                     => 'login',
        'Login blocked - account locked'       => 'login_failed',
        'Blocked login'                        => 'login_failed',
        'Failed login attempt'                 => 'login_failed',
        'User logged out'                      => 'logout',
        'Account locked after failed login attempts' => 'account_lockout',
        'Account auto-unlocked'                => 'account_lockout',
        'Account manually unlocked'            => 'account_lockout',
        'Failed-login lockout policy updated'  => 'security_policy_change',
        'Password reset completed'             => 'password_change',
        'Password policy updated'              => 'security_policy_change',
        'Email address changed'                => 'permission_change',
        'Two-factor authentication enabled'    => 'permission_change',
        'Two-factor authentication disabled'   => 'permission_change',
        'Two-factor recovery code used'        => 'login',
        'Device remembered for 2FA'            => 'permission_change',
        'Malware detected in uploaded file'    => 'file_upload',
        'Malware scan could not complete'      => 'file_upload',
        'Malware scan skipped'                 => 'file_upload',
        'User registered'                      => 'account_lockout',
    ];

    private const MODULE_MAP = [
        'login'      => 'Authentication',
        'logout'     => 'Authentication',
        'password_change' => 'Authentication',
        'account_lockout'  => 'Account Security',
        'permission_change' => 'Access Control',
        'file_upload' => 'File Management',
        'security_policy_change' => 'Security Policies',
    ];

    /** @return array<int,array<string,mixed>> شكل شاشات admin/security القديمة (بدون تغيير) */
    public function recent(int $days = 14, int $limit = 300): array
    {
        return array_map(fn ($e) => $this->toRow($e), $this->logs->recent($days, $limit));
    }

    /**
     * أحداث مفلترة ومرتبة (الأحدث أولًا)، مضاف لها الحقول اللي شاشة
     * Security & Audit Logs بتفلتر عليها. مفيش pagination هنا — الكولر
     * (SecurityAuditLogService) بيدمجها مع مصدر audit_logs قبل الترقيم.
     *
     * @param array{q?:string,user?:string,ip?:string,severity?:string,
     *   event_type?:string,device?:string,browser?:string,os?:string,
     *   status?:string,date_from?:string,date_to?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $days = 90): array
    {
        $rows = array_map(fn ($e) => $this->toRow($e), $this->logs->readDays($days));

        return array_values(array_filter($rows, function (array $row) use ($filters) {
            if (!empty($filters['q'])) {
                $needle = mb_strtolower($filters['q']);
                $haystack = mb_strtolower($row['event'] . ' ' . $row['user'] . ' ' . $row['ip']);
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }
            if (!empty($filters['user']) && stripos($row['user'], $filters['user']) === false) {
                return false;
            }
            if (!empty($filters['ip']) && stripos($row['ip'], $filters['ip']) === false) {
                return false;
            }
            if (!empty($filters['severity']) && $row['severity'] !== $filters['severity']) {
                return false;
            }
            if (!empty($filters['event_type']) && $row['event_type'] !== $filters['event_type']) {
                return false;
            }
            if (!empty($filters['device']) && $row['device'] !== $filters['device']) {
                return false;
            }
            if (!empty($filters['browser']) && $row['browser'] !== $filters['browser']) {
                return false;
            }
            if (!empty($filters['os']) && $row['os'] !== $filters['os']) {
                return false;
            }
            if (!empty($filters['status']) && $row['status'] !== $filters['status']) {
                return false;
            }
            if (!empty($filters['module']) && $row['module'] !== $filters['module']) {
                return false;
            }
            if (!empty($filters['date_from']) && strtotime($row['time']) < strtotime($filters['date_from'])) {
                return false;
            }
            if (!empty($filters['date_to']) && strtotime($row['time']) > strtotime($filters['date_to'] . ' 23:59:59')) {
                return false;
            }
            return true;
        }));
    }

    private function toRow(array $event): array
    {
        $severity = 'info';
        foreach (self::SEVERITY_MAP as $needle => $level) {
            if (str_starts_with($event['message'], $needle)) {
                $severity = $level;
                break;
            }
        }

        $eventType = 'other';
        foreach (self::EVENT_TYPE_MAP as $needle => $type) {
            if (str_starts_with($event['message'], $needle)) {
                $eventType = $type;
                break;
            }
        }

        $status = 'success';
        if ($severity === 'critical' || $severity === 'warning') {
            $status = 'failed';
        }
        if (str_contains($event['message'], 'skipped') || str_contains($event['message'], 'could not complete')) {
            $status = 'warning';
        }

        $context = $event['context'];
        $user = $context['email'] ?? ($context['user_id'] ?? null);
        $ua = UserAgentParser::parse($context['user_agent'] ?? null);

        return [
            'severity'   => $severity,
            'event'      => $event['message'],
            'event_type' => $eventType,
            'module'     => self::MODULE_MAP[$eventType] ?? 'General',
            'status'     => $status,
            'user'       => $user !== null ? (string) $user : '—',
            'ip'         => $context['ip'] ?? '—',
            'device'     => $ua['device'],
            'browser'    => $ua['browser'],
            'os'         => $ua['os'],
            'time'       => $event['time'],
        ];
    }
}
