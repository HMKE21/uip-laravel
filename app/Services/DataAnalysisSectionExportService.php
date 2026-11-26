<?php

namespace App\Services;

/**
 * بند 2 (الناقص للمحلل) — تصديرات أقسام المشاريع والامتحانات العميقة
 * والتفاعل/النشاط. مفيش استعلامات جديدة هنا: كل نوع بياخد نفس مخرجات
 * section() اللي صفحات Project Insights / Exam Deep / Engagement بتعرضها
 * (مصدر وحيد للحقيقة) وبيفرّدها لجدول طويل (Group / Item / Metric / Value)
 * يصلح لكل الصيغ (CSV / XLSX / PDF / JSON). أي قسم جداوله ناقصة
 * (available=false) بيطلع صف واحد يوضح كده بدل ما التصدير يفشل.
 */
class DataAnalysisSectionExportService
{
    /** نوع التصدير => [المصدر، مفتاح القسم، العنوان الإنجليزي]. */
    public const MAP = [
        // المشاريع بعمق (DataAnalysisProjectsDeepService)
        'project_approvals'   => ['projects', 'approvals',   'Project Approvals'],
        'project_grading'     => ['projects', 'grading',     'Project Grading'],
        'project_team'        => ['projects', 'team',        'Project Teams'],
        'project_deadlines'   => ['projects', 'deadlines',   'Project Deadlines'],
        'project_supervisors' => ['projects', 'supervisors', 'Project Supervisors'],
        'project_readiness'   => ['projects', 'readiness',   'Project Readiness'],
        'project_ip'          => ['projects', 'ip',          'Project IP & Ownership'],
        // الامتحانات بعمق (DataAnalysisExamsDeepService)
        'exam_deep_targeting'  => ['exams', 'targeting',  'Exam Targeting'],
        'exam_deep_grading'    => ['exams', 'grading',    'Exam Grading (deep)'],
        'exam_deep_ai'         => ['exams', 'ai',         'Exam AI Grading'],
        'exam_deep_proctoring' => ['exams', 'proctoring', 'Exam Proctoring'],
        'exam_deep_pools'      => ['exams', 'pools',      'Question Pools & Banks'],
        'exam_deep_overrides'  => ['exams', 'overrides',  'Exam Grade Overrides'],
        // التفاعل والنشاط (DataAnalysisEngagementService)
        'engagement_feed'          => ['engagement', 'feed',          'Engagement — Feed'],
        'engagement_announcements' => ['engagement', 'announcements', 'Engagement — Announcements'],
        'engagement_notifications' => ['engagement', 'notifications', 'Engagement — Notifications'],
        'engagement_activity'      => ['engagement', 'activity',      'Engagement — User Activity'],
        'engagement_logins'        => ['engagement', 'logins',        'Engagement — Logins'],
        'engagement_visits'        => ['engagement', 'visits',        'Engagement — Visits'],
        'engagement_meetings'      => ['engagement', 'meetings',      'Engagement — Meetings'],
        'engagement_calls'         => ['engagement', 'calls',         'Engagement — Calls'],
        'engagement_forms'         => ['engagement', 'forms',         'Engagement — Forms'],
        'engagement_ai'            => ['engagement', 'ai',            'Engagement — AI Usage'],
    ];

    /** أنواع بتقبل فلاتر (university_id / from / to، والامتحانات كمان faculty/course/exam_type). */
    public const TYPE_KEYS = [
        'project_approvals', 'project_grading', 'project_team', 'project_deadlines', 'project_supervisors',
        'project_readiness', 'project_ip',
        'exam_deep_targeting', 'exam_deep_grading', 'exam_deep_ai', 'exam_deep_proctoring', 'exam_deep_pools',
        'exam_deep_overrides',
        'engagement_feed', 'engagement_announcements', 'engagement_notifications', 'engagement_activity',
        'engagement_logins', 'engagement_visits', 'engagement_meetings', 'engagement_calls', 'engagement_forms',
        'engagement_ai',
    ];

    public const LABELS = [
        'project_approvals'   => 'Project Approvals',
        'project_grading'     => 'Project Grading',
        'project_team'        => 'Project Teams',
        'project_deadlines'   => 'Project Deadlines',
        'project_supervisors' => 'Project Supervisors',
        'project_readiness'   => 'Project Readiness',
        'project_ip'          => 'Project IP & Ownership',
        'exam_deep_targeting'  => 'Exam Targeting',
        'exam_deep_grading'    => 'Exam Grading (deep)',
        'exam_deep_ai'         => 'Exam AI Grading',
        'exam_deep_proctoring' => 'Exam Proctoring',
        'exam_deep_pools'      => 'Question Pools & Banks',
        'exam_deep_overrides'  => 'Exam Grade Overrides',
        'engagement_feed'          => 'Engagement — Feed',
        'engagement_announcements' => 'Engagement — Announcements',
        'engagement_notifications' => 'Engagement — Notifications',
        'engagement_activity'      => 'Engagement — User Activity',
        'engagement_logins'        => 'Engagement — Logins',
        'engagement_visits'        => 'Engagement — Visits',
        'engagement_meetings'      => 'Engagement — Meetings',
        'engagement_calls'         => 'Engagement — Calls',
        'engagement_forms'         => 'Engagement — Forms',
        'engagement_ai'            => 'Engagement — AI Usage',
    ];

    private const MAX_ROWS = 20000;
    private const MAX_DEPTH = 3;

    public function __construct(
        private DataAnalysisProjectsDeepService $projects,
        private DataAnalysisExamsDeepService $exams,
        private DataAnalysisEngagementService $engagement
    ) {
    }

    public static function handles(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    /** @return array{0:string[],1:array<int,array<int,mixed>>} */
    public function rows(string $type, array $filters = []): array
    {
        $header = ['Group', 'Item', 'Metric', 'Value'];
        if (!isset(self::MAP[$type])) {
            return [$header, []];
        }

        [$source, $key] = self::MAP[$type];
        $section = match ($source) {
            'projects'   => $this->projects->section($key, $filters),
            'exams'      => $this->exams->section($key, $filters),
            'engagement' => $this->engagement->section($key, $filters),
            default      => null,
        };

        if (!is_array($section) || !($section['available'] ?? false)) {
            return [$header, [['Info', '', 'Status', 'Not available (the underlying tables are missing)']]];
        }

        $rows = [];
        if (!empty($section['kpis']) && is_array($section['kpis'])) {
            foreach ($section['kpis'] as $metric => $value) {
                if (is_scalar($value) || $value === null) {
                    $rows[] = ['KPIs', '', (string) $metric, $this->cell($value)];
                }
            }
        }
        foreach ($section as $name => $node) {
            if (in_array($name, ['available', 'kpis'], true) || !is_array($node)) {
                continue;
            }
            $this->walk((string) $name, $node, $rows, 1);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return [$header, array_slice($rows, 0, self::MAX_ROWS)];
    }

    /** بيفرّد أي عقدة (قائمة صفوف أو map) لصفوف [group,item,metric,value]. */
    private function walk(string $group, array $node, array &$rows, int $depth): void
    {
        if ($node === [] || count($rows) >= self::MAX_ROWS) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    return;
                }
                if (!is_array($row)) {
                    $rows[] = [$group, $this->cell($row), '', ''];
                    continue;
                }
                $this->listRow($group, $row, $rows);
            }
            return;
        }

        foreach ($node as $k => $v) {
            if (is_array($v)) {
                if ($this->isLabel($v)) {
                    $rows[] = [$group, '', (string) $k, $this->cell($this->label($v))];
                } elseif ($depth < self::MAX_DEPTH) {
                    $this->walk($group . '.' . $k, $v, $rows, $depth + 1);
                }
                continue;
            }
            $rows[] = [$group, '', (string) $k, $this->cell($v)];
        }
    }

    /** صف واحد من قائمة: الحقول النصية = Item، الحقول الرقمية = Metric/Value (صف لكل رقم). */
    private function listRow(string $group, array $row, array &$rows): void
    {
        $item = [];
        $metrics = [];
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                if ($this->isLabel($v)) {
                    $item[] = $this->label($v);
                }
                continue;
            }
            if (is_bool($v)) {
                $metrics[(string) $k] = $v ? 1 : 0;
            } elseif (is_int($v) || is_float($v)) {
                $metrics[(string) $k] = $v;
            } elseif ($v !== null && $v !== '') {
                $item[] = (string) $v;
            }
        }
        $itemText = $this->cell(implode(' / ', $item));

        if (!$metrics) {
            $rows[] = [$group, $itemText, '', ''];
            return;
        }
        foreach ($metrics as $metric => $value) {
            $rows[] = [$group, $itemText, $metric, $value];
        }
    }

    private function isLabel(array $v): bool
    {
        return !array_is_list($v) && (isset($v['en']) || isset($v['ar'])) && count($v) <= 2;
    }

    private function label(array $v): string
    {
        return (string) ($v['en'] ?? $v['ar'] ?? '');
    }

    /** نفس حماية الـ CSV injection في DataAnalysisExamsService::safeCell. */
    private function cell($v)
    {
        if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }
        return $v;
    }
}
