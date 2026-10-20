<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectGradeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/ProjectGradingService.php القديمة — بند 14
 * (Graduation). بتقف وراء فورم "Grade project" بتاعة بورتال المشرف: صف
 * project_grades (migration 108) + rubric حر project_grade_criteria
 * المشرف بيعرّفه بنفسه لكل مشروع (مفيش rubric ثابت على مستوى المنصة).
 *
 * المشرف يقدر يحفظ 'draft' أي عدد مرات وهو لسه شغال عليه؛ 'final' بتقفل
 * graded_at وتبلّغ الطالب — نفس شكل ProjectApprovalService::approve()/
 * reject() بالظبط (سجل + audit log + notify).
 *
 * عمدًا مش بتلمس students.gpa — تجميع المشاريع المُقيّمة في GPA حقيقي
 * شغلانة تانية أكبر (فجوة الـ GPA في تدقيق الـ spec) مش هنا.
 */
class ProjectGradingService
{
    /** نفس السلم القديم بالظبط — مش قابل للتخصيص لكل جامعة لسه. */
    private const LETTER_SCALE = [
        ['min' => 90, 'letter' => 'A'],
        ['min' => 80, 'letter' => 'B'],
        ['min' => 70, 'letter' => 'C'],
        ['min' => 60, 'letter' => 'D'],
        ['min' => 0,  'letter' => 'F'],
    ];

    /** المشروع يتقيّم بس لو عدّى فعليًا بمراجعة — عمره ما يبقى مسودة لسه ما اتقدمتش. */
    public const GRADABLE_STATUSES = ['submitted', 'under_review', 'approved', 'published', 'rejected'];

    public function __construct(
        private ProjectGradeRepository $grades,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private ProjectNotifier $notifier
    ) {
    }

    /**
     * التقييم + الـ rubric لمشروع واحد، بالشكل اللي فورم التقييم محتاجه.
     * لو المشروع ما اتقيّمش قبل كده، بترجع تقييم null + سطر معيار فاضي
     * واحد (فورم فاضي).
     */
    public function forProject(int $projectId): array
    {
        $grade = $this->grades->findByProject($projectId);
        if (!$grade) {
            return [
                'grade'    => null,
                'criteria' => [['criterion' => '', 'max_score' => '', 'score' => '', 'comments' => '']],
            ];
        }

        $criteria = $this->grades->criteriaFor($grade->id)->map(fn ($c) => [
            'criterion' => $c->criterion,
            'max_score' => $c->max_score,
            'score'     => $c->score,
            'comments'  => $c->comments,
        ])->all();

        return ['grade' => $grade, 'criteria' => $criteria];
    }

    /**
     * @param array<int,array{criterion:string,max_score:mixed,score:mixed,comments:?string}> $criteria
     * @return array{success:bool, message:string}
     */
    public function saveGrade(
        int $projectId,
        $graderUserId,
        array $criteria,
        ?string $overallComments,
        bool $finalize,
        string $locale = 'ar',
        ?array $members = null,
        string $actorRole = 'supervisor'
    ): array {
        $project = Project::find($projectId);
        if (!$project || !in_array($project->status, self::GRADABLE_STATUSES, true)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يمكن تقييم هذا المشروع في حالته الحالية.'
                : 'This project cannot be graded in its current status.'];
        }

        $existing = $this->grades->findByProject($projectId);
        // 5.3: الدرجة النهائية مقفولة. التعديل بعد الاعتماد بيعدي على reopen() بسبب مكتوب، مش حفظ عادي.
        if ($existing && $existing->status === 'final') {
            return ['success' => false, 'code' => 'grade_locked', 'message' => $locale === 'ar'
                ? 'الدرجة النهائية مقفولة. أعد فتحها بسبب مكتوب أولًا لو محتاج تعدّلها.'
                : 'This grade is finalized and locked. Reopen it with a written reason before changing it.'];
        }
        // بعد إعادة الفتح (reopen) الدرجة بتبقى draft ويتعدّل عليها عادي، والاعتماد النهائي بيقفلها تاني
        // ويسجّل revision جديد.

        $clean = [];
        $totalScore = 0.0;
        $totalMax = 0.0;
        $hasAnyScore = false;

        foreach ($criteria as $row) {
            $criterion = trim((string) ($row['criterion'] ?? ''));
            $maxScore = $row['max_score'] ?? null;
            $score = $row['score'] ?? null;

            if ($criterion === '' && ($maxScore === null || $maxScore === '')) {
                continue; // سطر فاضي في آخر الفورم — يتجاهل بهدوء
            }
            if ($criterion === '' || $maxScore === null || $maxScore === '' || !is_numeric($maxScore) || (float) $maxScore <= 0) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'كل معيار تقييم يحتاج اسم ودرجة عظمى أكبر من صفر.'
                    : 'Every rubric criterion needs a name and a max score greater than zero.'];
            }
            $maxScore = (float) $maxScore;

            $scoreValue = null;
            if ($score !== null && $score !== '') {
                if (!is_numeric($score) || (float) $score < 0 || (float) $score > $maxScore) {
                    return ['success' => false, 'message' => $locale === 'ar'
                        ? "الدرجة المدخلة لمعيار \"{$criterion}\" غير صحيحة (يجب أن تكون بين 0 والدرجة العظمى)."
                        : "The score for \"{$criterion}\" is invalid (must be between 0 and its max score)."];
                }
                $scoreValue = (float) $score;
                $hasAnyScore = true;
                $totalScore += $scoreValue;
            }
            $totalMax += $maxScore;

            $clean[] = [
                'criterion' => $criterion,
                'max_score' => $maxScore,
                'score'     => $scoreValue,
                'comments'  => ($row['comments'] ?? '') !== '' ? $row['comments'] : null,
            ];
        }

        if (empty($clean)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'أضف معيار تقييم واحد على الأقل.'
                : 'Add at least one rubric criterion.'];
        }

        if ($finalize && !$hasAnyScore) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يمكن اعتماد التقييم بدون إدخال درجات.'
                : "A grade can't be finalized without any scores entered."];
        }
        if ($finalize) {
            foreach ($clean as $row) {
                if ($row['score'] === null) {
                    return ['success' => false, 'message' => $locale === 'ar'
                        ? "لازم تدخل درجة لكل معيار قبل اعتماد التقييم النهائي (\"{$row['criterion']}\" بدون درجة)."
                        : "Every criterion needs a score before finalizing (\"{$row['criterion']}\" has none)."];
                }
            }
        }

        $percentage = $totalMax > 0 && $hasAnyScore ? ($totalScore / $totalMax) * 100 : null;
        $letter = $percentage !== null ? $this->letterFor($percentage) : null;

        // 5.1: تعديلات الأعضاء الفردية (نقاط مئوية فوق/تحت درجة المشروع) — بتتفحص قبل أي كتابة.
        $memberRows = $this->resolveMemberAdjustments($project, $existing, $members, $locale);
        if (is_string($memberRows)) {
            return ['success' => false, 'message' => $memberRows];
        }

        $gradeData = [
            'project_id'       => $projectId,
            'graded_by'        => $graderUserId,
            'total_score'      => $hasAnyScore ? round($totalScore, 2) : null,
            'max_score'        => round($totalMax, 2),
            'letter_grade'     => $letter,
            'status'           => $finalize ? 'final' : 'draft',
            'overall_comments' => $overallComments !== '' ? $overallComments : null,
            'graded_at'        => $finalize ? now() : ($existing->graded_at ?? null),
        ];
        if ($finalize) {
            $gradeData['revision'] = (int) ($existing->revision ?? 0) + 1;
            $gradeData['reopened_by'] = null;
            $gradeData['reopened_at'] = null;
            $gradeData['reopen_reason'] = null;
        }

        $before = $existing ? $existing->toArray() : null;
        $finalMembers = [];
        DB::transaction(function () use (&$existing, $gradeData, $clean, $memberRows, $percentage, $graderUserId, $finalize, $actorRole, $projectId, &$gradeId, &$finalMembers) {
            if ($existing) {
                $existing->fill($gradeData);
                $existing->save();
                $gradeId = (int) $existing->id;
            } else {
                $gradeId = (int) $this->grades->create($gradeData)->id;
            }
            $this->grades->replaceCriteria($gradeId, $clean);
            $finalMembers = $this->storeMemberGrades($gradeId, $projectId, $memberRows, $percentage, $graderUserId);

            if ($finalize) {
                $this->recordRevision($gradeId, $projectId, (int) $gradeData['revision'], 'finalized', $graderUserId, $actorRole, null, $gradeData, $clean, $finalMembers);
            }
        });

        $this->auditLog->record($graderUserId, 'supervisor.project_grade_save', 'Project', $projectId, $before, array_merge($gradeData, [
            'criteria_count' => count($clean),
        ]));
        Log::info('Project grade saved', ['project_id' => $projectId, 'grader_id' => $graderUserId, 'finalized' => $finalize]);

        if ($finalize) {
            $title = $project->title_en ?: $project->title_ar;
            $scoreLabel = $hasAnyScore ? (round($totalScore, 1) . '/' . round($totalMax, 1) . ($letter ? " ({$letter})" : '')) : '';
            $this->notifier->notifyTeam(
                $project,
                'project_graded',
                $locale === 'ar' ? "تم تقييم مشروعك \"{$title}\"" : "Your project \"{$title}\" was graded",
                $locale === 'ar'
                    ? ($scoreLabel !== '' ? "الدرجة: {$scoreLabel}." : 'راجع تفاصيل التقييم من صفحة المشروع.')
                    : ($scoreLabel !== '' ? "Score: {$scoreLabel}." : 'See the project page for the full breakdown.'),
                '/student/projects/' . $project->uuid,
                $graderUserId
            );
            // عضو درجته الفردية مختلفة عن درجة المشروع: إشعار شخصي بدرجته.
            foreach ($finalMembers as $m) {
                if ((float) $m['adjustment'] !== 0.0 && (int) $m['user_id'] !== (int) $graderUserId && $m['letter_grade'] !== null) {
                    $this->notifications->notify((int) $m['user_id'], 'project_graded_individual',
                        $locale === 'ar' ? "درجتك الفردية في مشروع \"{$title}\"" : "Your individual grade on \"{$title}\"",
                        ($locale === 'ar' ? 'النتيجة: ' : 'Result: ') . round((float) $m['final_percentage'], 1) . '% (' . $m['letter_grade'] . ')',
                        '/student/projects/' . $project->uuid);
                }
            }
        }

        return ['success' => true, 'message' => $finalize
            ? ($locale === 'ar' ? 'تم اعتماد التقييم وإخطار الطالب.' : 'Grade finalized and the student was notified.')
            : ($locale === 'ar' ? 'تم حفظ التقييم كمسودة.' : 'Grade saved as a draft.')];
    }

    // -- 5.1: درجة المشروع + تعديل فردي لكل عضو -------------------------------

    /** الأعضاء اللي ليهم درجة فردية: المالك + أعضاء الفريق الطلبة (بحساب) المقبولين. @return int[] user ids */
    public function gradeMemberIds(Project $project): array
    {
        $ids = [(int) $project->owner_id];
        $rows = DB::table('project_team_members')->where('project_id', $project->id)->where('status', 'accepted')
            ->whereIn('role', ['student_member', 'collaborator'])->whereNotNull('user_id')->pluck('user_id');
        foreach ($rows as $uid) {
            $ids[] = (int) $uid;
        }
        return array_values(array_unique($ids));
    }

    /** أعضاء المشروع اللي ينفع تتحدد لهم درجة فردية، بأسمائهم (لفورم التقييم قبل أول حفظ). */
    public function memberCandidates(Project $project): array
    {
        return DB::table('users')->whereIn('id', $this->gradeMemberIds($project))->orderBy('id')->get(['id as user_id', 'full_name'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * يطبّع مدخلات التعديلات: [user_id => ['adjustment'=>float,'comments'=>?string]] لكل عضو حالي.
     * لو $members === null بيحافظ على التعديلات المحفوظة. بيرجع string (رسالة خطأ) لو فيه مشكلة.
     * @return array<int,array{adjustment:float,comments:?string}>|string
     */
    private function resolveMemberAdjustments(Project $project, $existing, ?array $members, string $locale)
    {
        $allowed = $this->gradeMemberIds($project);
        $current = [];
        if ($existing) {
            foreach (DB::table('project_grade_members')->where('project_grade_id', $existing->id)->get() as $r) {
                $current[(int) $r->user_id] = ['adjustment' => (float) $r->adjustment, 'comments' => $r->comments];
            }
        }
        $out = [];
        foreach ($allowed as $uid) {
            $out[$uid] = $current[$uid] ?? ['adjustment' => 0.0, 'comments' => null];
        }
        foreach ($members ?? [] as $m) {
            $uid = (int) ($m['user_id'] ?? 0);
            if (!isset($out[$uid])) {
                return $locale === 'ar' ? 'هذا المستخدم ليس عضوًا طالبًا في المشروع.' : 'That user is not a student member of this project.';
            }
            $adj = $m['adjustment'] ?? 0;
            if ($adj === '' || $adj === null) {
                $adj = 0;
            }
            if (!is_numeric($adj) || (float) $adj < -100 || (float) $adj > 100) {
                return $locale === 'ar' ? 'التعديل الفردي لازم يكون رقم بين -100 و100.' : 'The individual adjustment must be a number between -100 and 100.';
            }
            $comment = trim((string) ($m['comments'] ?? ''));
            if ((float) $adj !== 0.0 && $comment === '') {
                return $locale === 'ar' ? 'اكتب سبب التعديل الفردي لكل عضو درجته مختلفة.' : 'Write a reason for every member whose individual grade differs.';
            }
            $out[$uid] = ['adjustment' => round((float) $adj, 2), 'comments' => $comment !== '' ? mb_substr($comment, 0, 1000) : null];
        }
        return $out;
    }

    /**
     * يكتب صفوف الأعضاء (واحد لكل عضو حالي، والباقي بيتمسح) ويحسب النتيجة الفردية =
     * clamp(نسبة المشروع + التعديل، 0..100) والحرف من نفس السلم.
     * @return array<int,array<string,mixed>>
     */
    private function storeMemberGrades(int $gradeId, int $projectId, array $rows, ?float $percentage, $actorId): array
    {
        DB::table('project_grade_members')->where('project_grade_id', $gradeId)->whereNotIn('user_id', array_keys($rows) ?: [0])->delete();
        $out = [];
        foreach ($rows as $uid => $r) {
            $final = $percentage !== null ? max(0.0, min(100.0, $percentage + $r['adjustment'])) : null;
            $data = [
                'adjustment' => $r['adjustment'], 'comments' => $r['comments'],
                'final_percentage' => $final !== null ? round($final, 2) : null,
                'letter_grade' => $final !== null ? $this->letterFor($final) : null,
                'updated_by' => $actorId, 'project_id' => $projectId,
            ];
            $exists = DB::table('project_grade_members')->where('project_grade_id', $gradeId)->where('user_id', $uid)->exists();
            if ($exists) {
                DB::table('project_grade_members')->where('project_grade_id', $gradeId)->where('user_id', $uid)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('project_grade_members')->insert($data + ['project_grade_id' => $gradeId, 'user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            }
            $out[] = $data + ['user_id' => $uid];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> الدرجات الفردية المحفوظة مع أسماء الأعضاء. */
    public function memberGrades(int $projectId): array
    {
        $grade = $this->grades->findByProject($projectId);
        if (!$grade) {
            return [];
        }
        return DB::table('project_grade_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.project_grade_id', $grade->id)->orderBy('m.id')
            ->get(['m.user_id', 'u.full_name', 'm.adjustment', 'm.final_percentage', 'm.letter_grade', 'm.comments'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** سجل الاعتماد/إعادة الفتح، الأحدث أولًا. */
    public function revisions(int $projectId): array
    {
        return DB::table('project_grade_revisions as r')->leftJoin('users as u', 'u.id', '=', 'r.actor_id')
            ->where('r.project_id', $projectId)->orderByDesc('r.id')
            ->get(['r.revision', 'r.action', 'r.actor_role', 'r.reason', 'r.total_score', 'r.max_score', 'r.letter_grade', 'r.created_at', 'u.full_name as actor_name'])
            ->map(fn ($r) => (array) $r)->all();
    }

    // -- 5.3: قفل الدرجة النهائية + إعادة الفتح بسبب ----------------------------

    /**
     * يعيد فتح درجة نهائية للتعديل. بسبب مكتوب إلزامي، مسجّل (revision + audit) ويوصل للفريق.
     * لو أي عضو اتخرّج فعلًا، الجامعة بس تقدر تفتحها (الدرجة بتأثر على التخرج، ومفيش إلغاء تخرج تلقائي).
     * @param string $actorRole supervisor|faculty|university
     * @return array{success:bool,message:string,code?:string}
     */
    public function reopen(int $projectId, $actorId, string $actorRole, string $reason, string $locale = 'ar'): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'code' => 'reason_required', 'message' => $locale === 'ar' ? 'اكتب سبب إعادة فتح الدرجة.' : 'A written reason is required to reopen a grade.'];
        }
        $project = Project::find($projectId);
        if (!$project) {
            return ['success' => false, 'code' => 'not_found', 'message' => 'Project not found.'];
        }

        $result = DB::transaction(function () use ($project, $projectId, $actorId, $actorRole, $reason, $locale) {
            Project::where('id', $projectId)->lockForUpdate()->first();
            $grade = $this->grades->findByProject($projectId);
            if (!$grade || $grade->status !== 'final') {
                return ['success' => false, 'code' => 'not_final', 'message' => $locale === 'ar' ? 'الدرجة غير معتمدة لتُعاد فتحها.' : 'There is no finalized grade to reopen.'];
            }
            $graduated = DB::table('graduation_records as g')->join('students as s', 's.id', '=', 'g.student_id')
                ->whereIn('s.user_id', $this->gradeMemberIds($project))->where('g.status', 'graduated')->exists();
            if ($graduated && $actorRole !== 'university') {
                return ['success' => false, 'code' => 'graduated_requires_university', 'message' => $locale === 'ar'
                    ? 'أحد أعضاء الفريق تخرّج بالفعل؛ إعادة فتح الدرجة من صلاحية الجامعة فقط.'
                    : 'A team member has already graduated; only the university can reopen this grade.'];
            }
            $members = DB::table('project_grade_members')->where('project_grade_id', $grade->id)->get()->map(fn ($r) => (array) $r)->all();
            $this->recordRevision((int) $grade->id, $projectId, (int) $grade->revision, 'reopened', $actorId, $actorRole, $reason, $grade->toArray(), $this->grades->criteriaFor($grade->id)->toArray(), $members);
            $grade->fill(['status' => 'draft', 'reopened_by' => $actorId, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $grade->save();
            return ['success' => true, 'message' => $locale === 'ar' ? 'تمت إعادة فتح الدرجة للتعديل.' : 'Grade reopened for editing.'];
        });

        if ($result['success']) {
            $this->auditLog->record($actorId, 'project.grade_reopen', 'Project', $projectId, ['status' => 'final'], ['status' => 'draft', 'reason' => $reason, 'by_role' => $actorRole]);
            $title = $project->title_en ?: $project->title_ar;
            $this->notifier->notifyTeam($project, 'project_grade_reopened',
                $locale === 'ar' ? "أُعيد فتح تقييم مشروعك \"{$title}\"" : "The grade for \"{$title}\" was reopened for revision",
                $reason, '/student/projects/' . $project->uuid, $actorId);
        }
        return $result;
    }

    private function recordRevision(int $gradeId, int $projectId, int $revision, string $action, $actorId, string $actorRole, ?string $reason, array $grade, array $criteria, array $members): void
    {
        DB::table('project_grade_revisions')->insert([
            'project_grade_id' => $gradeId, 'project_id' => $projectId, 'revision' => $revision,
            'action' => $action, 'actor_id' => $actorId, 'actor_role' => $actorRole, 'reason' => $reason,
            'total_score' => $grade['total_score'] ?? null, 'max_score' => $grade['max_score'] ?? null, 'letter_grade' => $grade['letter_grade'] ?? null,
            'snapshot' => json_encode(['criteria' => $criteria, 'members' => $members], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function letterFor(float $percentage): string
    {
        foreach (self::LETTER_SCALE as $tier) {
            if ($percentage >= $tier['min']) {
                return $tier['letter'];
            }
        }
        return 'F';
    }
}
