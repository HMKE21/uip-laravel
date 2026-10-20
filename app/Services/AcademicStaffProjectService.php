<?php

namespace App\Services;

use App\Models\AcademicStaff;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Student projects visible to a logged-in academic staff member (doctor or TA).
 *
 * A project is "theirs" when either:
 *   1. projects.supervisor_name matches the staff member's full name (this is the field students fill in
 *      as "academic supervisor" -> treated as the DOCTOR link, grade is OFFICIAL), or
 *   2. the staff member is on project_team_members (by user_id, or by typed name for manual members)
 *      with role professor / principal_investigator / supervisor (OFFICIAL) or teaching_assistant (ADVISORY).
 *
 * Both link types may approve / reject / request changes and may read everything on the project.
 * Only OFFICIAL links write project_grades; ADVISORY links write project_advisory_grades.
 */
class AcademicStaffProjectService
{
    private const OFFICIAL_ROLES = \App\Support\ApprovalFlow::OFFICIAL_ROLES;
    private const LINK_ROLES = ['professor', 'principal_investigator', 'supervisor', 'teaching_assistant'];
    private const LETTERS = [[90, 'A'], [80, 'B'], [70, 'C'], [60, 'D'], [0, 'F']];

    public function __construct(
        private ProjectApprovalService $approvals,
        private ProjectGradingService $grading
    ) {
    }

    public function staffForUser(int $userId): ?AcademicStaff
    {
        return AcademicStaff::where('user_id', $userId)->where('status', 'active')->first();
    }

    private function normName(?string $n): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $n)));
    }

    /** @return array<int,string> projectId => 'supervisor'|'professor'|...|'teaching_assistant' */
    private function linkMap(AcademicStaff $staff, string $fullName): array
    {
        $name = $this->normName($fullName);
        $map = [];

        $rows = DB::table('projects')->where('university_id', $staff->university_id)
            ->whereNotNull('supervisor_name')->where('supervisor_name', '!=', '')
            ->get(['id', 'supervisor_name']);
        foreach ($rows as $r) {
            if ($name !== '' && \App\Support\PersonName::matches($r->supervisor_name, $fullName)) {
                $map[(int) $r->id] = 'supervisor';
            }
        }

        $members = DB::table('project_team_members as m')
            ->join('projects as p', 'p.id', '=', 'm.project_id')
            ->where('p.university_id', $staff->university_id)
            ->where('m.status', 'accepted')->whereIn('m.role', self::LINK_ROLES)
            ->where(function ($q) use ($staff, $name) {
                $q->where('m.user_id', $staff->user_id)
                  ->orWhereRaw('LOWER(TRIM(m.member_name)) = ?', [$name]);
            })
            ->get(['m.project_id', 'm.role']);
        foreach ($members as $m) {
            $pid = (int) $m->project_id;
            // official link wins over advisory when both exist
            if (!isset($map[$pid]) || (!in_array($map[$pid], self::OFFICIAL_ROLES, true) && in_array($m->role, self::OFFICIAL_ROLES, true))) {
                $map[$pid] = $m->role;
            }
        }
        return $map;
    }

    public function isOfficial(?string $linkRole): bool
    {
        return in_array($linkRole, self::OFFICIAL_ROLES, true);
    }

    /** @return array<int,array<string,mixed>> */
    public function projectsFor(AcademicStaff $staff, string $fullName): array
    {
        $links = $this->linkMap($staff, $fullName);
        if (empty($links)) {
            return [];
        }
        $ids = array_keys($links);
        $rows = DB::table('projects as p')
            ->join('users as u', 'u.id', '=', 'p.owner_id')
            ->leftJoin('students as s', 's.user_id', '=', 'p.owner_id')
            ->whereIn('p.id', $ids)
            ->selectRaw("p.id, p.uuid, p.owner_id, p.university_id, p.review_round, p.supervisor_name, p.title_en, p.title_ar, p.status, p.created_at, p.updated_at, u.full_name AS owner_name, s.faculty AS owner_faculty,
                (SELECT pa.decision FROM project_approvals pa WHERE pa.project_id = p.id ORDER BY pa.created_at DESC, pa.id DESC LIMIT 1) AS last_decision,
                (SELECT g.status FROM project_grades g WHERE g.project_id = p.id LIMIT 1) AS official_grade_status")
            ->orderByDesc('p.updated_at')->get();

        $flows = $this->approvals->flowSummaries($rows->map(fn ($r) => (array) $r)->all());

        $out = [];
        foreach ($rows as $r) {
            // a draft only matters to staff when it came back from a "request changes"
            if ($r->status === 'draft' && $r->last_decision !== 'changes_requested') {
                continue;
            }
            $link = $links[(int) $r->id];
            $out[] = [
                'uuid' => $r->uuid, 'title_en' => $r->title_en, 'title_ar' => $r->title_ar,
                'status' => $r->status, 'last_decision' => $r->last_decision,
                'owner_name' => $r->owner_name, 'owner_faculty' => $r->owner_faculty,
                'link_role' => $link, 'is_official' => $this->isOfficial($link),
                'awaiting' => $flows[(int) $r->id]['awaiting'] ?? null,
                'official_grade_status' => $r->official_grade_status,
                'updated_at' => $r->updated_at,
            ];
        }
        return $out;
    }

    /** @return array{0:?Project,1:?string} [project, linkRole] — null project when out of scope. */
    public function scopedProject(AcademicStaff $staff, string $fullName, string $uuid): array
    {
        $project = Project::where('uuid', $uuid)->where('university_id', $staff->university_id)->first();
        if (!$project) {
            return [null, null];
        }
        $links = $this->linkMap($staff, $fullName);
        return isset($links[(int) $project->id]) ? [$project, $links[(int) $project->id]] : [null, null];
    }

    /** @throws \App\Exceptions\ApprovalException لو المعيد (ربط استشاري) أو مش دور المشرف في التسلسل. */
    public function decide(string $uuid, int $userId, string $action, ?string $comments, bool $ack, ?string $linkRole): bool
    {
        return $this->approvals->decideAsStaff($uuid, $userId, $action, $comments, $ack, $linkRole);
    }

    /** مسار الاعتماد للمشروع (المراحل + المرحلة المنتظرة). */
    public function flowFor(Project $project): array
    {
        return $this->approvals->flowFor($project);
    }

    public function requiresAck(string $uuid): bool
    {
        return $this->approvals->requiresAiAcknowledgment($uuid);
    }

    // -- grades ---------------------------------------------------------------

    /** إعادة فتح الدرجة الرسمية (الدكتور المشرف بس، ربط رسمي). */
    public function reopenGrade(Project $project, int $userId, string $reason, string $locale = 'en'): array
    {
        return $this->grading->reopen((int) $project->id, $userId, 'supervisor', $reason, $locale);
    }

    public function gradesFor(Project $project, int $userId): array
    {
        $official = $this->grading->forProject((int) $project->id);
        $advisory = DB::table('project_advisory_grades as a')
            ->join('users as u', 'u.id', '=', 'a.graded_by')
            ->where('a.project_id', $project->id)
            ->get(['a.*', 'u.full_name as grader_name']);

        $mine = null;
        $others = [];
        foreach ($advisory as $a) {
            $row = [
                'grader_name' => $a->grader_name, 'total_score' => $a->total_score, 'max_score' => $a->max_score,
                'letter_grade' => $a->letter_grade, 'status' => $a->status,
                'overall_comments' => $a->overall_comments,
                'criteria' => json_decode((string) $a->criteria, true) ?: [],
            ];
            if ((int) $a->graded_by === $userId) {
                $mine = $row;
            } else {
                $others[] = $row;
            }
        }
        return [
            'official' => $official['grade']?->toArray(),
            'official_criteria' => $official['criteria'],
            'members' => $this->grading->memberGrades((int) $project->id),
            'member_candidates' => $this->grading->memberCandidates($project),
            'revisions' => $this->grading->revisions((int) $project->id),
            'mine_advisory' => $mine,
            'other_advisory' => $others,
        ];
    }

    /** Official -> ProjectGradingService; advisory -> project_advisory_grades. */
    public function saveGrade(Project $project, int $userId, bool $official, array $criteria, ?string $comments, bool $finalize, string $locale = 'en', ?array $members = null): array
    {
        if ($official) {
            return $this->grading->saveGrade((int) $project->id, $userId, $criteria, $comments, $finalize, $locale, $members, 'supervisor');
        }
        if (!in_array($project->status, ProjectGradingService::GRADABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'This project cannot be graded in its current status.'];
        }

        $clean = [];
        $total = 0.0;
        $max = 0.0;
        foreach ($criteria as $row) {
            $name = trim((string) ($row['criterion'] ?? ''));
            $mx = $row['max_score'] ?? null;
            $sc = $row['score'] ?? null;
            if ($name === '' && ($mx === null || $mx === '')) {
                continue;
            }
            if ($name === '' || !is_numeric($mx) || (float) $mx <= 0) {
                return ['success' => false, 'message' => 'Every rubric criterion needs a name and a max score greater than zero.'];
            }
            $score = null;
            if ($sc !== null && $sc !== '') {
                if (!is_numeric($sc) || (float) $sc < 0 || (float) $sc > (float) $mx) {
                    return ['success' => false, 'message' => "The score for \"{$name}\" is invalid (must be between 0 and its max score)."];
                }
                $score = (float) $sc;
                $total += $score;
            } elseif ($finalize) {
                return ['success' => false, 'message' => "Every criterion needs a score before finalizing (\"{$name}\" has none)."];
            }
            $max += (float) $mx;
            $clean[] = ['criterion' => $name, 'max_score' => (float) $mx, 'score' => $score, 'comments' => ($row['comments'] ?? '') !== '' ? $row['comments'] : null];
        }
        if (empty($clean)) {
            return ['success' => false, 'message' => 'Add at least one rubric criterion.'];
        }

        $hasScore = collect($clean)->contains(fn ($c) => $c['score'] !== null);
        $pct = $max > 0 && $hasScore ? ($total / $max) * 100 : null;
        $letter = null;
        if ($pct !== null) {
            foreach (self::LETTERS as [$min, $l]) {
                if ($pct >= $min) { $letter = $l; break; }
            }
        }

        $data = [
            'total_score' => $hasScore ? round($total, 2) : null, 'max_score' => round($max, 2), 'letter_grade' => $letter,
            'status' => $finalize ? 'final' : 'draft', 'overall_comments' => $comments !== '' ? $comments : null,
            'criteria' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'graded_at' => $finalize ? now() : null, 'updated_at' => now(),
        ];
        $key = ['project_id' => $project->id, 'graded_by' => $userId];
        if (DB::table('project_advisory_grades')->where($key)->exists()) {
            DB::table('project_advisory_grades')->where($key)->update($data);
        } else {
            DB::table('project_advisory_grades')->insert($key + $data + ['created_at' => now()]);
        }

        return ['success' => true, 'message' => $finalize ? 'Advisory grade submitted.' : 'Advisory grade saved as a draft.'];
    }

    /** Everything a reviewer needs to see about a project, besides grades. */
    public function approvalHistory(Project $project): array
    {
        return DB::table('project_approvals as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.reviewer_id')
            ->where('a.project_id', $project->id)
            ->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->get(['a.decision', 'a.stage', 'a.comments', 'a.decided_at', 'a.created_at', 'u.full_name as reviewer_name'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
