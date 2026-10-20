<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/AuditLogService.php القديمة — record() (بند 3)
 * + forSubject() (بند 11 مرحلة 4، تاب Activity بتاع مشروع) +
 * forActionPrefixes() جديدة هنا (بند 24 batch 6 — Team Workspace
 * Activity Timeline). باقي المتودز القديمة (recent/toRow الكامل/الشكل
 * الخاص بلوحة الأدمن للـ Security portal) لسه متأجلة لبند 25، مش حاجة
 * ناقصة اتنسيت.
 */
class AuditLogService
{
    public function __construct(
        private AuditLogRepository $logs
    ) {
    }

    public function record($userId, string $action, ?string $subjectType = null, $subjectId = null, ?array $oldValues = null, ?array $newValues = null, ?string $ip = null): void
    {
        try {
            AuditLog::create([
                'user_id'      => $userId,
                'action'       => $action,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'old_values'   => $oldValues,
                'new_values'   => $newValues,
                'ip_address'   => $ip,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * منقولة من AuditLogService::recent() القديمة بالظبط — نفس صفوف
     * toRow() البايلينجوال {en,ar}، الأحدث الأول. بند 25 batch 11
     * (Admin > Audit Logs) محتاجاها لما مفيش فلاتر متطبّقة (fallback
     * الافتراضي، نفس AdminAuditLogController القديمة بالظبط).
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return array_map(fn ($row) => $this->toRow($row), $this->logs->recent($limit));
    }

    /**
     * منقولة من AuditLogService::recentForApi() القديمة — بند 25
     * (AdminDashboardApiController::index() -> 'recent_activity'). شكل
     * مسطّح (id/actor_id/action/entity_type/entity_id/before/after/
     * created_at) لسطح React Admin SPA، منفصل عن recent()/toRow()
     * البايلينجوال {en,ar} بتاعة أي Blade view قديمة.
     * @return array<int,array<string,mixed>>
     */
    public function recentForApi(int $limit = 100): array
    {
        return array_map(fn ($row) => [
            'id'          => (int) $row['id'],
            'actor_id'    => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'action'      => $row['action'],
            'entity_type' => $row['subject_type'],
            'entity_id'   => $row['subject_id'],
            'before'      => $row['old_values'] !== null ? (is_string($row['old_values']) ? json_decode($row['old_values'], true) : $row['old_values']) : null,
            'after'       => $row['new_values'] !== null ? (is_string($row['new_values']) ? json_decode($row['new_values'], true) : $row['new_values']) : null,
            'created_at'  => $row['created_at'],
        ], $this->logs->recent($limit));
    }

    /**
     * سجل نشاط كيان واحد (مثلًا مشروع)، الأحدث الأول — تاب Activity في
     * StudentProjectDetail.jsx بيتوقع
     * {id, description, created_at} لكل صف؛ description هنا حرفة مشتقة
     * من action string نفسه (project.file_upload -> "Project file
     * upload") بدل جدول ترجمة يدوي لكل action ممكن — نفس المعلومة
     * الحقيقية المسجّلة فعليًا، مش نص مختلق.
     * @return array<int,array{id:int,action:string,description:string,created_at:mixed}>
     */
    public function forSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        $rows = AuditLog::where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get();

        return $rows->map(fn (AuditLog $row) => [
            'id'          => $row->id,
            'action'      => $row->action,
            'description' => $this->humanizeAction($row->action),
            'created_at'  => $row->created_at,
        ])->all();
    }

    /** "project.file_upload" -> "Project file upload"; "project.submit" -> "Project submit". */
    private function humanizeAction(string $action): string
    {
        $label = str_replace(['.', '_'], ' ', $action);
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        return $label === '' ? $action : mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /**
     * منقولة من searchPaginated() القديمة — بند 25 batch 4 (Logs، مصدر
     * audit_logs جوه SecurityAuditLogService::search()). فلترة +
     * pagination حقيقية على مستوى الـ SQL (AuditLogRepository::
     * searchPaginated())، بعدين toSearchRow() بيضيف event_type/module/
     * status مشتقة زي القديمة بالظبط.
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function searchPaginated(array $filters, int $page, int $perPage): array
    {
        $result = $this->logs->searchPaginated($filters, $page, $perPage);

        return [
            'rows'  => array_map(fn ($row) => $this->toSearchRow($row), $result['rows']),
            'total' => $result['total'],
        ];
    }

    /** نفس فلاتر searchPaginated() بس كل الصفوف المطابقة (بحد أقصى) — للتصدير. */
    public function searchAll(array $filters, int $limit = 5000): array
    {
        $result = $this->logs->searchPaginated($filters, 1, $limit);
        return array_map(fn ($row) => $this->toSearchRow($row), $result['rows']);
    }

    private const EVENT_TYPE_KEYWORDS = [
        'security_policy'  => 'security_policy_change',
        'password'         => 'password_change',
        'two_factor'       => 'permission_change',
        'role'             => 'permission_change',
        'permission'       => 'permission_change',
        'report_delete'    => 'report_export',
        'report.generate'  => 'report_export',
        'report_schedule'  => 'report_export',
        'ai_'              => 'ai_analysis',
        'ai.'              => 'ai_analysis',
        'upload'           => 'file_upload',
        'delete'           => 'file_download',
        'project'          => 'project_update',
        'ip.block'         => 'account_lockout',
        'account_locked'   => 'account_lockout',
        'account_unlocked' => 'account_lockout',
    ];

    private function classify(string $action): string
    {
        foreach (self::EVENT_TYPE_KEYWORDS as $needle => $type) {
            if (str_contains($action, $needle)) {
                return $type;
            }
        }
        return 'other';
    }

    /** نفس toSearchRow() القديمة بالظبط — شكل صف مصدر 'audit' جوه شاشة Security & Audit Logs. */
    private function toSearchRow(array $row): array
    {
        $actorLabel = $row['actor_name'] ?? (($row['user_id'] ?? null) ? ('#' . $row['user_id']) : 'System');
        $eventType = $this->classify($row['action']);

        return [
            'source'      => 'audit',
            'actor'       => $actorLabel,
            'email'       => $row['actor_email'] ?? '—',
            'role'        => $row['actor_role'] ?? null,
            'role_label'  => $row['actor_role_en'] ?? $row['actor_role'] ?? '—',
            'university'  => $row['university_en'] ?? null,
            'ip'          => $row['ip_address'] ?? '—',
            'device'      => '—',
            'browser'     => '—',
            'os'          => '—',
            'event_type'  => $eventType,
            'module'      => $row['subject_type'] ?? 'General',
            'action'      => $row['action'],
            'target'      => ($row['subject_type'] ?? null)
                ? $row['subject_type'] . (($row['subject_id'] ?? null) ? ' #' . $row['subject_id'] : '')
                : '—',
            'severity'    => 'info',
            'status'      => 'success',
            'time'        => $row['created_at'],
        ];
    }

    /**
     * منقولة من forActionPrefixes() القديمة — بند 24 batch 6
     * (TeamWorkspaceService::activityTimeline()). صفوف مشكّلة زي
     * toRow() القديمة بالظبط ({actor:{en,ar}, action:{en,ar}, target,
     * time}) — نفس الشكل اللي DataAnalysisWorkspace.jsx بيتوقعه.
     * @param string[] $prefixes مثال ['saved_dashboard.', 'kpi.']
     * @return array<int,array<string,mixed>> الأحدث أولًا
     */
    public function forActionPrefixes(array $prefixes, int $limit = 30): array
    {
        return array_map(fn ($row) => $this->toRow($row), $this->logs->forActionPrefixes($prefixes, $limit));
    }

    /**
     * منقولة من AuditLogService::forActor() القديمة بالظبط — نشاط
     * مستخدم واحد كـ فاعل (user_id)، بنفس شكل toRow() بتاع
     * forActionPrefixes() فوق.
     * @return array<int,array<string,mixed>>
     */
    public function forActor(int $userId, int $limit = 100): array
    {
        return array_map(fn ($row) => $this->toRow($row), $this->logs->forActor($userId, $limit));
    }

    /** نفس toRow() القديمة بالظبط. */
    private function toRow(array $row): array
    {
        $actorLabel = $row['actor_name'] ?? 'System';
        if (!($row['user_id'] ?? null)) {
            $actorLabel = 'System';
        }

        $target = ($row['subject_type'] ?? null)
            ? $row['subject_type'] . (($row['subject_id'] ?? null) ? ' #' . $row['subject_id'] : '')
            : '—';

        return [
            'actor'  => ['en' => $actorLabel, 'ar' => $actorLabel],
            'action' => ['en' => $row['action'], 'ar' => $row['action']],
            'target' => $target,
            'time'   => $row['created_at'],
        ];
    }
}
