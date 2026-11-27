<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\SecurityExamMonitoringService;
use Illuminate\Http\Request;

/**
 * بند 8 من نواقص بورتال الأمان — "أمان الامتحانات": شاشة تجمع أحداث الأمان المسجلة لكل محاولة امتحان
 * (تبديل نافذة، نسخ/لصق، DevTools، استحواذ على جلسة، إشارات الكاميرا/الهوية) وتبيّن الحالات المشبوهة عبر الامتحانات.
 *
 * قراءة بس (GET). RBAC: الـ route عليه uip.can:security.exams.view، وهنا نفس فحص الدور بتاع باقي كنترولرز الأمان.
 * بيانات الطلاب حساسة: فتح تفاصيل محاولة والتصدير بيتسجلوا في audit log.
 */
class SecurityExamMonitoringApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 25;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private SecurityExamMonitoringService $monitor,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/security/exam-monitoring/overview */
    public function overview(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view exam security monitoring.', null, 403);
        }

        $f = $this->monitor->filters($request->all());

        return $this->apiSuccess(
            $this->monitor->overview($f),
            'Exam security overview retrieved successfully.',
            200,
            $this->meta($f) + $this->monitor->filterOptions($f)
        );
    }

    /** GET /api/v1/security/exam-monitoring/attempts */
    public function attempts(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view exam security monitoring.', null, 403);
        }

        $f = $this->monitor->filters($request->all());
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));
        $result = $this->monitor->attempts($f, $page, $perPage);

        return $this->apiSuccess($result['rows'], 'Suspicious attempts retrieved successfully.', 200,
            $this->meta($f) + ['page' => $page, 'perPage' => $perPage, 'total' => $result['total']]);
    }

    /** GET /api/v1/security/exam-monitoring/attempts/{id} */
    public function attemptShow(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view exam security monitoring.', null, 403);
        }

        $detail = $this->monitor->attemptDetail((int) $id);
        if (!$detail) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        $this->auditLog->record(
            (int) $request->attributes->get('uip_user_id'),
            'security.exam_monitoring.view_attempt',
            'exam_attempt',
            (int) $id,
            null,
            null,
            $request->ip()
        );

        return $this->apiSuccess($detail, 'Attempt security timeline retrieved successfully.');
    }

    /** GET /api/v1/security/exam-monitoring/students — طلاب لهم أحداث مشبوهة (min_exams=2 => المتكرر عبر امتحانات). */
    public function students(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view exam security monitoring.', null, 403);
        }

        $f = $this->monitor->filters($request->all());
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));
        $minExams = max(1, min(20, (int) $request->input('min_exams', 1)));
        $result = $this->monitor->students($f, $page, $perPage, $minExams);

        return $this->apiSuccess($result['rows'], 'Students retrieved successfully.', 200,
            $this->meta($f) + ['page' => $page, 'perPage' => $perPage, 'total' => $result['total'], 'min_exams' => $minExams]);
    }

    /** GET /api/v1/security/exam-monitoring/attempts/export — كل المحاولات المطابقة للفلاتر كـ CSV (حد أقصى 5000). */
    public function export(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can export exam security data.', null, 403);
        }

        $f = $this->monitor->filters($request->all());
        $rows = $this->monitor->attemptsAll($f);

        $this->auditLog->record(
            (int) $request->attributes->get('uip_user_id'),
            'security.exam_monitoring.export',
            'exam_security_events',
            null,
            null,
            ['rows' => count($rows), 'from' => $f['from']->toDateString(), 'to' => $f['to']->toDateString()],
            $request->ip()
        );

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Exam', 'Student', 'Student Number', 'Email', 'Attempt', 'Status', 'Risk', 'Risk Score',
            'Events', 'Violations', 'Max Violations', 'Auto-submitted (violations)', 'Identity', 'Proctoring Flags',
            'Similarity Flags', 'IP', 'Signals', 'First Event', 'Last Event',
        ]);
        foreach ($rows as $r) {
            $signals = [];
            foreach ($r['signals'] as $type => $count) {
                $signals[] = $type . ' x' . $count;
            }
            fputcsv($stream, [
                $this->csvSafe($r['exam_title']), $this->csvSafe($r['full_name']), $this->csvSafe($r['student_number']), $r['email'],
                $r['attempt_number'], $r['status'], $r['risk'], $r['score'],
                $r['events'], $r['violations'], $r['max_violations'] ?? '', $r['auto_submitted_by_violations'] ? 'yes' : 'no',
                $r['identity_status'], $r['proctoring_flags'], $r['similarity_flags'], $r['session_ip'],
                implode('; ', $signals), $r['first_event_at'], $r['last_event_at'],
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="exam-security-' . date('Y-m-d_His') . '.csv"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    private function meta(array $f): array
    {
        return [
            'range' => [
                'from' => $f['from']->toDateString(),
                'to'   => $f['to']->toDateString(),
                'days' => $f['days'],
            ],
            'filters' => [
                'exam_id'       => $f['exam_id'],
                'university_id' => $f['university_id'],
                'student_id'    => $f['student_id'],
                'event_type'    => $f['event_type'],
                'risk'          => $f['risk'],
                'q'             => $f['q'],
            ],
        ];
    }

    /** منع CSV/Excel formula injection: اسم/عنوان بيبدأ بـ = + - @ بيتحوّل لنص عادي. */
    private function csvSafe($value)
    {
        $v = (string) ($value ?? '');
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
