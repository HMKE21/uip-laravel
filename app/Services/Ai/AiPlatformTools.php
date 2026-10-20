<?php

namespace App\Services\Ai;

use App\Repositories\ExamTargetRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "عيون" المساعد الذكي على المنصة: مجموعة أدوات (function calling) للقراءة
 * فقط، بينادي عليها الموديل لما اليوزر يسأل عن بياناته (مشاريعي، طلابي،
 * امتحاناتي، إشعاراتي، إحصائيات الجامعة…).
 *
 * قواعد الأمان (مهمة — متكسرهاش):
 *  1) هوية اليوزر ودوره بييجوا من توكن uip.auth بس (الكنترولر بيمررهم)، أبدًا
 *     من arguments اللي الموديل بيبعتها. الموديل بيتحكم بس في فلاتر (status,
 *     query, limit, id) — وكل id بيتفحص تاني جوه نطاق اليوزر قبل ما يتقرا.
 *  2) كل أداة ليها قايمة أدوار. execute() بترفض أي أداة مش مسموحة لدور
 *     اليوزر حتى لو الموديل اخترع اسمها.
 *  3) قراءة فقط. مفيش INSERT/UPDATE/DELETE هنا، ومفيش أي أداة بتنفّذ إجراء.
 *  4) مفيش باسوردات/توكنز/تليفونات. الإيميل متاح لدور admin بس (في search_users).
 *  5) كل نتيجة بتتقص (عدد صفوف + طول نصوص) عشان تفضل جوه حجم سياق الموديل
 *     ومايتسحبش داتا كتير بالغلط.
 *  6) أي استثناء بيتحوّل لرد عام "tool_failed" — رسالة الاستثناء (ممكن فيها
 *     SQL) مابتوصلش للموديل ولا لليوزر، بتتسجل في اللوج بس.
 */
class AiPlatformTools
{
    private const LIMIT_DEFAULT = 15;
    private const LIMIT_MAX = 30;
    private const STALE_DAYS = 14;
    private const MAX_RESULT_CHARS = 14000;

    private const PROJECT_STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'published', 'archived'];
    private const SUPERVISION_ROLES = ['supervisor', 'professor', 'principal_investigator', 'teaching_assistant'];
    private const GRADING_STATUSES = ['submitted', 'auto_submitted', 'grading'];

    private const UNIVERSITY_ROLES = ['student', 'academic_staff', 'supervisor', 'faculty', 'university'];
    private const SECURITY_ROLES = ['security_admin', 'security_officer', 'admin'];

    /** @var array<string,array> */
    private array $actors = [];

    /** @var array<string,bool> */
    private static array $tableCache = [];

    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private ExamTargetRepository $examTargets
    ) {
    }

    // =====================================================================
    // Catalog / public API
    // =====================================================================

    /** @return array<string,array{roles:string[],description:string,params:array,handler:string,label_en:string,label_ar:string}> */
    private function catalog(): array
    {
        $limit = ['type' => 'integer', 'description' => 'Max rows to return (default 15, max 30).'];

        return [
            'my_notifications' => [
                'roles' => ['*'], 'handler' => 'toolNotifications',
                'label_en' => 'Checking your notifications', 'label_ar' => 'جاري الاطلاع على إشعاراتك',
                'description' => 'The signed-in user\'s notifications: unread count plus the latest items (unread first). Use for "what\'s new", "any alerts", "what did I miss".',
                'params' => [
                    'unread_only' => ['type' => 'boolean', 'description' => 'Only unread notifications.'],
                    'limit' => $limit,
                ],
            ],
            'my_meetings' => [
                'roles' => ['*'], 'handler' => 'toolMeetings',
                'label_en' => 'Checking your meetings', 'label_ar' => 'جاري الاطلاع على اجتماعاتك',
                'description' => 'The user\'s upcoming and live video meetings (as host or invited participant).',
                'params' => ['limit' => $limit],
            ],
            'my_announcements' => [
                'roles' => self::UNIVERSITY_ROLES, 'handler' => 'toolAnnouncements',
                'label_en' => 'Checking announcements', 'label_ar' => 'جاري الاطلاع على الإعلانات',
                'description' => 'Active university announcements visible to the user (deadlines, events, competitions, workshops). Students only get ones targeted at them.',
                'params' => ['limit' => $limit],
            ],
            'list_projects' => [
                'roles' => ['student', 'academic_staff', 'supervisor', 'faculty', 'university', 'admin'], 'handler' => 'toolListProjects',
                'label_en' => 'Reviewing projects', 'label_ar' => 'جاري مراجعة المشاريع',
                'description' => 'Projects inside the user\'s own scope: a student → their own/team projects; academic_staff (doctor) → projects they supervise (+ pending supervision invitations); supervisor → assigned scope; faculty → their faculty; university → their university; admin → platform-wide. Returns a status summary for the WHOLE scope plus rows with attention flags (awaiting_review, changes_requested, stale_draft, low_readiness, deadline_soon, rejected). Use for "which projects need my attention", "my projects", "projects awaiting review".',
                'params' => [
                    'status' => ['type' => 'string', 'enum' => self::PROJECT_STATUSES, 'description' => 'Filter by project status.'],
                    'query' => ['type' => 'string', 'description' => 'Search in project title or student name.'],
                    'needs_attention_only' => ['type' => 'boolean', 'description' => 'Return only projects that have at least one attention flag.'],
                    'limit' => $limit,
                ],
            ],
            'get_project_details' => [
                'roles' => ['student', 'academic_staff', 'supervisor', 'faculty', 'university', 'admin'], 'handler' => 'toolProjectDetails',
                'label_en' => 'Opening project details', 'label_ar' => 'جاري فتح تفاصيل المشروع',
                'description' => 'Full details of ONE project (id from list_projects): summary, team, review history and comments, AI readiness breakdown, improvement suggestions, grade, files and links. Only works for projects inside the user\'s scope.',
                'params' => ['project_id' => ['type' => 'integer', 'description' => 'Project id from list_projects.']],
                'required' => ['project_id'],
            ],
            'list_students' => [
                'roles' => ['academic_staff', 'supervisor', 'faculty', 'university', 'admin'], 'handler' => 'toolListStudents',
                'label_en' => 'Reviewing students', 'label_ar' => 'جاري مراجعة الطلاب',
                'description' => 'Students inside the user\'s scope: academic_staff → students on projects they supervise; supervisor → assigned scope; faculty → their faculty; university → their university; admin → all. Includes academic year, GPA, project counts and latest project status. Use for "summarize the students I supervise", "which students have no project".',
                'params' => [
                    'query' => ['type' => 'string', 'description' => 'Search by student name or student number.'],
                    'without_project_only' => ['type' => 'boolean', 'description' => 'Only students who have no project yet.'],
                    'limit' => $limit,
                ],
            ],
            'my_exams' => [
                'roles' => ['student'], 'handler' => 'toolStudentExams',
                'label_en' => 'Checking your exams', 'label_ar' => 'جاري الاطلاع على امتحاناتك',
                'description' => 'Exams targeted at the student: schedule/window, duration, attempts used, and the result ONLY when the exam\'s result-visibility rules already allow the student to see it.',
                'params' => ['limit' => $limit],
            ],
            'my_courses' => [
                'roles' => ['student', 'academic_staff'], 'handler' => 'toolCourses',
                'label_en' => 'Checking courses', 'label_ar' => 'جاري الاطلاع على المقررات',
                'description' => 'Courses the student is enrolled in, or the courses the academic staff member teaches (with enrollment counts).',
                'params' => ['limit' => $limit],
            ],
            'my_graduation_status' => [
                'roles' => ['student'], 'handler' => 'toolStudentGraduation',
                'label_en' => 'Checking graduation status', 'label_ar' => 'جاري الاطلاع على حالة التخرج',
                'description' => 'The student\'s graduation picture: academic year, GPA, expected graduation date, graduation record/certificate, project progress, and their supervisors.',
                'params' => [],
            ],
            'exams_overview' => [
                'roles' => ['academic_staff', 'faculty', 'university'], 'handler' => 'toolExamsOverview',
                'label_en' => 'Reviewing exams', 'label_ar' => 'جاري مراجعة الامتحانات',
                'description' => 'Exams in the user\'s scope (academic_staff → exams they created) with status, window, attempts, attempts awaiting grading, pass rate, average, violations and pending grade appeals. Use for "my upcoming exams", "pending grading", "how did students do".',
                'params' => [
                    'status' => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'published', 'active', 'closed', 'grading', 'graded', 'archived'], 'description' => 'Filter by exam status.'],
                    'limit' => $limit,
                ],
            ],
            'get_exam_details' => [
                'roles' => ['academic_staff', 'faculty', 'university'], 'handler' => 'toolExamDetails',
                'label_en' => 'Opening exam details', 'label_ar' => 'جاري فتح تفاصيل الامتحان',
                'description' => 'Deep dive on ONE exam (id from exams_overview): question count, score distribution, attempts awaiting grading, high-violation attempts, and pending grade appeals.',
                'params' => ['exam_id' => ['type' => 'integer', 'description' => 'Exam id from exams_overview.']],
                'required' => ['exam_id'],
            ],
            'university_overview' => [
                'roles' => ['university', 'faculty'], 'handler' => 'toolUniversityOverview',
                'label_en' => 'Reviewing institution numbers', 'label_ar' => 'جاري مراجعة أرقام المؤسسة',
                'description' => 'Institution/faculty dashboard numbers: students, staff, projects by status, per-faculty or per-department breakdown, pending join requests, graduates, verification state, plus a list of what needs attention.',
                'params' => [],
            ],
            'pending_join_requests' => [
                'roles' => ['university', 'faculty'], 'handler' => 'toolJoinRequests',
                'label_en' => 'Checking join requests', 'label_ar' => 'جاري مراجعة طلبات الانضمام',
                'description' => 'Students waiting for approval to join the university/faculty, oldest first, with days waiting.',
                'params' => ['limit' => $limit],
            ],
            'graduation_overview' => [
                'roles' => ['university', 'faculty'], 'handler' => 'toolGraduationOverview',
                'label_en' => 'Checking graduation records', 'label_ar' => 'جاري مراجعة سجلات التخرج',
                'description' => 'Graduated/revoked counts, the latest graduation records, and students expected to graduate in the next 6 months who have no record yet.',
                'params' => ['limit' => $limit],
            ],
            'platform_overview' => [
                'roles' => ['admin'], 'handler' => 'toolPlatformOverview',
                'label_en' => 'Reviewing platform health', 'label_ar' => 'جاري مراجعة حالة المنصة',
                'description' => 'Platform-wide numbers: users by role/status, universities by verification status, projects by status, exams, open security items, AI assistant usage (7 days), signups, and what needs admin attention.',
                'params' => [],
            ],
            'search_users' => [
                'roles' => ['admin'], 'handler' => 'toolSearchUsers',
                'label_en' => 'Searching users', 'label_ar' => 'جاري البحث في المستخدمين',
                'description' => 'Admin only: find accounts by name/email with optional role and status filters. Returns status, roles, last login, 2FA and lock state (never passwords or tokens).',
                'params' => [
                    'query' => ['type' => 'string', 'description' => 'Name or email fragment.'],
                    'role' => ['type' => 'string', 'description' => 'Role slug, e.g. student, academic_staff, university.'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'pending', 'suspended', 'banned']],
                    'limit' => $limit,
                ],
            ],
            'recent_audit_logs' => [
                'roles' => self::SECURITY_ROLES, 'handler' => 'toolAuditLogs',
                'label_en' => 'Reading audit logs', 'label_ar' => 'جاري قراءة سجلات التدقيق',
                'description' => 'Recent audit-log entries (who did what, when, from which IP). Filter by hours back and an action fragment such as "approved", "login", "settings".',
                'params' => [
                    'hours' => ['type' => 'integer', 'description' => 'Look back this many hours (default 24, max 720).'],
                    'action_contains' => ['type' => 'string'],
                    'limit' => $limit,
                ],
            ],
            'security_overview' => [
                'roles' => self::SECURITY_ROLES, 'handler' => 'toolSecurityOverview',
                'label_en' => 'Reviewing security status', 'label_ar' => 'جاري مراجعة الوضع الأمني',
                'description' => 'Security posture: open incidents and alerts by severity, vulnerabilities by severity and overdue ones, latest incidents, and recent critical security events.',
                'params' => [],
            ],
            'recent_security_events' => [
                'roles' => self::SECURITY_ROLES, 'handler' => 'toolSecurityEvents',
                'label_en' => 'Reading security events', 'label_ar' => 'جاري قراءة الأحداث الأمنية',
                'description' => 'Recent security-log events (logins, lockouts, suspicious activity) filtered by severity and hours back.',
                'params' => [
                    'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
                    'hours' => ['type' => 'integer', 'description' => 'Look back this many hours (default 24, max 720).'],
                    'limit' => $limit,
                ],
            ],
            'analytics_snapshot' => [
                'roles' => ['data_analyst', 'admin'], 'handler' => 'toolAnalytics',
                'label_en' => 'Analyzing platform data', 'label_ar' => 'جاري تحليل بيانات المنصة',
                'description' => 'Aggregate-only analytics (no personal data): projects by status/category/month, users by role, exam performance, and KPI status versus targets.',
                'params' => [],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> تعريفات tools بصيغة OpenAI للدور ده بس. */
    public function definitionsFor(?string $role): array
    {
        if ($role === null || $role === '') {
            return [];
        }

        $defs = [];
        foreach ($this->catalog() as $name => $tool) {
            if (!$this->allowed($tool, $role)) {
                continue;
            }
            $defs[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool['description'],
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool['params'] ?: new \stdClass(),
                        'required' => $tool['required'] ?? [],
                    ],
                ],
            ];
        }

        return $defs;
    }

    /** اسم ودّي للأداة يتعرض لليوزر وهي شغالة ("جاري مراجعة المشاريع"). */
    public function label(string $name, string $locale = 'ar'): string
    {
        $tool = $this->catalog()[$name] ?? null;
        if (!$tool) {
            return $locale === 'ar' ? 'جاري جلب البيانات' : 'Fetching data';
        }
        return $locale === 'ar' ? $tool['label_ar'] : $tool['label_en'];
    }

    /** @return string[] أسماء الأدوات المتاحة للدور (للـ audit/اللوج). */
    public function namesFor(?string $role): array
    {
        return array_map(fn ($d) => $d['function']['name'], $this->definitionsFor($role));
    }

    /**
     * ينفّذ أداة واحدة. دايمًا بيرجّع array قابل للـ json_encode (حتى عند الخطأ).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function execute(string $name, array $args, int $userId, ?string $role): array
    {
        $tool = $this->catalog()[$name] ?? null;
        if (!$tool || $userId <= 0 || !$this->allowed($tool, (string) $role)) {
            return ['error' => 'tool_not_available', 'message' => 'This data is not available for your account.'];
        }

        try {
            $actor = $this->actor($userId, (string) $role);
            $data = $this->{$tool['handler']}($actor, $args);
        } catch (\Throwable $e) {
            Log::warning('AI tool failed', ['tool' => $name, 'role' => $role, 'error' => $e->getMessage()]);
            return ['error' => 'tool_failed', 'message' => 'Could not load this data right now.'];
        }

        $data['as_of'] = now()->format('Y-m-d H:i');

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false && strlen($encoded) > self::MAX_RESULT_CHARS) {
            return $this->shrink($data);
        }

        return $data;
    }

    private function allowed(array $tool, string $role): bool
    {
        return in_array('*', $tool['roles'], true) || in_array($role, $tool['roles'], true);
    }

    /** نتيجة كبيرة بزيادة: بنشيل ذيل أطول array ونعلّم إنها اتقصّت. */
    private function shrink(array $data): array
    {
        for ($i = 0; $i < 12; $i++) {
            $longestKey = null;
            $longest = 1;
            foreach ($data as $k => $v) {
                if (is_array($v) && array_is_list($v) && count($v) > $longest) {
                    $longest = count($v);
                    $longestKey = $k;
                }
            }
            if ($longestKey === null) {
                break;
            }
            $data[$longestKey] = array_slice($data[$longestKey], 0, (int) ceil($longest / 2));
            $data['truncated'] = true;

            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false && strlen($encoded) <= self::MAX_RESULT_CHARS) {
                break;
            }
        }
        return $data;
    }

    // =====================================================================
    // Actor (who is asking) — resolved from the DB by token user id only
    // =====================================================================

    /** @return array<string,mixed> */
    private function actor(int $userId, string $role): array
    {
        $key = $userId . ':' . $role;
        if (isset($this->actors[$key])) {
            return $this->actors[$key];
        }

        $a = [
            'user_id' => $userId, 'role' => $role,
            'name' => (string) DB::table('users')->where('id', $userId)->value('full_name'),
            'university_id' => null, 'faculty_id' => null, 'department_id' => null,
            'student_id' => null, 'staff_id' => null, 'supervisor_id' => null,
            'academic_year' => null, 'group_id' => null,
        ];

        switch ($role) {
            case 'student':
                $s = DB::table('students')->where('user_id', $userId)->first();
                if ($s) {
                    $a['student_id'] = (int) $s->id;
                    $a['university_id'] = $s->university_id ? (int) $s->university_id : null;
                    $a['faculty_id'] = $s->faculty_id ? (int) $s->faculty_id : null;
                    $a['department_id'] = $s->department_id ? (int) $s->department_id : null;
                    $a['academic_year'] = $s->academic_year;
                    $a['group_id'] = $s->group_id ?? null;
                }
                break;
            case 'academic_staff':
                $s = DB::table('academic_staff')->where('user_id', $userId)->first();
                if ($s) {
                    $a['staff_id'] = (int) $s->id;
                    $a['university_id'] = (int) $s->university_id;
                    $a['faculty_id'] = $s->faculty_id ? (int) $s->faculty_id : null;
                    $a['department_id'] = $s->department_id ? (int) $s->department_id : null;
                }
                break;
            case 'supervisor':
                $s = $this->supervisors->findActiveByUserId($userId);
                if ($s) {
                    $a['supervisor_id'] = (int) $s->id;
                    $a['university_id'] = (int) $s->university_id;
                }
                break;
            case 'faculty':
                $f = DB::table('faculties')->where('user_id', $userId)->first();
                if ($f) {
                    $a['faculty_id'] = (int) $f->id;
                    $a['university_id'] = (int) $f->university_id;
                }
                break;
            case 'university':
                $u = DB::table('universities')->where('user_id', $userId)->first();
                if ($u) {
                    $a['university_id'] = (int) $u->id;
                }
                break;
        }

        return $this->actors[$key] = $a;
    }

    // =====================================================================
    // Small helpers
    // =====================================================================

    private function lim(array $args, int $default = self::LIMIT_DEFAULT): int
    {
        $n = isset($args['limit']) ? (int) $args['limit'] : $default;
        return max(1, min(self::LIMIT_MAX, $n));
    }

    private function hours(array $args): int
    {
        return max(1, min(720, (int) ($args['hours'] ?? 24)));
    }

    private function enumArg($value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function clip($text, int $max = 300): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        if ($t === '') {
            return null;
        }
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
    }

    private function bi($en, $ar): ?string
    {
        $en = trim((string) $en);
        $ar = trim((string) $ar);
        if ($en !== '' && $ar !== '' && $en !== $ar) {
            return "{$en} / {$ar}";
        }
        return $en !== '' ? $en : ($ar !== '' ? $ar : null);
    }

    private function dt($value): ?string
    {
        if (!$value) {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts ? date('Y-m-d H:i', $ts) : null;
    }

    private function daysSince($value): ?int
    {
        $ts = $value ? strtotime((string) $value) : false;
        return $ts ? max(0, (int) floor((time() - $ts) / 86400)) : null;
    }

    private function daysUntil($value): ?int
    {
        $ts = $value ? strtotime((string) $value) : false;
        return $ts ? (int) floor(($ts - time()) / 86400) : null;
    }

    private function like(string $s): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    }

    private function has(string $table): bool
    {
        if (!array_key_exists($table, self::$tableCache)) {
            try {
                self::$tableCache[$table] = Schema::hasTable($table);
            } catch (\Throwable $e) {
                self::$tableCache[$table] = false;
            }
        }
        return self::$tableCache[$table];
    }

    /** @return array<string,int> */
    private function countBy(Builder $q, string $column): array
    {
        $out = [];
        foreach ($q->select($column, DB::raw('COUNT(*) AS c'))->groupBy($column)->get() as $r) {
            $out[(string) ($r->{$this->bare($column)} ?? 'unknown')] = (int) $r->c;
        }
        return $out;
    }

    private function bare(string $column): string
    {
        $pos = strrpos($column, '.');
        return $pos === false ? $column : substr($column, $pos + 1);
    }

    private function noAccess(string $what): array
    {
        return ['items' => [], 'note' => "No {$what} found in your scope (your account may not be linked to a university/scope yet)."];
    }

    // =====================================================================
    // Common tools (all roles / all university roles)
    // =====================================================================

    private function toolNotifications(array $a, array $args): array
    {
        $base = fn () => DB::table('notifications')
            ->where('user_id', $a['user_id'])->where('is_deleted', 0)->where('is_archived', 0);

        $q = $base();
        if (!empty($args['unread_only'])) {
            $q->where('is_read', 0);
        }

        $items = $q->orderBy('is_read')->orderByDesc('is_important')->orderByDesc('created_at')
            ->limit($this->lim($args, 10))
            ->get(['title', 'body', 'type', 'category', 'priority', 'is_read', 'is_important', 'link_url', 'created_at'])
            ->map(fn ($n) => array_filter([
                'title' => $this->clip($n->title, 150),
                'body' => $this->clip($n->body, 220),
                'type' => $n->type,
                'priority' => $n->priority !== 'normal' ? $n->priority : null,
                'important' => $n->is_important ? true : null,
                'read' => (bool) $n->is_read,
                'page' => $n->link_url,
                'at' => $this->dt($n->created_at),
            ], fn ($v) => $v !== null))
            ->all();

        return [
            'unread_count' => (int) $base()->where('is_read', 0)->count(),
            'important_unread' => (int) $base()->where('is_read', 0)->where('is_important', 1)->count(),
            'items' => $items,
        ];
    }

    private function toolMeetings(array $a, array $args): array
    {
        if (!$this->has('meetings')) {
            return ['items' => [], 'note' => 'Meetings are not available.'];
        }
        $me = $a['user_id'];

        $rows = DB::table('meetings as m')
            ->leftJoin('meeting_participants as mp', function ($j) use ($me) {
                $j->on('mp.meeting_id', '=', 'm.id')->where('mp.user_id', '=', $me);
            })
            ->join('users as h', 'h.id', '=', 'm.host_user_id')
            ->where(function ($w) use ($me) {
                $w->where('m.host_user_id', $me)
                    ->orWhere(function ($x) {
                        $x->whereNotNull('mp.id')->whereNotIn('mp.status', ['declined', 'removed']);
                    });
            })
            ->whereIn('m.status', ['scheduled', 'lobby', 'live'])
            ->where(function ($w) {
                $w->whereNull('m.scheduled_start_at')->orWhere('m.scheduled_start_at', '>=', now()->subHours(2));
            })
            ->orderByRaw('CASE WHEN m.scheduled_start_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('m.scheduled_start_at')
            ->limit($this->lim($args, 10))
            ->get(['m.id', 'm.title', 'm.status', 'm.type', 'm.scheduled_start_at', 'm.duration_minutes', 'h.full_name as host', 'm.host_user_id', 'mp.status as my_status']);

        return ['items' => $rows->map(fn ($m) => array_filter([
            'id' => (int) $m->id,
            'title' => $this->clip($m->title, 150),
            'status' => $m->status,
            'starts_at' => $this->dt($m->scheduled_start_at),
            'duration_minutes' => $m->duration_minutes ? (int) $m->duration_minutes : null,
            'host' => (int) $m->host_user_id === $me ? 'you' : $m->host,
            'your_invitation' => (int) $m->host_user_id === $me ? null : $m->my_status,
        ], fn ($v) => $v !== null))->all()];
    }

    private function toolAnnouncements(array $a, array $args): array
    {
        if (!$a['university_id']) {
            return $this->noAccess('announcements');
        }

        $q = DB::table('announcements as an')
            ->where('an.university_id', $a['university_id'])
            ->whereNull('an.deleted_at')
            ->whereNotNull('an.published_at')
            ->where(function ($w) {
                $w->whereNull('an.expires_at')->orWhere('an.expires_at', '>', now());
            });

        if ($a['role'] === 'student') {
            $q->where(function ($w) use ($a) {
                $w->whereNull('an.target_faculty_id')->orWhere('an.target_faculty_id', $a['faculty_id']);
            })->where(function ($w) use ($a) {
                $w->whereNull('an.target_department_id')->orWhere('an.target_department_id', $a['department_id']);
            })->where(function ($w) use ($a) {
                $w->whereNull('an.target_academic_year')->orWhere('an.target_academic_year', $a['academic_year']);
            });
        }

        $items = $q->orderByDesc('an.published_at')->limit($this->lim($args, 8))
            ->get(['an.id', 'an.category', 'an.title', 'an.body', 'an.published_at', 'an.expires_at'])
            ->map(fn ($r) => array_filter([
                'title' => $this->clip($r->title, 160),
                'category' => $r->category,
                'summary' => $this->clip($r->body, 280),
                'published_at' => $this->dt($r->published_at),
                'expires_at' => $this->dt($r->expires_at),
                'expires_in_days' => $r->expires_at ? $this->daysUntil($r->expires_at) : null,
            ], fn ($v) => $v !== null))->all();

        return ['items' => $items];
    }

    // =====================================================================
    // Projects
    // =====================================================================

    /** نطاق المشاريع المسموح لليوزر ده. null = مفيش نطاق (يرجّع فاضي). */
    private function projectScope(array $a): ?Builder
    {
        $q = DB::table('projects as p')
            ->join('users as ou', 'ou.id', '=', 'p.owner_id')
            ->leftJoin('students as os', 'os.user_id', '=', 'p.owner_id')
            ->whereNull('p.deleted_at');

        $me = $a['user_id'];

        switch ($a['role']) {
            case 'student':
                $q->where(function ($w) use ($me) {
                    $w->where('p.owner_id', $me)->orWhereIn('p.id', function ($s) use ($me) {
                        $s->select('project_id')->from('project_team_members')
                            ->where('user_id', $me)->where('status', 'accepted');
                    });
                });
                return $q;

            case 'academic_staff':
                if (!$a['staff_id']) {
                    return null;
                }
                $name = $a['name'];
                $uni = $a['university_id'];
                $q->where(function ($w) use ($me, $name, $uni) {
                    $w->whereIn('p.id', function ($s) use ($me) {
                        $s->select('project_id')->from('project_team_members')
                            ->where('user_id', $me)->where('status', 'accepted')
                            ->whereIn('role', self::SUPERVISION_ROLES);
                    });
                    if ($name !== '' && $uni) {
                        $w->orWhere(function ($x) use ($name, $uni) {
                            $x->where('p.supervisor_name', $name)->where('p.university_id', $uni);
                        });
                    }
                });
                return $q;

            case 'supervisor':
                if (!$a['supervisor_id'] || !$a['university_id']) {
                    return null;
                }
                $ids = array_map(
                    fn ($r) => (int) $r->id,
                    $this->assignments->scopedProjects($a['supervisor_id'], $a['university_id'])
                );
                if (!$ids) {
                    return null;
                }
                return $q->whereIn('p.id', array_slice($ids, 0, 2000));

            case 'faculty':
                if (!$a['faculty_id']) {
                    return null;
                }
                $fid = $a['faculty_id'];
                return $q->whereIn('p.owner_id', function ($s) use ($fid) {
                    $s->select('user_id')->from('students')->where('faculty_id', $fid);
                });

            case 'university':
                return $a['university_id'] ? $q->where('p.university_id', $a['university_id']) : null;

            case 'admin':
                return $q;
        }

        return null;
    }

    private function scopeLabel(array $a): string
    {
        return [
            'student' => 'your own and team projects',
            'academic_staff' => 'projects you supervise',
            'supervisor' => 'projects in your assigned scope',
            'faculty' => 'projects of your faculty\'s students',
            'university' => 'projects of your university',
            'admin' => 'all projects on the platform',
        ][$a['role']] ?? 'projects';
    }

    /** @return array<int,mixed> */
    private function projectColumns(): array
    {
        $cols = [
            'p.id', 'p.title_ar', 'p.title_en', 'p.status', 'p.category', 'p.visibility',
            'p.updated_at', 'p.created_at', 'p.timeline_end', 'p.supervisor_name',
            'ou.full_name as owner_name', 'os.student_number', 'os.academic_year',
            DB::raw("(SELECT COUNT(*) FROM project_team_members t WHERE t.project_id = p.id AND t.status = 'accepted') AS team_size"),
        ];
        if ($this->has('ai_readiness_scores')) {
            $cols[] = DB::raw('(SELECT r.overall_score FROM ai_readiness_scores r WHERE r.project_id = p.id ORDER BY r.id DESC LIMIT 1) AS readiness');
        }
        if ($this->has('project_approvals')) {
            $cols[] = DB::raw('(SELECT x.decision FROM project_approvals x WHERE x.project_id = p.id ORDER BY x.id DESC LIMIT 1) AS last_decision');
            $cols[] = DB::raw('(SELECT x.comments FROM project_approvals x WHERE x.project_id = p.id ORDER BY x.id DESC LIMIT 1) AS last_review_comment');
            $cols[] = DB::raw('(SELECT x.decided_at FROM project_approvals x WHERE x.project_id = p.id ORDER BY x.id DESC LIMIT 1) AS last_decided_at');
        }
        return $cols;
    }

    /** @return string[] */
    private function attentionFlags(object $r, string $role): array
    {
        $flags = [];
        $st = (string) $r->status;
        $days = $this->daysSince($r->updated_at);

        if ($role === 'student') {
            if ($st === 'rejected') {
                $flags[] = 'rejected';
            }
        } elseif (in_array($st, ['submitted', 'under_review'], true)) {
            $flags[] = 'awaiting_review';
        }

        if (($r->last_decision ?? null) === 'changes_requested' && in_array($st, ['draft', 'submitted', 'under_review', 'rejected'], true)) {
            $flags[] = 'changes_requested';
        }
        if ($st === 'draft' && $days !== null && $days >= self::STALE_DAYS) {
            $flags[] = 'stale_draft';
        }
        if (isset($r->readiness) && $r->readiness !== null && (float) $r->readiness < 50
            && in_array($st, ['draft', 'submitted', 'under_review'], true)) {
            $flags[] = 'low_readiness';
        }
        $left = $this->daysUntil($r->timeline_end ?? null);
        if ($left !== null && $left <= 14 && !in_array($st, ['approved', 'published', 'archived'], true)) {
            $flags[] = $left < 0 ? 'deadline_passed' : 'deadline_soon';
        }

        return $flags;
    }

    private function shapeProject(object $r, string $role): array
    {
        $row = [
            'id' => (int) $r->id,
            'title' => $this->bi($r->title_en, $r->title_ar) ?: 'Untitled',
            'student' => $r->owner_name,
            'student_number' => $r->student_number,
            'academic_year' => $r->academic_year,
            'status' => $r->status,
            'category' => $r->category,
            'last_updated' => $this->dt($r->updated_at),
            'days_since_update' => $this->daysSince($r->updated_at),
            'deadline' => $r->timeline_end,
            'team_size' => (int) $r->team_size,
            'ai_readiness' => isset($r->readiness) && $r->readiness !== null ? (int) round((float) $r->readiness) : null,
            'last_review' => isset($r->last_decision) && $r->last_decision ? array_filter([
                'decision' => $r->last_decision,
                'comment' => $this->clip($r->last_review_comment ?? null, 200),
                'at' => $this->dt($r->last_decided_at ?? null),
            ], fn ($v) => $v !== null) : null,
            'attention' => $this->attentionFlags($r, $role),
        ];

        return array_filter($row, fn ($v) => $v !== null && $v !== '');
    }

    private function toolListProjects(array $a, array $args): array
    {
        $q = $this->projectScope($a);
        if ($q === null) {
            return $this->noAccess('projects') + ['projects' => []];
        }

        $byStatus = $this->countBy(clone $q, 'p.status');

        $status = $this->enumArg($args['status'] ?? null, self::PROJECT_STATUSES);
        if ($status) {
            $q->where('p.status', $status);
        }
        $search = trim((string) ($args['query'] ?? ''));
        if ($search !== '') {
            $like = $this->like($search);
            $q->where(function ($w) use ($like) {
                $w->where('p.title_en', 'like', $like)->orWhere('p.title_ar', 'like', $like)->orWhere('ou.full_name', 'like', $like);
            });
        }

        $limit = $this->lim($args);
        $onlyAttention = !empty($args['needs_attention_only']);

        $rows = $q->select($this->projectColumns())
            ->orderByRaw("CASE p.status WHEN 'submitted' THEN 0 WHEN 'under_review' THEN 1 WHEN 'draft' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END")
            ->orderByDesc('p.updated_at')
            ->limit($onlyAttention ? 300 : $limit + 1)
            ->get();

        $shaped = [];
        foreach ($rows as $r) {
            $row = $this->shapeProject($r, $a['role']);
            if ($onlyAttention && empty($row['attention'])) {
                continue;
            }
            $shaped[] = $row;
        }

        $hasMore = count($shaped) > $limit;
        $shaped = array_slice($shaped, 0, $limit);

        $total = array_sum($byStatus);
        $out = [
            'scope' => $this->scopeLabel($a),
            'summary' => [
                'total' => $total,
                'by_status' => $byStatus,
                'awaiting_review' => ($byStatus['submitted'] ?? 0) + ($byStatus['under_review'] ?? 0),
            ],
            'projects' => $shaped,
            'returned' => count($shaped),
        ];
        if ($hasMore) {
            $out['note'] = "More projects match; showing the first {$limit}. Narrow with status/query or ask for more.";
        }

        if ($a['role'] === 'academic_staff' && $this->has('project_team_members')) {
            $out['pending_supervision_invitations'] = $this->pendingSupervisionInvitations($a['user_id']);
        }

        return $out;
    }

    private function pendingSupervisionInvitations(int $userId): array
    {
        return DB::table('project_team_members as t')
            ->join('projects as p', 'p.id', '=', 't.project_id')
            ->join('users as ou', 'ou.id', '=', 'p.owner_id')
            ->where('t.user_id', $userId)->where('t.status', 'pending')
            ->whereIn('t.role', self::SUPERVISION_ROLES)
            ->whereNull('p.deleted_at')
            ->orderBy('t.invited_at')->limit(10)
            ->get(['p.id', 'p.title_en', 'p.title_ar', 't.role', 't.invited_at', 'ou.full_name as student'])
            ->map(fn ($r) => [
                'project_id' => (int) $r->id,
                'title' => $this->bi($r->title_en, $r->title_ar),
                'student' => $r->student,
                'invited_as' => $r->role,
                'waiting_days' => $this->daysSince($r->invited_at),
            ])->all();
    }

    private function toolProjectDetails(array $a, array $args): array
    {
        $pid = (int) ($args['project_id'] ?? 0);
        $q = $pid > 0 ? $this->projectScope($a) : null;
        if ($q === null) {
            return ['error' => 'not_found_or_no_access', 'message' => 'Project not found in your scope.'];
        }

        $cols = array_merge($this->projectColumns(), ['p.summary', 'p.description', 'p.technologies', 'p.tags', 'p.sdgs', 'p.timeline_start', 'p.published_at']);
        $r = $q->where('p.id', $pid)->select($cols)->first();
        if (!$r) {
            return ['error' => 'not_found_or_no_access', 'message' => 'Project not found in your scope.'];
        }

        $json = function ($v): ?array {
            $d = is_string($v) ? json_decode($v, true) : (is_array($v) ? $v : null);
            return is_array($d) && $d ? array_slice(array_map('strval', array_values($d)), 0, 12) : null;
        };

        $out = $this->shapeProject($r, $a['role']);
        $out += array_filter([
            'visibility' => $r->visibility,
            'supervisor_name' => $r->supervisor_name,
            'summary' => $this->clip($r->summary, 900),
            'description' => $this->clip($r->description, 1500),
            'technologies' => $json($r->technologies),
            'tags' => $json($r->tags),
            'sdgs' => $json($r->sdgs),
            'timeline_start' => $r->timeline_start,
            'published_at' => $this->dt($r->published_at),
        ], fn ($v) => $v !== null);

        // الفريق
        $out['team'] = DB::table('project_team_members as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where('t.project_id', $pid)->whereIn('t.status', ['accepted', 'pending'])
            ->limit(20)
            ->get(['t.role', 't.status', 't.member_name', 'u.full_name', 't.student_number'])
            ->map(fn ($m) => array_filter([
                'name' => $m->full_name ?: $m->member_name,
                'role' => $m->role,
                'status' => $m->status,
                'student_number' => $m->student_number,
            ], fn ($v) => $v !== null && $v !== ''))->all();

        // تاريخ المراجعات
        if ($this->has('project_approvals')) {
            $out['review_history'] = DB::table('project_approvals as x')
                ->leftJoin('users as ru', 'ru.id', '=', 'x.reviewer_id')
                ->where('x.project_id', $pid)->orderByDesc('x.id')->limit(6)
                ->get(['x.stage', 'x.decision', 'x.comments', 'x.decided_at', 'ru.full_name as reviewer'])
                ->map(fn ($x) => array_filter([
                    'stage' => $x->stage, 'decision' => $x->decision,
                    'comment' => $this->clip($x->comments, 400),
                    'reviewer' => $x->reviewer, 'at' => $this->dt($x->decided_at),
                ], fn ($v) => $v !== null))->all();
        }

        // الجاهزية + الاقتراحات
        if ($this->has('ai_readiness_scores')) {
            $rs = DB::table('ai_readiness_scores')->where('project_id', $pid)->orderByDesc('id')->first();
            if ($rs) {
                $out['ai_readiness_breakdown'] = array_filter([
                    'overall' => (int) round((float) $rs->overall_score),
                    'technical' => $rs->technical_score !== null ? (int) round((float) $rs->technical_score) : null,
                    'market' => $rs->market_score !== null ? (int) round((float) $rs->market_score) : null,
                    'innovation' => $rs->innovation_score !== null ? (int) round((float) $rs->innovation_score) : null,
                    'presentation' => $rs->presentation_score !== null ? (int) round((float) $rs->presentation_score) : null,
                    'computed_at' => $this->dt($rs->computed_at),
                ], fn ($v) => $v !== null);
            }
        }
        if ($this->has('improvement_suggestions')) {
            $out['improvement_suggestions'] = DB::table('improvement_suggestions')
                ->where('project_id', $pid)
                ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
                ->orderByDesc('id')->limit(8)
                ->get(['category', 'priority', 'suggestion'])
                ->map(fn ($s) => ['category' => $s->category, 'priority' => $s->priority, 'suggestion' => $this->clip($s->suggestion, 250)])
                ->all();
        }

        // الدرجة (الطالب مايشوفش إلا النهائية)
        if ($this->has('project_grades')) {
            $g = DB::table('project_grades')->where('project_id', $pid)
                ->when($a['role'] === 'student', fn ($qq) => $qq->where('status', 'final'))
                ->orderByDesc('id')->first();
            if ($g) {
                $out['grade'] = array_filter([
                    'total' => $g->total_score !== null ? (float) $g->total_score : null,
                    'max' => (float) $g->max_score,
                    'letter' => $g->letter_grade,
                    'status' => $g->status,
                    'comments' => $this->clip($g->overall_comments, 300),
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        // ملفات وروابط
        if ($this->has('project_files')) {
            $files = DB::table('project_files')->where('project_id', $pid)->where('is_latest', 1)->limit(40)->get(['file_type', 'original_name']);
            $out['files'] = [
                'count' => $files->count(),
                'by_type' => $files->countBy('file_type')->all(),
                'names' => $files->pluck('original_name')->take(8)->map(fn ($n) => $this->clip($n, 80))->all(),
            ];
        }
        if ($this->has('project_links')) {
            $out['links'] = DB::table('project_links')->where('project_id', $pid)->limit(8)->get(['type', 'label', 'url'])
                ->map(fn ($l) => array_filter(['type' => $l->type, 'label' => $l->label, 'url' => $l->url], fn ($v) => $v !== null && $v !== ''))->all();
        }

        return $out;
    }

    // =====================================================================
    // Students
    // =====================================================================

    private function studentScope(array $a): ?Builder
    {
        $q = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->whereNull('u.deleted_at');

        switch ($a['role']) {
            case 'academic_staff':
                $scope = $this->projectScope($a);
                if ($scope === null) {
                    return null;
                }
                $pids = $scope->limit(1000)->pluck('p.id')->map(fn ($v) => (int) $v)->all();
                if (!$pids) {
                    return null;
                }
                $owners = DB::table('projects')->whereIn('id', $pids)->pluck('owner_id')->all();
                $members = DB::table('project_team_members')->whereIn('project_id', $pids)
                    ->where('status', 'accepted')->whereNotNull('user_id')
                    ->whereNotIn('role', self::SUPERVISION_ROLES)->pluck('user_id')->all();
                $uids = array_values(array_unique(array_map('intval', array_merge($owners, $members))));
                return $uids ? $q->whereIn('s.user_id', $uids) : null;

            case 'supervisor':
                if (!$a['supervisor_id'] || !$a['university_id']) {
                    return null;
                }
                $sids = array_map(fn ($r) => (int) $r->id, $this->assignments->scopedStudents($a['supervisor_id'], $a['university_id']));
                return $sids ? $q->whereIn('s.id', array_slice($sids, 0, 2000)) : null;

            case 'faculty':
                return $a['faculty_id'] ? $q->where('s.faculty_id', $a['faculty_id']) : null;

            case 'university':
                return $a['university_id'] ? $q->where('s.university_id', $a['university_id']) : null;

            case 'admin':
                return $q;
        }
        return null;
    }

    private function toolListStudents(array $a, array $args): array
    {
        $q = $this->studentScope($a);
        if ($q === null) {
            return $this->noAccess('students') + ['students' => []];
        }

        $search = trim((string) ($args['query'] ?? ''));
        if ($search !== '') {
            $like = $this->like($search);
            $q->where(function ($w) use ($like) {
                $w->where('u.full_name', 'like', $like)->orWhere('s.student_number', 'like', $like);
            });
        }

        $projCount = '(SELECT COUNT(*) FROM projects pr WHERE pr.owner_id = s.user_id AND pr.deleted_at IS NULL)';
        if (!empty($args['without_project_only'])) {
            $q->whereRaw("{$projCount} = 0");
        }

        $total = (clone $q)->count();

        $rows = $q->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('departments as d', 'd.id', '=', 's.department_id')
            ->select([
                's.id', 'u.full_name', 'u.status as account_status', 's.student_number', 's.academic_year', 's.gpa',
                'f.name_en as faculty_en', 'f.name_ar as faculty_ar', 'd.name_en as dept_en', 'd.name_ar as dept_ar',
                DB::raw("{$projCount} AS projects_count"),
                DB::raw("(SELECT COUNT(*) FROM projects pr WHERE pr.owner_id = s.user_id AND pr.deleted_at IS NULL AND pr.status = 'published') AS published_count"),
                DB::raw('(SELECT pr.status FROM projects pr WHERE pr.owner_id = s.user_id AND pr.deleted_at IS NULL ORDER BY pr.updated_at DESC LIMIT 1) AS latest_project_status'),
                DB::raw('(SELECT pr.updated_at FROM projects pr WHERE pr.owner_id = s.user_id AND pr.deleted_at IS NULL ORDER BY pr.updated_at DESC LIMIT 1) AS latest_project_update'),
            ])
            ->orderBy('u.full_name')
            ->limit($this->lim($args))
            ->get();

        $students = $rows->map(function ($r) {
            $att = [];
            if ((int) $r->projects_count === 0) {
                $att[] = 'no_project';
            }
            $d = $this->daysSince($r->latest_project_update);
            if ($d !== null && $d >= 30 && $r->latest_project_status === 'draft') {
                $att[] = 'inactive_draft_' . $d . 'd';
            }
            return array_filter([
                'name' => $r->full_name,
                'student_number' => $r->student_number,
                'faculty' => $this->bi($r->faculty_en, $r->faculty_ar),
                'department' => $this->bi($r->dept_en, $r->dept_ar),
                'academic_year' => $r->academic_year,
                'gpa' => $r->gpa !== null ? (float) $r->gpa : null,
                'account_status' => $r->account_status !== 'active' ? $r->account_status : null,
                'projects' => (int) $r->projects_count,
                'published_projects' => (int) $r->published_count,
                'latest_project_status' => $r->latest_project_status,
                'attention' => $att,
            ], fn ($v) => $v !== null && $v !== '');
        })->all();

        return ['total_matching' => $total, 'returned' => count($students), 'students' => $students];
    }

    // =====================================================================
    // Student-only
    // =====================================================================

    private function toolStudentExams(array $a, array $args): array
    {
        if (!$a['student_id'] || !$a['university_id']) {
            return ['exams' => [], 'note' => 'You are not linked to a university yet, so no exams are targeted at you.'];
        }

        $ids = $this->examTargets->examIdsForStudent($a['student_id'], $a['university_id']);
        if (!$ids) {
            return ['exams' => [], 'note' => 'No exams are targeted at you right now.'];
        }

        $exams = DB::table('exams as e')
            ->whereIn('e.id', $ids)->whereNull('e.deleted_at')
            ->whereIn('e.status', ['scheduled', 'published', 'active', 'closed', 'grading', 'graded'])
            ->orderByRaw('COALESCE(e.start_at, e.created_at) DESC')
            ->limit($this->lim($args, 12))
            ->get(['e.id', 'e.title', 'e.subject', 'e.status', 'e.start_at', 'e.end_at', 'e.duration_minutes', 'e.max_attempts',
                'e.passing_score', 'e.total_marks', 'e.result_visibility', 'e.results_published_at']);

        $attempts = DB::table('exam_attempts')->where('student_id', $a['student_id'])
            ->whereIn('exam_id', $exams->pluck('id')->all())->get()->groupBy('exam_id');

        $now = time();
        $items = $exams->map(function ($e) use ($attempts, $now) {
            $mine = $attempts->get($e->id, collect());
            $start = $e->start_at ? strtotime((string) $e->start_at) : null;
            $end = $e->end_at ? strtotime((string) $e->end_at) : null;
            $window = $start && $start > $now ? 'upcoming' : ($end && $end < $now ? 'closed' : 'open_now');

            $inProgress = $mine->firstWhere('status', 'in_progress') ? true : null;
            $row = [
                'id' => (int) $e->id, 'title' => $this->clip($e->title, 150), 'subject' => $e->subject,
                'window' => $window, 'starts_at' => $this->dt($e->start_at), 'ends_at' => $this->dt($e->end_at),
                'duration_minutes' => (int) $e->duration_minutes,
                'attempts_used' => $mine->count(), 'max_attempts' => (int) $e->max_attempts,
                'attempt_in_progress' => $inProgress,
            ];

            // نفس قاعدة ExamGradingService::isResultVisibleTo بالظبط.
            $best = null;
            foreach ($mine->where('status', 'graded') as $att) {
                $visible = match ($e->result_visibility) {
                    'immediate' => true,
                    'after_close' => $end === null || $now >= $end,
                    'manual' => $e->results_published_at !== null && $now >= strtotime((string) $e->results_published_at),
                    default => false,
                };
                if ($visible && ($best === null || (float) $att->percentage > (float) $best->percentage)) {
                    $best = $att;
                }
            }
            if ($best) {
                $row['best_result'] = [
                    'score' => (float) $best->score, 'out_of' => (float) $e->total_marks,
                    'percentage' => (float) $best->percentage,
                    'passed' => $e->passing_score !== null ? (float) $best->score >= (float) $e->passing_score : null,
                ];
            } elseif ($mine->where('status', 'graded')->isNotEmpty()) {
                $row['result'] = 'graded but not released to students yet';
            } elseif ($mine->whereIn('status', self::GRADING_STATUSES)->isNotEmpty()) {
                $row['result'] = 'submitted, awaiting grading';
            }

            return array_filter($row, fn ($v) => $v !== null && $v !== '');
        })->all();

        return ['exams' => $items];
    }

    private function toolCourses(array $a, array $args): array
    {
        if (!$this->has('courses')) {
            return ['items' => [], 'note' => 'Courses are not available.'];
        }

        $q = DB::table('courses as c');
        if ($a['role'] === 'student') {
            if (!$a['student_id']) {
                return $this->noAccess('courses');
            }
            $q->join('course_enrollments as ce', 'ce.course_id', '=', 'c.id')->where('ce.student_id', $a['student_id']);
        } else {
            if (!$a['staff_id']) {
                return $this->noAccess('courses');
            }
            $q->join('course_staff as cs', 'cs.course_id', '=', 'c.id')->where('cs.academic_staff_id', $a['staff_id'])
                ->addSelect(DB::raw('(SELECT COUNT(*) FROM course_enrollments x WHERE x.course_id = c.id) AS enrolled'));
        }

        $rows = $q->where('c.status', 'active')->orderBy('c.academic_year')->orderBy('c.code')
            ->limit($this->lim($args))
            ->addSelect(['c.code', 'c.name_en', 'c.name_ar', 'c.credit_hours', 'c.academic_year', 'c.semester'])
            ->get();

        return ['items' => $rows->map(fn ($c) => array_filter([
            'code' => $c->code, 'name' => $this->bi($c->name_en, $c->name_ar),
            'credit_hours' => $c->credit_hours, 'academic_year' => $c->academic_year, 'semester' => $c->semester,
            'enrolled_students' => isset($c->enrolled) ? (int) $c->enrolled : null,
        ], fn ($v) => $v !== null && $v !== ''))->all()];
    }

    private function toolStudentGraduation(array $a, array $args): array
    {
        if (!$a['student_id']) {
            return ['note' => 'No student profile found for this account.'];
        }

        $s = DB::table('students')->where('id', $a['student_id'])->first();
        $out = array_filter([
            'academic_year' => $s->academic_year, 'current_semester' => $s->current_semester,
            'gpa' => $s->gpa !== null ? (float) $s->gpa : null,
            'study_start' => $s->study_start_date, 'expected_graduation' => $s->expected_graduation_date,
            'months_to_expected_graduation' => $s->expected_graduation_date ? (int) round(($this->daysUntil($s->expected_graduation_date) ?? 0) / 30) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $rec = DB::table('graduation_records')->where('student_id', $a['student_id'])->orderByDesc('id')->first();
        $out['graduation_record'] = $rec ? array_filter([
            'status' => $rec->status, 'graduation_date' => $rec->graduation_date,
            'certificate_number' => $rec->certificate_number, 'final_gpa' => $rec->final_gpa !== null ? (float) $rec->final_gpa : null,
        ], fn ($v) => $v !== null) : 'no graduation record yet';

        $out['projects_by_status'] = $this->countBy(
            DB::table('projects')->where('owner_id', $a['user_id'])->whereNull('deleted_at'),
            'status'
        );

        try {
            $sup = $this->assignments->supervisorsForStudent($a['user_id']);
            if ($sup) {
                $out['supervisors'] = array_map(fn ($r) => $r->full_name, array_slice($sup, 0, 5));
            }
        } catch (\Throwable $e) {
            // اختياري
        }

        $out['note'] = 'The detailed requirements checklist is on the Graduation Requirements page (/student/graduation).';
        return $out;
    }

    // =====================================================================
    // Exams (staff / faculty / university)
    // =====================================================================

    private function examScope(array $a): ?Builder
    {
        $q = DB::table('exams as e')->whereNull('e.deleted_at');
        switch ($a['role']) {
            case 'academic_staff':
                return $a['staff_id'] ? $q->where('e.created_by_academic_staff_id', $a['staff_id']) : null;
            case 'faculty':
                return $a['faculty_id'] ? $q->where('e.university_id', $a['university_id'])->where('e.faculty_id', $a['faculty_id']) : null;
            case 'university':
                return $a['university_id'] ? $q->where('e.university_id', $a['university_id']) : null;
        }
        return null;
    }

    private function toolExamsOverview(array $a, array $args): array
    {
        $q = $this->examScope($a);
        if ($q === null) {
            return $this->noAccess('exams') + ['exams' => []];
        }

        $grading = "'" . implode("','", self::GRADING_STATUSES) . "'";
        $appeals = $this->has('exam_grade_appeals');

        $base = clone $q;
        $pendingGradingTotal = (int) (clone $base)->join('exam_attempts as ea', 'ea.exam_id', '=', 'e.id')->whereIn('ea.status', self::GRADING_STATUSES)->count();
        $pendingAppealsTotal = $appeals
            ? (int) (clone $base)->join('exam_grade_appeals as ga', 'ga.exam_id', '=', 'e.id')->where('ga.status', 'pending')->count()
            : 0;

        $status = $this->enumArg($args['status'] ?? null, ['draft', 'scheduled', 'published', 'active', 'closed', 'grading', 'graded', 'archived']);
        if ($status) {
            $q->where('e.status', $status);
        }

        $cols = [
            'e.id', 'e.title', 'e.subject', 'e.status', 'e.start_at', 'e.end_at', 'e.duration_minutes', 'e.passing_score', 'e.total_marks',
            DB::raw('(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id = e.id) AS attempts_total'),
            DB::raw("(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.status = 'in_progress') AS attempts_in_progress"),
            DB::raw("(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.status IN ({$grading})) AS awaiting_grading"),
            DB::raw("(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.status = 'graded') AS graded"),
            DB::raw("(SELECT ROUND(AVG(ea.percentage), 1) FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.status = 'graded') AS avg_percentage"),
            DB::raw("(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.status = 'graded' AND e.passing_score IS NOT NULL AND ea.score >= e.passing_score) AS passed"),
            DB::raw('(SELECT COALESCE(SUM(ea.violations_count), 0) FROM exam_attempts ea WHERE ea.exam_id = e.id) AS violations'),
        ];
        if ($appeals) {
            $cols[] = DB::raw("(SELECT COUNT(*) FROM exam_grade_appeals ga WHERE ga.exam_id = e.id AND ga.status = 'pending') AS pending_appeals");
        }

        $rows = $q->select($cols)
            ->orderByRaw("CASE e.status WHEN 'grading' THEN 0 WHEN 'active' THEN 1 WHEN 'published' THEN 2 WHEN 'scheduled' THEN 3 WHEN 'closed' THEN 4 ELSE 5 END")
            ->orderByDesc('e.updated_at')
            ->limit($this->lim($args))
            ->get();

        $exams = $rows->map(function ($r) {
            $graded = (int) $r->graded;
            return array_filter([
                'id' => (int) $r->id, 'title' => $this->clip($r->title, 150), 'subject' => $r->subject, 'status' => $r->status,
                'starts_at' => $this->dt($r->start_at), 'ends_at' => $this->dt($r->end_at),
                'duration_minutes' => (int) $r->duration_minutes,
                'attempts' => (int) $r->attempts_total, 'in_progress' => (int) $r->attempts_in_progress,
                'awaiting_grading' => (int) $r->awaiting_grading, 'graded' => $graded,
                'avg_percentage' => $r->avg_percentage !== null ? (float) $r->avg_percentage : null,
                'pass_rate_percent' => ($graded > 0 && $r->passing_score !== null) ? (int) round(100 * (int) $r->passed / $graded) : null,
                'violations' => (int) $r->violations ?: null,
                'pending_appeals' => isset($r->pending_appeals) ? ((int) $r->pending_appeals ?: null) : null,
            ], fn ($v) => $v !== null && $v !== '');
        })->all();

        return [
            'totals' => ['attempts_awaiting_grading' => $pendingGradingTotal, 'pending_grade_appeals' => $pendingAppealsTotal],
            'exams' => $exams,
        ];
    }

    private function toolExamDetails(array $a, array $args): array
    {
        $id = (int) ($args['exam_id'] ?? 0);
        $q = $id > 0 ? $this->examScope($a) : null;
        $e = $q ? $q->where('e.id', $id)->first() : null;
        if (!$e) {
            return ['error' => 'not_found_or_no_access', 'message' => 'Exam not found in your scope.'];
        }

        $out = array_filter([
            'id' => (int) $e->id, 'title' => $this->clip($e->title, 150), 'subject' => $e->subject, 'status' => $e->status,
            'starts_at' => $this->dt($e->start_at), 'ends_at' => $this->dt($e->end_at),
            'duration_minutes' => (int) $e->duration_minutes, 'max_attempts' => (int) $e->max_attempts,
            'passing_score' => $e->passing_score !== null ? (float) $e->passing_score : null,
            'total_marks' => (float) $e->total_marks, 'result_visibility' => $e->result_visibility,
            'results_released' => $e->results_published_at ? true : null,
            'questions' => (int) DB::table('exam_questions')->where('exam_id', $id)->count(),
            'targeted_rules' => (int) DB::table('exam_targets')->where('exam_id', $id)->count(),
        ], fn ($v) => $v !== null && $v !== '');

        $pct = DB::table('exam_attempts')->where('exam_id', $id)->where('status', 'graded')->whereNotNull('percentage')->limit(2000)->pluck('percentage');
        if ($pct->isNotEmpty()) {
            $out['score_distribution'] = [
                'below_50' => $pct->filter(fn ($p) => $p < 50)->count(),
                '50_to_69' => $pct->filter(fn ($p) => $p >= 50 && $p < 70)->count(),
                '70_to_84' => $pct->filter(fn ($p) => $p >= 70 && $p < 85)->count(),
                '85_and_above' => $pct->filter(fn ($p) => $p >= 85)->count(),
                'average' => round((float) $pct->avg(), 1), 'highest' => (float) $pct->max(), 'lowest' => (float) $pct->min(),
            ];
        }

        $attemptRows = fn () => DB::table('exam_attempts as ea')
            ->join('students as s', 's.id', '=', 'ea.student_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('ea.exam_id', $id);

        $out['awaiting_grading'] = $attemptRows()->whereIn('ea.status', self::GRADING_STATUSES)
            ->orderBy('ea.submitted_at')->limit(15)
            ->get(['u.full_name', 's.student_number', 'ea.status', 'ea.submitted_at', 'ea.violations_count'])
            ->map(fn ($r) => array_filter([
                'student' => $r->full_name, 'student_number' => $r->student_number, 'status' => $r->status,
                'submitted_at' => $this->dt($r->submitted_at), 'violations' => (int) $r->violations_count ?: null,
            ], fn ($v) => $v !== null))->all();

        $out['high_violation_attempts'] = $attemptRows()->where('ea.violations_count', '>=', 3)
            ->orderByDesc('ea.violations_count')->limit(8)
            ->get(['u.full_name', 's.student_number', 'ea.violations_count', 'ea.status'])
            ->map(fn ($r) => ['student' => $r->full_name, 'student_number' => $r->student_number, 'violations' => (int) $r->violations_count, 'status' => $r->status])
            ->all();

        if ($this->has('exam_grade_appeals')) {
            $out['pending_appeals'] = DB::table('exam_grade_appeals as ga')
                ->join('students as s', 's.id', '=', 'ga.student_id')->join('users as u', 'u.id', '=', 's.user_id')
                ->where('ga.exam_id', $id)->where('ga.status', 'pending')->orderBy('ga.created_at')->limit(10)
                ->get(['u.full_name', 'ga.reason', 'ga.created_at'])
                ->map(fn ($r) => ['student' => $r->full_name, 'reason' => $this->clip($r->reason, 220), 'at' => $this->dt($r->created_at)])->all();
        }

        return $out;
    }

    // =====================================================================
    // University / Faculty
    // =====================================================================

    private function toolUniversityOverview(array $a, array $args): array
    {
        $uid = $a['university_id'];
        if (!$uid) {
            return $this->noAccess('institution data');
        }
        $isFaculty = $a['role'] === 'faculty';
        $fid = $a['faculty_id'];
        if ($isFaculty && !$fid) {
            return $this->noAccess('faculty data');
        }

        $students = fn () => DB::table('students as s')->where('s.university_id', $uid)->when($isFaculty, fn ($q) => $q->where('s.faculty_id', $fid));
        $projects = fn () => $isFaculty
            ? DB::table('projects as p')->whereNull('p.deleted_at')->whereIn('p.owner_id', fn ($s) => $s->select('user_id')->from('students')->where('faculty_id', $fid))
            : DB::table('projects as p')->whereNull('p.deleted_at')->where('p.university_id', $uid);

        $byStatus = $this->countBy($projects(), 'p.status');
        $awaiting = ($byStatus['submitted'] ?? 0) + ($byStatus['under_review'] ?? 0);

        $joinQ = DB::table('student_university_requests as r')->where('r.university_id', $uid)->where('r.status', 'pending')
            ->when($isFaculty, fn ($q) => $q->where('r.faculty_id', $fid));
        $pendingJoin = (int) $joinQ->count();

        $gradQ = DB::table('graduation_records as g')->where('g.university_id', $uid)->where('g.status', 'graduated')
            ->when($isFaculty, fn ($q) => $q->whereIn('g.student_id', fn ($s) => $s->select('id')->from('students')->where('faculty_id', $fid)));

        $noProject = (int) $students()->whereRaw('NOT EXISTS (SELECT 1 FROM projects pr WHERE pr.owner_id = s.user_id AND pr.deleted_at IS NULL)')->count();

        $out = [
            'students' => (int) $students()->count(),
            'students_without_project' => $noProject,
            'academic_staff' => (int) DB::table('academic_staff')->where('university_id', $uid)->when($isFaculty, fn ($q) => $q->where('faculty_id', $fid))->count(),
            'projects_by_status' => $byStatus,
            'projects_awaiting_review' => $awaiting,
            'pending_join_requests' => $pendingJoin,
            'graduates' => (int) $gradQ->count(),
        ];

        $attention = [];
        if ($awaiting > 0) {
            $attention[] = "{$awaiting} project(s) awaiting review";
        }
        if ($pendingJoin > 0) {
            $attention[] = "{$pendingJoin} pending join request(s)";
        }

        if ($isFaculty) {
            $out['departments'] = DB::table('departments as d')->where('d.faculty_id', $fid)->whereNull('d.deleted_at')
                ->select(['d.name_en', 'd.name_ar', DB::raw('(SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS students')])
                ->orderBy('d.name_en')->limit(30)->get()
                ->map(fn ($d) => ['name' => $this->bi($d->name_en, $d->name_ar), 'students' => (int) $d->students])->all();
        } else {
            $out['faculties'] = DB::table('faculties as f')->where('f.university_id', $uid)
                ->select([
                    'f.name_en', 'f.name_ar',
                    DB::raw('(SELECT COUNT(*) FROM students s WHERE s.faculty_id = f.id) AS students'),
                    DB::raw('(SELECT COUNT(*) FROM academic_staff x WHERE x.faculty_id = f.id) AS staff'),
                    DB::raw('(SELECT COUNT(*) FROM projects p JOIN students s ON s.user_id = p.owner_id WHERE s.faculty_id = f.id AND p.deleted_at IS NULL) AS projects'),
                    DB::raw("(SELECT COUNT(*) FROM projects p JOIN students s ON s.user_id = p.owner_id WHERE s.faculty_id = f.id AND p.deleted_at IS NULL AND p.status IN ('submitted','under_review')) AS awaiting_review"),
                ])->orderBy('f.name_en')->limit(30)->get()
                ->map(fn ($f) => ['name' => $this->bi($f->name_en, $f->name_ar), 'students' => (int) $f->students, 'staff' => (int) $f->staff, 'projects' => (int) $f->projects, 'awaiting_review' => (int) $f->awaiting_review])->all();

            $u = DB::table('universities')->where('id', $uid)->first(['verification_status', 'verification_expires_at']);
            if ($u) {
                $out['verification'] = array_filter([
                    'status' => $u->verification_status, 'expires_at' => $this->dt($u->verification_expires_at),
                    'expires_in_days' => $u->verification_expires_at ? $this->daysUntil($u->verification_expires_at) : null,
                ], fn ($v) => $v !== null);
                $left = $u->verification_expires_at ? $this->daysUntil($u->verification_expires_at) : null;
                if ($left !== null && $left <= 30) {
                    $attention[] = $left < 0 ? 'verification has expired' : "verification expires in {$left} day(s)";
                }
                if ($u->verification_status !== 'verified') {
                    $attention[] = 'university is not verified (status: ' . $u->verification_status . ')';
                }
            }
        }

        if ($noProject > 0) {
            $attention[] = "{$noProject} student(s) have no project yet";
        }
        $out['needs_attention'] = $attention;

        return $out;
    }

    private function toolJoinRequests(array $a, array $args): array
    {
        if (!$a['university_id'] || ($a['role'] === 'faculty' && !$a['faculty_id'])) {
            return $this->noAccess('join requests');
        }

        $rows = DB::table('student_university_requests as r')
            ->join('students as s', 's.id', '=', 'r.student_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('faculties as f', 'f.id', '=', 'r.faculty_id')->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->where('r.university_id', $a['university_id'])->where('r.status', 'pending')
            ->when($a['role'] === 'faculty', fn ($q) => $q->where('r.faculty_id', $a['faculty_id']))
            ->orderBy('r.created_at')->limit($this->lim($args))
            ->get(['u.full_name', 's.student_number', 'f.name_en as f_en', 'f.name_ar as f_ar', 'd.name_en as d_en', 'd.name_ar as d_ar', 'r.created_at']);

        return ['pending' => $rows->map(fn ($r) => array_filter([
            'student' => $r->full_name, 'student_number' => $r->student_number,
            'faculty' => $this->bi($r->f_en, $r->f_ar), 'department' => $this->bi($r->d_en, $r->d_ar),
            'requested_at' => $this->dt($r->created_at), 'waiting_days' => $this->daysSince($r->created_at),
        ], fn ($v) => $v !== null && $v !== ''))->all()];
    }

    private function toolGraduationOverview(array $a, array $args): array
    {
        $uid = $a['university_id'];
        if (!$uid || ($a['role'] === 'faculty' && !$a['faculty_id'])) {
            return $this->noAccess('graduation data');
        }
        $fid = $a['role'] === 'faculty' ? $a['faculty_id'] : null;

        $records = fn () => DB::table('graduation_records as g')->join('students as s', 's.id', '=', 'g.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')->where('g.university_id', $uid)
            ->when($fid, fn ($q) => $q->where('s.faculty_id', $fid));

        $latest = $records()->orderByDesc('g.graduation_date')->limit($this->lim($args, 10))
            ->get(['u.full_name', 'g.status', 'g.graduation_date', 'g.certificate_number', 'g.final_gpa', 'g.faculty_name_en', 'g.faculty_name_ar'])
            ->map(fn ($r) => array_filter([
                'student' => $r->full_name, 'status' => $r->status, 'graduation_date' => $r->graduation_date,
                'certificate_number' => $r->certificate_number, 'final_gpa' => $r->final_gpa !== null ? (float) $r->final_gpa : null,
                'faculty' => $this->bi($r->faculty_name_en, $r->faculty_name_ar),
            ], fn ($v) => $v !== null && $v !== ''))->all();

        $soon = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.university_id', $uid)
            ->when($fid, fn ($q) => $q->where('s.faculty_id', $fid))
            ->whereNotNull('s.expected_graduation_date')
            ->whereBetween('s.expected_graduation_date', [now()->toDateString(), now()->addMonths(6)->toDateString()])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM graduation_records g WHERE g.student_id = s.id AND g.status = \'graduated\')');

        return [
            'counts' => $this->countBy($records(), 'g.status'),
            'latest_records' => $latest,
            'expected_within_6_months_without_record' => [
                'count' => (int) (clone $soon)->count(),
                'students' => $soon->orderBy('s.expected_graduation_date')->limit(10)->get(['u.full_name', 's.student_number', 's.expected_graduation_date'])
                    ->map(fn ($r) => ['student' => $r->full_name, 'student_number' => $r->student_number, 'expected' => $r->expected_graduation_date])->all(),
            ],
        ];
    }

    // =====================================================================
    // Admin
    // =====================================================================

    private function toolPlatformOverview(array $a, array $args): array
    {
        $out = [
            'users_by_status' => $this->countBy(DB::table('users')->whereNull('deleted_at'), 'status'),
            'users_by_role' => $this->countBy(
                DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')->whereNull('u.deleted_at'),
                'r.slug'
            ),
            'universities_by_verification' => $this->countBy(DB::table('universities'), 'verification_status'),
            'projects_by_status' => $this->countBy(DB::table('projects')->whereNull('deleted_at'), 'status'),
            'new_users_last_7_days' => (int) DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(7))->count(),
            'new_users_last_30_days' => (int) DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(30))->count(),
        ];

        if ($this->has('exams')) {
            $out['exams_by_status'] = $this->countBy(DB::table('exams')->whereNull('deleted_at'), 'status');
        }
        if ($this->has('security_incidents')) {
            $out['open_security_incidents'] = (int) DB::table('security_incidents')->whereIn('status', ['open', 'investigating'])->count();
            $out['open_critical_incidents'] = (int) DB::table('security_incidents')->whereIn('status', ['open', 'investigating'])->where('severity', 'critical')->count();
        }
        if ($this->has('ai_usage_logs')) {
            $since = now()->subDays(7);
            $out['ai_assistant_last_7_days'] = [
                'requests' => (int) DB::table('ai_usage_logs')->where('created_at', '>=', $since)->count(),
                'errors' => (int) DB::table('ai_usage_logs')->where('created_at', '>=', $since)->where('was_error', 1)->count(),
                'active_users' => (int) DB::table('ai_usage_logs')->where('created_at', '>=', $since)->distinct()->count('user_id'),
            ];
        }

        $attention = [];
        $pendingUni = $out['universities_by_verification']['pending'] ?? 0;
        $pendingUsers = $out['users_by_status']['pending'] ?? 0;
        $review = ($out['projects_by_status']['under_review'] ?? 0) + ($out['projects_by_status']['submitted'] ?? 0);
        if ($pendingUni) {
            $attention[] = "{$pendingUni} university verification(s) pending";
        }
        if ($pendingUsers) {
            $attention[] = "{$pendingUsers} account(s) in pending status";
        }
        if ($review) {
            $attention[] = "{$review} project(s) submitted/under review across the platform";
        }
        if (!empty($out['open_critical_incidents'])) {
            $attention[] = $out['open_critical_incidents'] . ' open critical security incident(s)';
        }
        $out['needs_attention'] = $attention;

        return $out;
    }

    private function toolSearchUsers(array $a, array $args): array
    {
        $q = DB::table('users as u')->whereNull('u.deleted_at');

        $search = trim((string) ($args['query'] ?? ''));
        if ($search !== '') {
            $like = $this->like($search);
            $q->where(function ($w) use ($like) {
                $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like);
            });
        }
        $status = $this->enumArg($args['status'] ?? null, ['active', 'pending', 'suspended', 'banned']);
        if ($status) {
            $q->where('u.status', $status);
        }
        $role = trim((string) ($args['role'] ?? ''));
        if ($role !== '') {
            $q->whereIn('u.id', fn ($s) => $s->select('ur.user_id')->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('r.slug', $role));
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('u.created_at')->limit($this->lim($args, 10))->get([
            'u.id', 'u.full_name', 'u.email', 'u.status', 'u.last_login_at', 'u.created_at', 'u.two_factor_enabled', 'u.locked_until', 'u.lock_permanent',
            DB::raw('(SELECT GROUP_CONCAT(r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles'),
        ]);

        return ['total_matching' => $total, 'users' => $rows->map(fn ($u) => array_filter([
            'id' => (int) $u->id, 'name' => $u->full_name, 'email' => $u->email, 'roles' => $u->roles, 'status' => $u->status,
            'last_login' => $this->dt($u->last_login_at), 'joined' => $this->dt($u->created_at),
            'two_factor' => (bool) $u->two_factor_enabled,
            'locked' => ($u->lock_permanent || ($u->locked_until && strtotime((string) $u->locked_until) > time())) ? true : null,
        ], fn ($v) => $v !== null && $v !== ''))->all()];
    }

    private function toolAuditLogs(array $a, array $args): array
    {
        $q = DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.created_at', '>=', now()->subHours($this->hours($args)));

        $needle = trim((string) ($args['action_contains'] ?? ''));
        if ($needle !== '') {
            $q->where('l.action', 'like', $this->like($needle));
        }

        $total = (clone $q)->count();
        $byAction = (clone $q)->select('l.action', DB::raw('COUNT(*) AS c'))->groupBy('l.action')->orderByDesc('c')->limit(8)->get()
            ->mapWithKeys(fn ($r) => [$r->action => (int) $r->c])->all();

        $rows = $q->orderByDesc('l.id')->limit($this->lim($args))->get(['l.action', 'l.subject_type', 'l.subject_id', 'l.ip_address', 'l.created_at', 'u.full_name']);

        return [
            'window_hours' => $this->hours($args), 'total_in_window' => $total, 'top_actions' => $byAction,
            'entries' => $rows->map(fn ($r) => array_filter([
                'action' => $r->action, 'by' => $r->full_name, 'subject' => $r->subject_type ? $r->subject_type . ($r->subject_id ? "#{$r->subject_id}" : '') : null,
                'ip' => $r->ip_address, 'at' => $this->dt($r->created_at),
            ], fn ($v) => $v !== null && $v !== ''))->all(),
        ];
    }

    // =====================================================================
    // Security
    // =====================================================================

    private function toolSecurityOverview(array $a, array $args): array
    {
        $out = [];
        $open = ['open', 'investigating', 'contained'];

        if ($this->has('security_incidents')) {
            $out['incidents_by_status'] = $this->countBy(DB::table('security_incidents'), 'status');
            $out['open_incidents_by_severity'] = $this->countBy(DB::table('security_incidents')->whereIn('status', $open), 'severity');
            $out['latest_open_incidents'] = DB::table('security_incidents')->whereIn('status', $open)
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderByDesc('detected_at')->limit(8)
                ->get(['reference_code', 'title', 'category', 'severity', 'status', 'detected_at'])
                ->map(fn ($r) => ['ref' => $r->reference_code, 'title' => $this->clip($r->title, 120), 'category' => $r->category, 'severity' => $r->severity, 'status' => $r->status, 'detected' => $this->dt($r->detected_at)])->all();
        }
        if ($this->has('security_alerts')) {
            $out['open_alerts_by_severity'] = $this->countBy(DB::table('security_alerts')->whereIn('status', ['open', 'escalated']), 'severity');
        }
        if ($this->has('vulnerabilities')) {
            $openV = fn () => DB::table('vulnerabilities')->whereIn('status', ['open', 'in_progress']);
            $out['open_vulnerabilities_by_severity'] = $this->countBy($openV(), 'severity');
            $out['overdue_vulnerabilities'] = (int) $openV()->whereNotNull('deadline')->where('deadline', '<', now()->toDateString())->count();
        }
        if ($this->has('security_logs')) {
            $out['critical_events_last_24h'] = (int) DB::table('security_logs')->where('severity', 'critical')->where('created_at', '>=', now()->subDay())->count();
            $out['failed_logins_last_24h'] = (int) DB::table('security_logs')->where('event_type', 'like', '%fail%')->where('created_at', '>=', now()->subDay())->count();
        }

        return $out ?: ['note' => 'Security data is not available.'];
    }

    private function toolSecurityEvents(array $a, array $args): array
    {
        if (!$this->has('security_logs')) {
            return ['entries' => [], 'note' => 'Security logs are not available.'];
        }
        $q = DB::table('security_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.created_at', '>=', now()->subHours($this->hours($args)));
        $sev = $this->enumArg($args['severity'] ?? null, ['info', 'warning', 'critical']);
        if ($sev) {
            $q->where('l.severity', $sev);
        }

        $byType = (clone $q)->select('l.event_type', DB::raw('COUNT(*) AS c'))->groupBy('l.event_type')->orderByDesc('c')->limit(8)->get()
            ->mapWithKeys(fn ($r) => [$r->event_type => (int) $r->c])->all();

        $rows = $q->orderByDesc('l.id')->limit($this->lim($args))->get(['l.event_type', 'l.severity', 'l.ip_address', 'l.created_at', 'u.full_name']);

        return [
            'window_hours' => $this->hours($args), 'top_event_types' => $byType,
            'entries' => $rows->map(fn ($r) => array_filter([
                'event' => $r->event_type, 'severity' => $r->severity, 'user' => $r->full_name, 'ip' => $r->ip_address, 'at' => $this->dt($r->created_at),
            ], fn ($v) => $v !== null && $v !== ''))->all(),
        ];
    }

    // =====================================================================
    // Data analysis
    // =====================================================================

    private function toolAnalytics(array $a, array $args): array
    {
        $out = [
            'projects_by_status' => $this->countBy(DB::table('projects')->whereNull('deleted_at'), 'status'),
            'projects_by_category_top' => DB::table('projects')->whereNull('deleted_at')->whereNotNull('category')
                ->select('category', DB::raw('COUNT(*) AS c'))->groupBy('category')->orderByDesc('c')->limit(8)->get()
                ->mapWithKeys(fn ($r) => [$r->category => (int) $r->c])->all(),
            'projects_created_per_month' => DB::table('projects')->whereNull('deleted_at')->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
                ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') AS m"), DB::raw('COUNT(*) AS c'))->groupBy('m')->orderBy('m')->get()
                ->mapWithKeys(fn ($r) => [$r->m => (int) $r->c])->all(),
            'users_by_role' => $this->countBy(
                DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')->whereNull('u.deleted_at'),
                'r.slug'
            ),
            'universities' => (int) DB::table('universities')->count(),
            'students' => (int) DB::table('students')->count(),
        ];

        if ($this->has('exam_attempts')) {
            $out['exams'] = [
                'by_status' => $this->countBy(DB::table('exams')->whereNull('deleted_at'), 'status'),
                'graded_attempts' => (int) DB::table('exam_attempts')->where('status', 'graded')->count(),
                'average_percentage' => round((float) DB::table('exam_attempts')->where('status', 'graded')->avg('percentage'), 1),
            ];
        }
        if ($this->has('kpis')) {
            $out['kpis'] = DB::table('kpis')->where('status', 'active')->orderBy('category')->orderBy('name')->limit(20)
                ->get(['name', 'category', 'unit', 'current_value', 'target_value', 'direction'])
                ->map(function ($k) {
                    $cur = (float) $k->current_value;
                    $tgt = (float) $k->target_value;
                    return array_filter([
                        'name' => $this->clip($k->name, 80), 'category' => $k->category, 'unit' => $k->unit, 'current' => $cur, 'target' => $tgt,
                        'on_track' => $tgt == 0.0 ? null : ($k->direction === 'lower_better' ? $cur <= $tgt : $cur >= $tgt),
                    ], fn ($v) => $v !== null && $v !== '');
                })->all();
        }

        return $out;
    }
}
