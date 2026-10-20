<?php

namespace App\Services;

use App\Helpers\ReportExportWriter;
use App\Models\Report;
use App\Models\University;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SecurityIncidentRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;
use App\Repositories\VulnerabilityRepository;

/**
 * منقولة من app/Services/ReportService.php القديمة (635 سطر) — بس
 * الأنواع اللي وراها بيانات حقيقية *متاحة فعلًا* في اللارافيل دلوقتي.
 *
 * security_incident_summary و vulnerability_summary اتضافوا في بند 25
 * batch 5 (Security Portal — Reports) — SecurityIncidentRepository/
 * VulnerabilityRepository بقوا جاهزين فعليًا من batch 1-3، فمفيش داعي
 * لتأجيلهم زي ما كانوا قبل كده. مُنطاقة self::SECURITY_TYPES بس بدون
 * scope_id (platform-wide، زي self::TYPES بالظبط — نفس سلوك
 * SecurityReportsApiController القديمة اللي كانت بتبعت null دايمًا).
 *
 * كل باقي الأنواع (platform-wide + University
 * المُنطاقة) منقولة بالظبط، من غير أي بيانات مُخترعة.
 * @package UIP
 */
class ReportService
{
    /** الأنواع المتاحة فعلًا platform-wide دلوقتي. */
    public const TYPES = [
        'platform_usage_summary', 'university_onboarding_digest', 'audit_log_export', 'platform_growth_report',
        'analytics_platform_export',
    ];

    /** بند 25 batch 5 — تقارير Security Portal، platform-wide زي القديمة بالظبط. */
    public const SECURITY_TYPES = ['security_incident_summary', 'vulnerability_summary'];

    public const UNIVERSITY_TYPES = ['university_student_roster', 'university_project_log'];

    public function __construct(
        private ReportRepository $reports,
        private UserRepository $users,
        private UniversityRepository $universities,
        private ProjectRepository $projects,
        private AuditLogRepository $auditLogs,
        private StudentRepository $students,
        private AnalyticsService $analytics,
        private SecurityIncidentRepository $securityIncidents,
        private VulnerabilityRepository $vulnerabilities
    ) {
    }

    /**
     * @param int|null $scopeId مطلوب (وليه معنى) بس لـ self::UNIVERSITY_TYPES
     * @param string $format 'csv'|'pdf'|'xlsx'|'json'|'docx' — شوف ReportExportWriter
     * @param int|null $scheduleId لو الجري ده جه من صف report_schedules، بيربط reports.schedule_id بيه
     *        عشان ReportRepository::forSchedule() تعرض تاريخ تنفيذ حقيقي لكل جدولة.
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function generate(string $type, $userId, ?int $scopeId = null, string $format = 'csv', ?int $scheduleId = null): Report
    {
        $isScoped = in_array($type, self::UNIVERSITY_TYPES, true);
        $isUnscopedExtra = in_array($type, self::SECURITY_TYPES, true);
        if (!in_array($type, self::TYPES, true) && !$isUnscopedExtra && !$isScoped) {
            throw new \InvalidArgumentException('Unknown report type.');
        }
        if ($isScoped && !$scopeId) {
            throw new \InvalidArgumentException('Missing scope for this report.');
        }
        if (!in_array($format, ['csv', 'pdf', 'xlsx', 'json', 'docx'], true)) {
            throw new \InvalidArgumentException('Unknown export format.');
        }

        [$header, $rows] = $this->buildRows($type, $scopeId);

        $relativeDir = 'uploads/reports';
        $dir = public_path($relativeDir);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not prepare the reports folder.');
        }

        $filename = $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
        $fullPath = $dir . '/' . $filename;

        ReportExportWriter::write($format, $header, $rows, $fullPath, ucwords(str_replace(['_', '-'], ' ', $type)));

        return $this->reports->create([
            'generated_by' => $userId,
            'schedule_id'  => $scheduleId,
            'report_type'  => $type,
            'format'       => $format,
            'file_path'    => $relativeDir . '/' . $filename,
            'status'       => 'ready',
        ]);
    }

    /**
     * حذف آمن: بيتحقق من الملكية، بيشيل الملف المولّد من الديسك (مش صف
     * الداتابيز بس — ملف يتيم تحت public/uploads/reports هيفضل قابل
     * للتحميل لأي حد كان عنده اللينك)، وبعدين صف reports نفسه.
     */
    public function delete($reportId, $userId): bool
    {
        $report = $this->reports->findOwned($reportId, $userId);
        if (!$report) {
            return false;
        }

        if ($report->file_path) {
            $fullPath = public_path(ltrim($report->file_path, '/'));
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        return $this->reports->delete($reportId, $userId);
    }

    /** @return array{0:string[],1:array<int,array<int,mixed>>} */
    private function buildRows(string $type, ?int $scopeId = null): array
    {
        return match ($type) {
            'platform_usage_summary'        => $this->platformUsageSummary(),
            'university_onboarding_digest'  => $this->universityOnboardingDigest(),
            'audit_log_export'              => $this->auditLogExport(),
            'platform_growth_report'        => $this->platformGrowthReport(),
            'university_student_roster'     => $this->universityStudentRoster($scopeId),
            'university_project_log'        => $this->universityProjectLog($scopeId),
            'analytics_platform_export'     => $this->analyticsPlatformExport(),
            'security_incident_summary'     => $this->securityIncidentSummary(),
            'vulnerability_summary'         => $this->vulnerabilitySummary(),
            default => throw new \InvalidArgumentException('Unknown report type.'),
        };
    }

    // -- بند 25 batch 5: Security Portal reports -----------------------------

    private function securityIncidentSummary(): array
    {
        $rows = [];
        foreach ($this->securityIncidents->allWithAssignee(1000) as $i) {
            $rows[] = [
                $i['reference_code'], $i['title'], $i['category'], $i['severity'], $i['status'],
                $i['assignee_name'] ?? 'Unassigned', $i['detected_at'], $i['resolved_at'] ?? '',
            ];
        }
        return [['Reference', 'Title', 'Category', 'Severity', 'Status', 'Assigned To', 'Detected', 'Resolved'], $rows];
    }

    private function vulnerabilitySummary(): array
    {
        $rows = [];
        foreach ($this->vulnerabilities->allWithAssignee(1000) as $v) {
            $rows[] = [
                $v['title'], $v['affected_component'] ?? '', $v['severity'], $v['cvss_score'] ?? '',
                $v['status'], $v['assignee_name'] ?? 'Unassigned', $v['discovered_at'], $v['resolved_at'] ?? '',
            ];
        }
        return [['Title', 'Component', 'Severity', 'CVSS', 'Status', 'Assigned To', 'Discovered', 'Resolved'], $rows];
    }

    private function analyticsPlatformExport(): array
    {
        $rows = [];
        foreach ($this->analytics->usersByRoleSeries() as $r) {
            $rows[] = ['Users - ' . ($r['label']['en'] ?? ''), $r['value']];
        }
        foreach ($this->analytics->categoryDistribution(10) as $c) {
            $rows[] = ['Projects - ' . ($c['label']['en'] ?? ''), $c['value']];
        }
        foreach ($this->analytics->platformOverview() as $key => $value) {
            if (!is_array($value)) {
                $rows[] = ['Overview - ' . $key, $value];
            }
        }
        return [['Metric', 'Value'], $rows];
    }

    private function universityStudentRoster(int $universityId): array
    {
        $rows = [];
        foreach ($this->students->forUniversityWithStats($universityId) as $s) {
            $rows[] = [
                $s['full_name'],
                $s['student_number'],
                $s['faculty'] ?? '',
                $s['department'] ?? '',
                $s['academic_year'] ?? '',
                (int) $s['projects_count'],
                (int) $s['published_count'],
                $s['account_status'],
            ];
        }
        return [['Student', 'Student No.', 'Faculty', 'Department', 'Year', 'Projects', 'Published', 'Account Status'], $rows];
    }

    private function universityProjectLog(int $universityId): array
    {
        $rows = [];
        foreach ($this->projects->forUniversityWithOwner($universityId) as $p) {
            $rows[] = [
                $p['title_en'] ?: $p['title_ar'],
                $p['owner_name'],
                $p['owner_faculty'] ?? '',
                $p['status'],
                $p['category'] ?: '',
                $p['created_at'],
                $p['published_at'] ?: '',
            ];
        }
        return [['Title', 'Student', 'Faculty', 'Status', 'Category', 'Submitted', 'Published'], $rows];
    }

    private function platformUsageSummary(): array
    {
        $rows = [
            ['Total Users', User::count()],
            ['Suspended Users', User::where('status', 'suspended')->count()],
            ['Total Universities', University::count()],
            ['Verified Universities', University::where('verification_status', 'verified')->count()],
            ['Pending Universities', University::where('verification_status', 'pending')->count()],
            ['Published Projects', $this->projects->countByStatus('published')],
            ['Pending Approvals', $this->projects->countByStatus('submitted')],
            ['Rejected Projects', $this->projects->countByStatus('rejected')],
        ];
        return [['Metric', 'Value'], $rows];
    }

    private function universityOnboardingDigest(): array
    {
        $rows = [];
        foreach ($this->universities->allWithStats() as $u) {
            $rows[] = [
                $u['official_name_en'] ?: $u['official_name_ar'],
                $u['verification_status'],
                $u['city'] ?? '',
                $u['country'] ?? '',
                (int) $u['students_count'],
                (int) $u['projects_count'],
                $u['created_at'],
            ];
        }
        return [['University', 'Status', 'City', 'Country', 'Students', 'Projects', 'Joined'], $rows];
    }

    private function auditLogExport(): array
    {
        $rows = [];
        foreach ($this->auditLogs->recent(5000) as $log) {
            $rows[] = [
                $log['created_at'],
                $log['actor_name'] ?? 'System',
                $log['action'],
                $log['subject_type'] ? $log['subject_type'] . ' #' . $log['subject_id'] : '',
            ];
        }
        return [['Time', 'Actor', 'Action', 'Target'], $rows];
    }

    private function platformGrowthReport(): array
    {
        $rows = [];
        foreach ($this->users->monthlySignups(12) as $point) {
            $rows[] = [$point['month'], $point['total']];
        }
        return [['Month', 'New Users'], $rows];
    }
}
