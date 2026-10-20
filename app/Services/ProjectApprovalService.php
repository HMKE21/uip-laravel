<?php

namespace App\Services;

use App\Exceptions\ApprovalException;
use App\Models\Project;
use App\Models\ProjectApproval;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Support\ApprovalFlow;
use App\Support\PersonName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مسار الاعتماد الإجباري (مجموعة 4): دكتور مشرف ← كلية ← جامعة.
 *  - كل قرار بيتسجل بمرحلته (project_approvals.stage) وبدورة التقديم (review_round).
 *  - مرحلة مبتقدرش تقرر غير لما يجي دورها (App\Support\ApprovalFlow): الدكتور والكلية والجامعة
 *    والمشرف-بنطاق كلهم بيمرّوا على applyDecision() الواحدة، تحت قفل صف المشروع (transaction).
 *  - اعتماد آخر مرحلة بيخلّي الحالة `approved` (معتمد)؛ النشر للعامة خطوة منفصلة
 *    (publishAsOwner / publishForUniversity وعكسها unpublishForUniversity).
 *  - المعيد (ربط استشاري) مبيقررش ولا بينشر.
 *  - مرحلة مش ممكن تتحل (مفيش مشرف مربوط بحساب، أو الطالب من غير كلية ببورتال) بتتخطى بدل ما
 *    المشروع يعلق، والمشرف غير المربوط بيتعلّم (supervisor_unlinked) عشان يتصلح.
 *
 * بوابة المراجعة البشرية الإلزامية (AI acknowledgment): القرار نفسه دايمًا بشري (reviewer_id)،
 * والقرار بيترفض (false) على مشروع عنده مؤشرات ذكاء اصطناعي إلا لو $aiAcknowledged = true،
 * وبتتحفظ نفس المؤشرات في project_approvals.ai_output_snapshot.
 */
class ProjectApprovalService
{
    public function __construct(
        private ProjectRepository $projects,
        private UniversityRepository $universities,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private AIAnalysisRepository $aiAnalysis,
        private FacultyRepository $faculties,
        private ProjectNotifier $notifier
    ) {
    }

    /** يحوّل مستخدم جامعة (users.id) لـ universities.id بتاعته. */
    public function universityIdForUser($userId): ?int
    {
        $university = $this->universities->findByUserId($userId);
        return $university?->id;
    }

    /** يحوّل مستخدم بورتال كلية (migration 106) لـ faculties.id بتاعته. */
    public function facultyIdForUser($userId): ?int
    {
        $faculty = $this->faculties->findByUserId($userId);
        return $faculty?->id;
    }

    // -- طوابير (جامعة / كلية) -------------------------------------------------

    /**
     * طابور اعتماد كامل لجامعة. مشروع draft لسه الطالب مقدموش مستبعد؛ draft ناتج عن
     * "طلب تعديلات" متضمّن وموسوم كده.
     */
    public function queueForUniversity($universityId): array
    {
        return $this->buildQueue($this->projects->forUniversityWithOwner($universityId));
    }

    /** زي queueForUniversity()، مقيّدة بكلية واحدة. */
    public function queueForFaculty($facultyId): array
    {
        return $this->buildQueue($this->projects->forFacultyWithOwner($facultyId));
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function buildQueue(array $rows): array
    {
        $visible = [];
        foreach ($rows as $r) {
            $lastDecision = in_array($r['status'], ['draft', 'submitted', 'approved', 'published', 'rejected'], true)
                ? $this->lastDecision((int) $r['id'])
                : null;
            if ($r['status'] === 'draft' && $lastDecision !== 'changes_requested') {
                continue; // draft عمره ما اتقدم — لسه مش شغل المراجعين
            }
            $visible[] = $r;
        }

        $flows = $this->flowSummaries($visible);
        return array_map(fn (array $r) => $this->toQueueRow($r, $flows[(int) $r['id']] ?? null), $visible);
    }

    // -- مسار الاعتماد: حساب المراحل -------------------------------------------

    /** مسار الاعتماد لمشروع واحد (المراحل المطلوبة + المعتمدة + المرحلة المنتظرة). */
    public function flowFor(Project $project): array
    {
        $attrs = $project->getAttributes();
        $attrs['id'] = $project->id;
        return $this->flowSummaries([$attrs])[(int) $project->id];
    }

    /**
     * نفس flowFor() لمجموعة مشاريع بعدد ثابت من الاستعلامات (من غير N+1 في الطوابير).
     * الصف لازم فيه: id, owner_id, university_id, status, review_round, supervisor_name.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{required:string[],approved:string[],awaiting:?string,round:int,supervisor_unlinked:bool,stages:array<int,array{stage:string,state:string}>}>
     */
    public function flowSummaries(array $rows): array
    {
        $rows = array_values(array_map(fn ($r) => (array) $r, $rows));
        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(fn ($r) => (int) $r['id'], $rows)));
        $ownerIds = array_values(array_unique(array_map(fn ($r) => (int) $r['owner_id'], $rows)));

        // الطالب عنده كلية ببورتال (حساب user_id)؟ لو لأ، مرحلة الكلية بتتخطى.
        $facultyOwners = DB::table('students as s')
            ->join('faculties as f', 'f.id', '=', 's.faculty_id')
            ->whereIn('s.user_id', $ownerIds)->whereNotNull('f.user_id')
            ->pluck('s.user_id')->map(fn ($v) => (int) $v)->flip()->all();

        $approvedByProject = [];
        $approvals = DB::table('project_approvals')
            ->whereIn('project_id', $ids)->where('decision', 'approved')->whereIn('stage', ApprovalFlow::ORDER)
            ->get(['project_id', 'stage', 'review_round']);
        foreach ($approvals as $a) {
            $approvedByProject[(int) $a->project_id][] = ['stage' => $a->stage, 'round' => (int) $a->review_round];
        }

        $teamSupervisors = DB::table('project_team_members')
            ->whereIn('project_id', $ids)->where('status', 'accepted')
            ->whereIn('role', ApprovalFlow::OFFICIAL_ROLES)->whereNotNull('user_id')
            ->pluck('project_id')->map(fn ($v) => (int) $v)->flip()->all();

        $staffByUniversity = [];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $status = (string) $r['status'];
            $round = max(1, (int) ($r['review_round'] ?? 0));

            // مشرف: صف فريق رسمي بحساب، أو اسم مكتوب بيتحل لدكتور واحد بس في الجامعة.
            $typed = trim((string) ($r['supervisor_name'] ?? ''));
            $hasSupervisor = isset($teamSupervisors[$id]);
            if (!$hasSupervisor && $typed !== '' && !empty($r['university_id'])) {
                $uni = (int) $r['university_id'];
                $staffByUniversity[$uni] ??= $this->activeStaff($uni);
                $hasSupervisor = PersonName::resolveUnique($typed, $staffByUniversity[$uni]) !== null;
            }
            $unlinked = !$hasSupervisor && $typed !== '';

            $required = [];
            if ($hasSupervisor) {
                $required[] = ApprovalFlow::SUPERVISOR;
            }
            if (isset($facultyOwners[(int) $r['owner_id']])) {
                $required[] = ApprovalFlow::FACULTY;
            }
            $required[] = ApprovalFlow::UNIVERSITY; // دايمًا موجودة: آخر مرحلة

            // قرارات الدورة الحالية بس. round 0 = قرارات قديمة قبل التسلسل: مبتتحسبش.
            $approved = [];
            foreach ($approvedByProject[$id] ?? [] as $d) {
                if ($d['round'] === $round && in_array($d['stage'], $required, true)) {
                    $approved[$d['stage']] = true;
                }
            }
            $approved = array_values(array_filter(ApprovalFlow::ORDER, fn ($st) => isset($approved[$st])));

            $awaiting = $status === 'submitted' ? ApprovalFlow::awaiting($required, $approved) : null;
            $complete = in_array($status, ['approved', 'published'], true);

            $stages = [];
            foreach (ApprovalFlow::ORDER as $st) {
                $state = !in_array($st, $required, true) ? 'skipped'
                    : (($complete || in_array($st, $approved, true)) ? 'approved'
                    : ($st === $awaiting ? 'awaiting' : 'pending'));
                $stages[] = ['stage' => $st, 'state' => $state];
            }

            $out[$id] = [
                'required' => $required, 'approved' => $approved, 'awaiting' => $awaiting,
                'round' => $round, 'supervisor_unlinked' => $unlinked, 'stages' => $stages,
            ];
        }
        return $out;
    }

    /** @return array<int,object{user_id:int,full_name:string}> دكاترة الجامعة الفعّالين. */
    private function activeStaff(int $universityId): array
    {
        return DB::table('academic_staff as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.university_id', $universityId)->where('a.status', 'active')->whereNull('u.deleted_at')
            ->get(['a.user_id', 'u.full_name', 'u.email'])->all();
    }

    // -- قرارات المراحل (كلها بتعدي على applyDecision) --------------------------

    public function approve(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::UNIVERSITY, $this->projects->findForUniversityByUuid($uuid, $universityId), $reviewerId, 'approve', $comments, $aiAcknowledged);
    }

    public function reject(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::UNIVERSITY, $this->projects->findForUniversityByUuid($uuid, $universityId), $reviewerId, 'reject', $comments, $aiAcknowledged);
    }

    public function requestChanges(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::UNIVERSITY, $this->projects->findForUniversityByUuid($uuid, $universityId), $reviewerId, 'request_changes', $comments, $aiAcknowledged);
    }

    public function approveForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::FACULTY, $this->projects->findForFacultyByUuid($uuid, $facultyId), $reviewerId, 'approve', $comments, $aiAcknowledged);
    }

    public function rejectForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::FACULTY, $this->projects->findForFacultyByUuid($uuid, $facultyId), $reviewerId, 'reject', $comments, $aiAcknowledged);
    }

    public function requestChangesForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        return $this->applyDecision(ApprovalFlow::FACULTY, $this->projects->findForFacultyByUuid($uuid, $facultyId), $reviewerId, 'request_changes', $comments, $aiAcknowledged);
    }

    /**
     * قرار الدكتور المشرف (مرحلة supervisor_review). الفحص إن المشروع داخل نطاقه بيتم قبل النداء
     * (AcademicStaffProjectService::scopedProject) وبيدّي $linkRole؛ هنا بنتأكد إنه ربط رسمي.
     * @param string $action approve|reject|request_changes
     * @throws ApprovalException المعيد (استشاري) أو مش دور المشرف.
     */
    public function decideAsStaff(string $uuid, $reviewerId, string $action, ?string $comments, bool $aiAcknowledged = false, ?string $linkRole = null): bool
    {
        if (!in_array($linkRole, ApprovalFlow::OFFICIAL_ROLES, true)) {
            throw ApprovalException::advisoryRole();
        }
        $project = $this->projects->findByUuid($uuid);
        if (!$project) {
            return false;
        }
        // الدكتور لازم يكون عضو هيئة تدريس فعّال في نفس جامعة المشروع (دفاع إضافي فوق فحص النطاق).
        $isStaff = DB::table('academic_staff')->where('user_id', $reviewerId)
            ->where('university_id', $project->university_id)->where('status', 'active')->exists();
        if (!$isStaff) {
            return false;
        }
        return $this->applyDecision(ApprovalFlow::SUPERVISOR, $project, $reviewerId, $action, $comments, $aiAcknowledged);
    }

    /**
     * المحرك الواحد: كل قرار (أي مرحلة، أي جهة) بيتحقق من إن المشروع submitted، وإن دور المرحلة دي
     * فعلًا، وبيسجّل القرار بمرحلته ودورته، وبيحدّث الحالة، كل ده في transaction واحدة بقفل على صف
     * المشروع — عشان قرارين متزامنين (أو ضغطتين) ميسجلوش اعتماد مرتين.
     * @throws ApprovalException لو مش دور المرحلة.
     */
    private function applyDecision(string $stage, ?Project $project, $reviewerId, string $action, ?string $comments, bool $aiAcknowledged): bool
    {
        if (!$project || !in_array($action, ['approve', 'reject', 'request_changes'], true)) {
            return false;
        }

        $result = DB::transaction(function () use ($stage, $project, $reviewerId, $action, $comments, $aiAcknowledged) {
            $locked = Project::where('id', $project->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'submitted') {
                return null;
            }

            $flow = $this->flowFor($locked);
            if ($flow['awaiting'] !== $stage) {
                throw ApprovalException::notYourTurn((string) ($flow['awaiting'] ?? 'none'));
            }

            $snapshot = $this->aiOutputSnapshot((int) $locked->id);
            if ($snapshot !== null && !$aiAcknowledged) {
                return null;
            }

            $round = $flow['round'];
            $decision = ['approve' => 'approved', 'reject' => 'rejected', 'request_changes' => 'changes_requested'][$action];
            $next = null;
            $final = false;

            if ($action === 'approve') {
                $final = ApprovalFlow::isFinal($stage, $flow['required']);
                if ($final) {
                    $locked->status = 'approved';
                    $locked->approved_at = now();
                } else {
                    $next = ApprovalFlow::awaiting($flow['required'], array_merge($flow['approved'], [$stage]));
                }
            } elseif ($action === 'reject') {
                $locked->status = 'rejected';
            } else {
                $locked->status = 'draft'; // يرجع للطالب يعدّل ويعيد التقديم (دورة جديدة)
            }

            $locked->review_round = $round; // تثبيت الدورة للمشاريع القديمة (review_round = 0)
            $locked->save();

            $this->recordDecision((int) $locked->id, $reviewerId, $decision, $comments, $aiAcknowledged, $snapshot, $stage, $round);

            return ['project' => $locked, 'final' => $final, 'next' => $next];
        });

        if ($result === null) {
            return false;
        }

        /** @var Project $p */
        $p = $result['project'];
        Log::info('Project decision', ['project_id' => $p->id, 'stage' => $stage, 'action' => $action]);

        $title = $p->title_en ?: $p->title_ar;
        $url = '/student/projects/' . $p->uuid;
        $stageEn = ['supervisor_review' => 'supervisor', 'faculty_review' => 'faculty', 'university_review' => 'university'][$stage];

        if ($action === 'approve' && $result['final']) {
            $this->notifier->notifyTeam($p, 'project_approved', "\"{$title}\" was approved", $comments, $url, $reviewerId);
        } elseif ($action === 'approve') {
            $this->notifier->notifyTeam($p, 'project_stage_approved', "\"{$title}\" was approved by the {$stageEn}", $comments, $url, $reviewerId);
            if ($result['next'] !== null) {
                $this->notifier->notifyStageTurn($p, $result['next']);
            }
        } elseif ($action === 'reject') {
            $this->notifier->notifyTeam($p, 'project_rejected', "\"{$title}\" was rejected", $comments, $url, $reviewerId);
        } else {
            $this->notifier->notifyTeam($p, 'project_changes_requested', "Changes requested on \"{$title}\"", $comments, $url . '/edit', $reviewerId);
        }

        return true;
    }

    // -- النشر: خطوة منفصلة عن الاعتماد (4.2) ---------------------------------

    /** صاحب المشروع ينشر مشروعه المعتمد للعامة. */
    public function publishAsOwner(string $uuid, $ownerId): bool
    {
        return $this->setPublished($this->projects->findOwnedByUuid($uuid, $ownerId), true, $ownerId, false);
    }

    /** الجامعة تنشر مشروع معتمد تابع لها. */
    public function publishForUniversity(string $uuid, $universityId, $userId): bool
    {
        return $this->setPublished($this->projects->findForUniversityByUuid($uuid, $universityId), true, $userId, true);
    }

    /** الجامعة تسحب مشروع منشور (يرجع معتمد). */
    public function unpublishForUniversity(string $uuid, $universityId, $userId): bool
    {
        return $this->setPublished($this->projects->findForUniversityByUuid($uuid, $universityId), false, $userId, true);
    }

    /** approved ⇄ published بـ UPDATE شرطي واحد (آمن مع التزامن). */
    private function setPublished(?Project $project, bool $publish, $actorId, bool $notifyTeam): bool
    {
        if (!$project) {
            return false;
        }
        $from = $publish ? 'approved' : 'published';
        if ($project->status !== $from) {
            return false;
        }
        if ($publish) {
            $this->projects->ensureSlug($project);
        }
        $changed = DB::table('projects')->where('id', $project->id)->where('status', $from)->update([
            'status'       => $publish ? 'published' : 'approved',
            'published_at' => $publish ? now() : null,
            'updated_at'   => now(),
        ]);
        if ($changed !== 1) {
            return false;
        }

        $this->auditLog->record($actorId, $publish ? 'project.publish' : 'project.unpublish', 'Project', (int) $project->id, ['status' => $from], ['status' => $publish ? 'published' : 'approved']);
        if ($notifyTeam) {
            $title = $project->title_en ?: $project->title_ar;
            $this->notifier->notifyTeam(
                $project, $publish ? 'project_published' : 'project_unpublished',
                $publish ? "\"{$title}\" was published" : "\"{$title}\" was unpublished", null,
                '/student/projects/' . $project->uuid, $actorId
            );
        }
        return true;
    }

    /**
     * كل اللي المراجع (جامعة/كلية) محتاج يشوفه جنب بيانات المشروع: المشرف، الفريق،
     * الروابط، سجل القرارات (مين قرر وإمتى)، والدرجة الرسمية لو اتحفظت.
     * @return array<string,mixed>
     */
    public function reviewExtras(\App\Models\Project $project): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $team = $db::table('project_team_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.project_id', $project->id)->whereIn('m.status', ['accepted', 'pending'])
            ->orderBy('m.id')
            ->get(['m.id', 'm.role', 'm.status', 'm.member_name', 'm.invited_email', 'm.student_number', 'u.full_name'])
            ->map(fn ($m) => [
                'id' => $m->id, 'role' => $m->role, 'status' => $m->status,
                'display_name' => $m->full_name ?: ($m->member_name ?: $m->invited_email),
                'student_number' => $m->student_number,
            ])->all();

        $links = $db::table('project_links')->where('project_id', $project->id)->orderByDesc('is_primary')
            ->get(['type', 'label', 'url', 'is_primary'])->map(fn ($l) => (array) $l)->all();

        $history = $db::table('project_approvals as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.reviewer_id')
            ->where('a.project_id', $project->id)->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->get(['a.stage', 'a.review_round', 'a.decision', 'a.comments', 'a.decided_at', 'a.created_at', 'u.full_name as reviewer_name'])
            ->map(fn ($h) => (array) $h)->all();

        $grade = $db::table('project_grades as g')
            ->leftJoin('users as u', 'u.id', '=', 'g.graded_by')
            ->where('g.project_id', $project->id)
            ->first(['g.id as grade_id', 'g.total_score', 'g.max_score', 'g.letter_grade', 'g.status', 'g.overall_comments', 'g.graded_at', 'g.revision', 'g.reopened_at', 'g.reopen_reason', 'u.full_name as grader_name']);
        $gradeMembers = $grade ? $db::table('project_grade_members as gm')->join('users as mu', 'mu.id', '=', 'gm.user_id')
            ->where('gm.project_grade_id', $grade->grade_id)->orderBy('gm.id')
            ->get(['gm.user_id', 'mu.full_name', 'gm.adjustment', 'gm.final_percentage', 'gm.letter_grade', 'gm.comments'])->map(fn ($m) => (array) $m)->all() : [];

        return [
            'supervisor_name' => $project->supervisor_name,
            'team'            => $team,
            'links'           => $links,
            'history'         => $history,
            'grade'           => $grade ? (array) $grade : null,
            'grade_members'   => $gradeMembers,
            'flow'            => $this->flowFor($project),
        ];
    }

    private function recordDecision(int $projectId, $reviewerId, string $decision, ?string $comments, bool $aiAcknowledged, ?array $aiSnapshot, string $stage, int $round): void
    {
        ProjectApproval::create([
            'project_id'   => $projectId,
            'reviewer_id'  => $reviewerId,
            'stage'        => $stage,
            'review_round' => $round,
            'decision'     => $decision,
            'comments'     => $comments,
            'decided_at'   => now(),
            'ai_output_acknowledged' => $aiSnapshot !== null && $aiAcknowledged,
            'ai_output_snapshot'     => $aiSnapshot !== null ? json_encode($aiSnapshot, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * درجة الجاهزية/التصنيف التلقائي لمشروع، بالظبط زي ما مراجع شايفها
     * دلوقتي في الطابور — null لو الاتنين لسه ماتحسبوش. بتتجمّد جوه
     * project_approvals وقت القرار (شوف recordDecision()) مش بتترجع.
     */
    private function aiOutputSnapshot(int $projectId): ?array
    {
        $readiness = $this->aiAnalysis->readinessFor($projectId);
        $classification = $this->aiAnalysis->classificationFor($projectId);

        if ($readiness === null && $classification === null) {
            return null;
        }

        return [
            'readiness_score'           => $readiness !== null ? (float) $readiness->overall_score : null,
            'predicted_category'        => $classification->predicted_category ?? null,
            'classification_confidence' => $classification !== null ? (float) $classification->confidence : null,
            'captured_at'               => now()->toDateTimeString(),
        ];
    }

    /**
     * true لو المشروع ده عنده مؤشرات ذكاء اصطناعي المراجع لازم يأكّد إنه
     * شافها قبل ما approve()/reject()/requestChanges() تقبل قرار عليه.
     * الكولر بينادي دي مقدّمًا عشان يعرض رسالة خطأ محددة بدل رسالة عامة.
     */
    public function requiresAiAcknowledgment(string $uuid): bool
    {
        $project = $this->projects->findByUuid($uuid);
        return $project !== null && $this->aiOutputSnapshot((int) $project->id) !== null;
    }

    // -- Admin: إشراف على مستوى المنصة كلها + override استثنائي --------------

    /**
     * كل مشروع عبر كل الجامعات وصل لمرحلة مراجعة الجامعة على الأقل، بالشكل
     * اللي جدول Admin oversight محتاجه. إشراف قراءة-فقط — القرار العادي
     * فاضل عند الجامعة (شوف docblock الكلاس)؛ الأدمن بيتصرف بس عبر
     * override() تحت.
     * @return array<int,array<string,mixed>>
     */
    public function platformOversight(): array
    {
        return $this->shapeOversightRows($this->projects->forAdminOversight());
    }

    /**
     * نسخة مرقّمة صفحات من platformOversight()، عشان صفحة Admin > Approval
     * Workflows تحمّل صفحة واحدة (+ إجمالي رخيص) بدل الطابور كله.
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function platformOversightPaginated(string $search, int $page, int $perPage): array
    {
        $result = $this->projects->paginateAdminOversight($search, $page, $perPage);
        return ['rows' => $this->shapeOversightRows($result['rows']), 'total' => $result['total']];
    }

    /** عدادات كروت الإحصاء الرخيصة، من غير ما تحمّل صفوف الطابور. */
    public function platformOversightCounts(): array
    {
        return $this->projects->countAdminOversight();
    }

    private function shapeOversightRows(array $rows): array
    {
        $uiStatusMap = [
            'submitted' => 'pending',
            'approved'  => 'approved',
            'published' => 'approved',
            'rejected'  => 'rejected',
        ];

        return array_map(function ($r) use ($uiStatusMap) {
            $r = (array) $r;
            $title = $r['title_en'] ?: $r['title_ar'];
            return [
                'id'         => $r['uuid'],
                'title'      => ['en' => $r['title_en'] ?: $title, 'ar' => $r['title_ar'] ?: $title],
                'university' => [
                    'en' => $r['university_name_en'] ?: '—',
                    'ar' => $r['university_name_ar'] ?: '—',
                ],
                'status'     => $uiStatusMap[$r['status']] ?? 'pending',
                'rawStatus'  => $r['status'],
                'decided'    => ($r['approved_at'] ?? $r['published_at']) ? date('Y-m-d', strtotime((string) ($r['approved_at'] ?? $r['published_at']))) : '—',
                'published'  => $r['status'] === 'published',
            ];
        }, $rows);
    }

    /**
     * تجاوز استثنائي من الأدمن لحالة مشروع، بيتخطى مسار الاعتماد الإجباري (دكتور ← كلية ← جامعة).
     * دايمًا مسجّل في project_approvals (stage=admin_review) وفي سجل التدقيق العام، ودايمًا محتاج
     * سبب مكتوب. الحالات المسموحة: approved (معتمد من غير نشر)، published (اعتماد + نشر)،
     * rejected، draft (رجوع للطالب).
     */
    public function override(string $uuid, $adminId, string $newStatus, string $comments): bool
    {
        if (!in_array($newStatus, ['approved', 'published', 'rejected', 'draft'], true) || trim($comments) === '') {
            return false;
        }

        $ok = DB::transaction(function () use ($uuid, $adminId, $newStatus, $comments) {
            $project = Project::where('uuid', $uuid)->lockForUpdate()->first();
            if (!$project) {
                return false;
            }
            $oldStatus = $project->status;
            $project->status = $newStatus;
            if (in_array($newStatus, ['approved', 'published'], true)) {
                $project->approved_at = $project->approved_at ?: now();
            } else {
                $project->approved_at = null;
            }
            if ($newStatus === 'published') {
                $this->projects->ensureSlug($project);
                $project->published_at = now();
            } else {
                $project->published_at = null;
            }
            $project->save();

            $decision = in_array($newStatus, ['approved', 'published'], true) ? 'approved' : ($newStatus === 'rejected' ? 'rejected' : 'changes_requested');
            $snapshot = $this->aiOutputSnapshot((int) $project->id);
            ProjectApproval::create([
                'project_id'   => (int) $project->id,
                'reviewer_id'  => $adminId,
                'stage'        => ApprovalFlow::ADMIN,
                'review_round' => max(1, (int) $project->review_round),
                'decision'     => $decision,
                'comments'     => $comments,
                'decided_at'   => now(),
                // السبب المكتوب الإلزامي هو نفسه دليل المراجعة البشرية هنا.
                'ai_output_acknowledged' => $snapshot !== null ? 1 : 0,
                'ai_output_snapshot'     => $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
            ]);

            $this->auditLog->record($adminId, 'project.admin_override', 'Project', (int) $project->id,
                ['status' => $oldStatus], ['status' => $newStatus, 'reason' => $comments]);
            Log::info('Admin overrode project status', ['project_id' => $project->id, 'from' => $oldStatus, 'to' => $newStatus]);
            return true;
        });

        return (bool) $ok;
    }

    /**
     * آخر قرار مراجع على مشروع، لبانر التغذية الراجعة اللي بيشوفه الطالب
     * (سبب الرفض/التعديلات المطلوبة قبل ما يعيد التقديم) — كان القرار
     * بيتسجل (recordDecision()) بس عمره ما كان بيرجع للطالب. مقيّدة
     * بالملكية: بس صاحب المشروع نفسه يقدر يشوفها.
     * @return array{decision:string,comments:?string,decided_at:string}|null
     */
    public function latestDecisionForOwner(string $uuid, $ownerId): ?array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return null;
        }
        $row = ProjectApproval::where('project_id', $project->id)->orderByDesc('created_at')->first();
        if (!$row) {
            return null;
        }
        return [
            'decision'   => $row->decision,
            'stage'      => $row->stage,
            'comments'   => $row->comments,
            'decided_at' => $row->decided_at,
        ];
    }

    /** سجل القرارات + الدرجة النهائية (final بس) للطالب المالك/عضو الفريق. */
    public function feedbackForProject(\App\Models\Project $project, ?int $viewerId = null): array
    {
        $x = $this->reviewExtras($project);
        $grade = $x['grade'];
        $myGrade = null;
        if ($grade && ($grade['status'] ?? '') !== 'final') {
            $grade = null; // الدرجة المسودة (أو المعاد فتحها) متتعرضش للطالب
        } elseif ($grade && $viewerId !== null) {
            // درجة العضو الفردية بس (مش درجات زمايله): النسبة والحرف وسبب التعديل لو فيه.
            foreach ($x['grade_members'] as $m) {
                if ((int) $m['user_id'] === $viewerId) {
                    $myGrade = ['final_percentage' => $m['final_percentage'], 'letter_grade' => $m['letter_grade'], 'adjustment' => $m['adjustment'], 'comments' => (float) $m['adjustment'] !== 0.0 ? $m['comments'] : null];
                }
            }
        }
        if ($grade) {
            unset($grade['grade_id'], $grade['reopen_reason'], $grade['reopened_at']);
        }
        return ['history' => $x['history'], 'grade' => $grade, 'my_grade' => $myGrade, 'supervisor_name' => $x['supervisor_name'], 'flow' => $x['flow']];
    }

    private function lastDecision(int $projectId): ?string
    {
        $row = ProjectApproval::where('project_id', $projectId)->orderByDesc('created_at')->first();
        return $row->decision ?? null;
    }

    private function toQueueRow(array $r, ?array $flow = null): array
    {
        $title = $r['title_en'] ?: $r['title_ar'];

        $uiStatusMap = [
            'submitted' => 'pending',
            'approved'  => 'approved',
            'published' => 'approved', // معتمد؛ هل اتنشر ولا لأ في الـ flag 'published'
            'rejected'  => 'rejected',
            'draft'     => 'changes_requested', // معمول الوصول لها هنا بس عبر الشرط فوق
        ];

        $readiness = $this->aiAnalysis->readinessFor((int) $r['id']);
        $classification = $this->aiAnalysis->classificationFor((int) $r['id']);

        return [
            'id'         => $r['uuid'],
            'title'      => ['en' => $r['title_en'] ?: $title, 'ar' => $r['title_ar'] ?: $title],
            'student'    => ['en' => $r['owner_name'], 'ar' => $r['owner_name']],
            'faculty'    => ['en' => $r['owner_faculty'] ?: '—', 'ar' => $r['owner_faculty'] ?: '—'],
            'supervisor' => ['en' => $r['supervisor_name'] ?: '—', 'ar' => $r['supervisor_name'] ?: '—'],
            'score'      => $readiness !== null ? (float) $readiness->overall_score : null,
            'category'   => $r['category'] ?: null,
            'predictedCategory'        => $classification->predicted_category ?? null,
            'classificationConfidence' => $classification !== null ? (float) $classification->confidence : null,
            'submitted'  => $r['created_at'] ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—',
            'status'     => $uiStatusMap[$r['status']] ?? 'pending',
            'published'  => $r['status'] === 'published',
            'awaiting'   => $flow['awaiting'] ?? null,
            'stages'     => $flow['stages'] ?? [],
            'supervisorUnlinked' => $flow['supervisor_unlinked'] ?? false,
        ];
    }
}
