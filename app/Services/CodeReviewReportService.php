<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Repositories\AiCodeReviewIssueRepository;
use App\Repositories\AiCodeReviewRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Support\Facades\DB;

/**
 * تقرير مراجعة الكود الكامل لمشروع — مصدر واحد بيخدم:
 *  - شاشة التقرير عند كل الأطراف (طالب/دكتور/معيد/مشرف/كلية/جامعة/أدمن)،
 *  - والتصدير بكل الصيغ.
 * بيجمع: بيانات المشروع + سلسلة الجهات (جامعة ← كلية ← دكتور/معيد) + الدرجات + الملاحظات + قرار الأدمن + تاريخ النسخ.
 */
class CodeReviewReportService
{
    /** أدوار ربط الدكتور/المعيد بالمشروع عبر الفريق. */
    private const STAFF_ROLES = ['supervisor', 'professor', 'principal_investigator', 'teaching_assistant'];

    public function __construct(
        private AiCodeReviewRepository $reviews,
        private AiCodeReviewIssueRepository $issues,
        private ProjectRepository $projects,
        private ResearchProjectService $research,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private AcademicStaffProjectService $staffProjects,
        private ProjectLinkRepository $links
    ) {
    }

    /**
     * المشروع بس لو الدور ده من حقه يشوفه (نفس شروط صفحات كل دور).
     * null = مش موجود أو خارج النطاق (الكنترولر بيرجع 404 عشان مانسربش وجوده).
     */
    public function resolveProject(string $role, int $userId, string $uuid): ?Project
    {
        switch ($role) {
            case 'admin': // صفحات الأدمن ممكن تبعت uuid أو الرقم الداخلي
                return ctype_digit($uuid) ? Project::find((int) $uuid) : $this->projects->findByUuid($uuid);
            case 'student':
                return $this->research->findAccessible($uuid, $userId);
            case 'university':
                $u = $this->universities->findByUserId($userId);
                return $u ? $this->projects->findForUniversityByUuid($uuid, $u->id) : null;
            case 'faculty':
                $f = $this->faculties->findByUserId($userId);
                return $f ? $this->projects->findForFacultyByUuid($uuid, $f->id) : null;
            case 'academic_staff': // دكتور أو معيد (المعيد ربط استشاري لكنه يشوف التقرير)
                $staff = $this->staffProjects->staffForUser($userId);
                if (!$staff) {
                    return null;
                }
                $name = (string) (User::find($userId)->full_name ?? '');
                [$project] = $this->staffProjects->scopedProject($staff, $name, $uuid);
                return $project;
            case 'supervisor':
                $sup = $this->supervisors->findActiveByUserId($userId);
                $project = $sup ? $this->projects->findByUuid($uuid) : null;
                return ($project && $this->assignments->projectInScope($sup->id, $sup->university_id, (int) $project->id)) ? $project : null;
        }
        return null;
    }

    /** @return array<string,mixed>|null null لو مفيش مراجعة اتعملت للمشروع. */
    public function build(Project $project, bool $ar): ?array
    {
        $chain = $this->reviews->allForProject((int) $project->id); // الأحدث أولًا
        if (!$chain) {
            return null;
        }
        $latest = $chain[0];
        $result = $latest->decodedResult();
        $issueRows = $this->issues->forReview((int) $latest->id);

        $issues = array_map(fn ($i) => [
            'severity'       => strtolower((string) $i->severity),
            'category'       => (string) $i->category,
            'file'           => (string) ($i->file_name ?? ''),
            'line'           => $i->line_number !== null ? (int) $i->line_number : null,
            'text'           => (string) $i->description,
            'recommendation' => (string) ($i->ai_recommendation ?? ''),
            'fix'            => (string) ($i->suggested_fix ?? ''),
            'snippet'        => (is_array($result['snippets'] ?? null) && $i->file_name !== null && $i->line_number !== null) ? ($result['snippets'][$i->file_name . '|' . (int) $i->line_number] ?? null) : null,
        ], $issueRows);

        if (!$issues) { // وضع heuristic: الملاحظات في raw_result مش في جدول الـ issues
            foreach (($result['heuristic_issues'] ?? []) as $h) {
                $issues[] = [
                    'severity' => strtolower((string) ($h['severity'] ?? 'info')),
                    'category' => '', 'file' => '', 'line' => null, 'recommendation' => '', 'fix' => '',
                    'text'     => (string) (($ar ? ($h['message_ar'] ?? null) : ($h['message_en'] ?? null)) ?? ($h['message_en'] ?? $h['message_ar'] ?? '')),
                ];
            }
        }

        $severity = array_fill_keys(['critical', 'high', 'medium', 'low', 'info'], 0);
        foreach ($issues as $i) {
            $severity[$i['severity']] = ($severity[$i['severity']] ?? 0) + 1;
        }

        return [
            'project'    => $this->projectInfo($project, $ar),
            'review'     => [
                'id'              => (int) $latest->id,
                'version'         => (int) ($latest->version ?? 1),
                'status'          => (string) $latest->status,
                'method'          => (string) ($result['method'] ?? 'heuristic'),
                'summary'         => (string) $latest->summary,
                'created_at'      => (string) $latest->created_at,
                'scores'          => $latest->scores(),
                'issues_found'    => (int) $latest->issues_found,
                'language_mix'    => is_array($result['language_mix'] ?? null) ? $result['language_mix'] : [],
                'files_analyzed'  => is_array($result['files_analyzed'] ?? null) ? $result['files_analyzed'] : [],
                'strengths'       => is_array($result['strengths'] ?? null) ? $result['strengths'] : [],
                'structure'       => is_array($result['structure'] ?? null) ? $result['structure'] : null,
                'admin_notes'     => $latest->admin_notes,
                'approved_at'     => $latest->approved_at,
                'score_overridden' => (bool) $latest->score_overridden,
                'override_reason' => $latest->override_reason,
            ],
            'severity'   => $severity,
            'issues'     => $issues,
            'history'    => array_map(fn ($r) => [
                'version' => (int) ($r->version ?? 1), 'status' => (string) $r->status,
                'overall' => $r->overall_score !== null ? (int) $r->overall_score : null,
                'issues'  => (int) $r->issues_found, 'created_at' => (string) $r->created_at,
            ], $chain),
        ];
    }

    /** بيانات المشروع + سلسلة الجهات اللي بيوصلها التقرير. */
    public function projectInfo(Project $project, bool $ar): array
    {
        $owner = User::find($project->owner_id);
        $student = DB::table('students')->where('user_id', $project->owner_id)->first();
        $uni = $project->university_id ? $this->universities->find($project->university_id) : null;
        $faculty = ($student && $student->faculty_id) ? DB::table('faculties')->where('id', $student->faculty_id)->first() : null;
        $pick = fn ($a, $b) => $ar ? ($a ?: $b) : ($b ?: $a);

        $team = DB::table('project_team_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.project_id', $project->id)->where('m.status', 'accepted')->whereIn('m.role', self::STAFF_ROLES)
            ->get(['m.role', 'm.member_name', 'u.full_name']);
        $doctors = [];
        $assistants = [];
        foreach ($team as $m) {
            $n = trim((string) ($m->full_name ?: $m->member_name));
            if ($n === '') {
                continue;
            }
            if ($m->role === 'teaching_assistant') {
                $assistants[] = $n;
            } else {
                $doctors[] = $n;
            }
        }
        if (!empty($project->supervisor_name) && !in_array($project->supervisor_name, $doctors, true)) {
            array_unshift($doctors, (string) $project->supervisor_name);
        }

        $repo = $this->links->primaryByType($project->id, 'github');

        return [
            'uuid'       => (string) $project->uuid,
            'title'      => (string) $pick($project->title_ar, $project->title_en),
            'status'     => (string) $project->status,
            'owner'      => (string) ($owner->full_name ?? ''),
            'student_number' => (string) ($student->student_number ?? ''),
            'university' => $uni ? (string) $pick($uni->official_name_ar, $uni->official_name_en) : '',
            'faculty'    => $faculty ? (string) $pick($faculty->name_ar, $faculty->name_en) : (string) ($student->faculty ?? ''),
            'department' => (string) ($student->department ?? ''),
            'doctors'    => array_values(array_unique($doctors)),
            'assistants' => array_values(array_unique($assistants)),
            'repository' => (string) ($repo->url ?? $project->repository_url ?? ''),
        ];
    }

    /**
     * يحوّل التقرير لشكل الـ export المشترك (meta + sections + chart) بلغة الطلب.
     * @return array{0:string,1:array<string,string>,2:array<int,array<string,mixed>>,3:array<string,mixed>}
     */
    public function toExportParts(array $report, bool $ar): array
    {
        $p = $report['project'];
        $r = $report['review'];
        $sev = fn (string $s) => \App\Services\Export\ExportLocalizer::text(ucfirst($s));
        $t = fn (string $s) => \App\Services\Export\ExportLocalizer::text($s);
        $dash = fn ($v) => ($v === null || $v === '' || $v === []) ? '-' : (is_array($v) ? implode('، ', $v) : (string) $v);

        $title = 'Code Review Report - ' . $p['title'];
        $meta = [
            'Project'            => $p['title'],
            'Status'             => $p['status'],
            'Student'            => $dash($p['owner']),
            'Student Number'     => $dash($p['student_number']),
            'University'         => $dash($p['university']),
            'Faculty'            => $dash($p['faculty']),
            'Department'         => $dash($p['department']),
            'Doctor / Supervisor' => $dash($p['doctors']),
            'Teaching Assistant' => $dash($p['assistants']),
            'Repository'         => $dash($p['repository']),
            'Analysis Method'    => $r['method'] === 'ai' ? ($ar ? 'ذكاء اصطناعي' : 'AI') : ($ar ? 'فحص سريع (Heuristic)' : 'Heuristic'),
            'Review Version'     => 'v' . $r['version'],
            'Review Date'        => $r['created_at'],
            'Generated At'       => date('Y-m-d H:i:s'),
        ];

        $s = $r['scores'];
        $sections = [];
        if ($r['summary'] !== '') {
            $sections[] = ['title' => 'Summary', 'header' => ['Summary'], 'rows' => [[$r['summary']]]];
        }
        $sections[] = ['title' => 'Scores', 'header' => ['Metric', 'Score'], 'rows' => [
            [$t('Overall'), $s['overall'] ?? '-'], [$t('Security'), $s['security'] ?? '-'], [$t('Performance'), $s['performance'] ?? '-'],
            [$t('Maintainability'), $s['maintainability'] ?? '-'], [$t('Architecture'), $s['architecture'] ?? '-'], [$t('Quality'), $s['quality'] ?? '-'],
        ]];
        $sections[] = ['title' => 'Issues by Severity', 'header' => ['Severity', 'Count'],
            'rows' => array_map(fn ($k, $v) => [$sev($k), $v], array_keys($report['severity']), array_values($report['severity']))];
        $sections[] = ['title' => 'Findings', 'header' => ['Severity', 'Category', 'File', 'Line', 'Description', 'Recommendation', 'Suggested Fix'],
            'rows' => array_map(fn ($i) => [
                $sev($i['severity']), $i['category'] ?: '-', $i['file'] ?: '-', $i['line'] ?? '-', $i['text'], $i['recommendation'] ?: '-', $i['fix'] ?: '-',
            ], $report['issues'])];
        if (!empty($r['strengths'])) {
            $sections[] = ['title' => 'Strengths (Done Well)', 'header' => ['File', 'Strength'],
                'rows' => array_map(fn ($x) => [$x['file'] ?: '-', $x['text']], $r['strengths'])];
        }
        if (!empty($r['structure']['layers'])) {
            if (!empty($r['structure']['summary'])) {
                $sections[] = ['title' => 'Code Structure Overview', 'header' => ['Overview'], 'rows' => [[$r['structure']['summary']]]];
            }
            $sections[] = ['title' => 'Code Structure Breakdown', 'header' => ['Layer / Module', 'Assessment', 'Purpose', 'Files', 'Note'],
                'rows' => array_map(fn ($ly) => [$ly['name'], $t(ucfirst($ly['assessment'])), $ly['purpose'] ?: '-', implode(', ', $ly['files']) ?: '-', $ly['note'] ?: '-'], $r['structure']['layers'])];
        }
        $evidence = [];
        foreach ($report['issues'] as $i) {
            if (!empty($i['snippet']['code'])) {
                $evidence[] = [$i['file'] . ':' . $i['line'], $i['snippet']['code'], $i['fix'] ?: '-'];
            }
        }
        if ($evidence) {
            $sections[] = ['title' => 'Code Evidence (Problem Code vs Suggested Fix)', 'header' => ['Location', 'Code in Repository', 'Suggested Fix'], 'rows' => $evidence];
        }
        if ($r['language_mix']) {
            $sections[] = ['title' => 'Language Mix', 'header' => ['Language', 'Share %'],
                'rows' => array_map(fn ($k, $v) => [$k, $v], array_keys($r['language_mix']), array_values($r['language_mix']))];
        }
        if ($r['files_analyzed']) {
            $sections[] = ['title' => 'Files Analyzed', 'header' => ['File'], 'rows' => array_map(fn ($f) => [$f], $r['files_analyzed'])];
        }
        if ($r['admin_notes'] || $r['override_reason'] || $r['approved_at']) {
            $sections[] = ['title' => 'Administrator Decision', 'header' => ['Field', 'Value'], 'rows' => [
                [$t('Approved At'), $r['approved_at'] ?? $t('Not yet approved')],
                [$t('Score Overridden'), $r['score_overridden'] ? $t('Yes') : $t('No')],
                [$t('Override Reason'), $r['override_reason'] ?: '-'],
                [$t('Admin Notes'), $r['admin_notes'] ?: '-'],
            ]];
        }
        // Only finished reviews are worth comparing: failed attempts (every re-run that errored creates a version) would
        // just pad the table. Latest 10 completed versions; skipped entirely when there is nothing to compare against.
        $done = array_slice(array_values(array_filter($report['history'], fn ($h) => $h['status'] === 'completed' && $h['overall'] !== null)), 0, 10);
        if (count($done) > 1) {
            $sections[] = ['title' => 'Historical Comparison (Completed Versions)', 'header' => ['Version', 'Status', 'Overall Score', 'Issues', 'Date'],
                'rows' => array_map(fn ($h) => ['v' . $h['version'], $h['status'], $h['overall'], $h['issues'], $h['created_at']], $done)];
        }

        $chart = [
            'labels' => ['Overall', 'Security', 'Performance', 'Maintain.', 'Arch.', 'Quality'],
            'values' => [$s['overall'], $s['security'], $s['performance'], $s['maintainability'], $s['architecture'], $s['quality']],
        ];

        return [$title, $meta, $sections, $chart];
    }
}
