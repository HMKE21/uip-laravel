<?php

namespace App\Repositories;

use App\Models\ProjectGrade;
use App\Models\ProjectGradeCriterion;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ProjectGradeRepository.php القديمة —
 * بند 14 (Graduation). project_grade_criteria بتتستبدل بالكامل في كل
 * save() بدل ما تتقارن سطر سطر، لأن فورم التقييم دايمًا بيبعت الـ rubric
 * كله من جديد — نفس تعليق replaceCriteria() القديم بالظبط.
 */
class ProjectGradeRepository
{
    public function findByProject($projectId): ?ProjectGrade
    {
        return ProjectGrade::where('project_id', $projectId)->first();
    }

    public function find($id): ?ProjectGrade
    {
        return ProjectGrade::find($id);
    }

    /** @return \Illuminate\Support\Collection<int,ProjectGradeCriterion> */
    public function criteriaFor($gradeId)
    {
        return ProjectGradeCriterion::where('project_grade_id', $gradeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): ProjectGrade
    {
        return ProjectGrade::create($data);
    }

    public function replaceCriteria($gradeId, array $rows): void
    {
        ProjectGradeCriterion::where('project_grade_id', $gradeId)->delete();
        $order = 0;
        foreach ($rows as $row) {
            ProjectGradeCriterion::create([
                'project_grade_id' => $gradeId,
                'criterion'        => $row['criterion'],
                'max_score'        => $row['max_score'],
                'score'            => $row['score'],
                'comments'         => $row['comments'] ?? null,
                'sort_order'       => $order++,
            ]);
        }
    }

    /**
     * الدرجات النهائية لطالب (users.id): مشاريع هو مالكها أو عضو طالب مقبول فيها. لو العضو ليه صف
     * فردي (project_grade_members) النتيجة الفردية (الحرف والنسبة) هي اللي بترجع بنفس مفاتيح
     * letter_grade/total_score، وإلا درجة المشروع نفسها. التخرج بيقرا من هنا.
     */
    public function finalGradesForUser(int $userId): array
    {
        $rows = DB::table('project_grades as pg')
            ->join('projects as p', 'p.id', '=', 'pg.project_id')
            ->leftJoin('project_team_members as m', function ($j) use ($userId) {
                $j->on('m.project_id', '=', 'p.id')->where('m.user_id', '=', $userId)->where('m.status', '=', 'accepted')
                  ->whereIn('m.role', ['student_member', 'collaborator']);
            })
            ->leftJoin('project_grade_members as gm', function ($j) use ($userId) {
                $j->on('gm.project_grade_id', '=', 'pg.id')->where('gm.user_id', '=', $userId);
            })
            ->where('pg.status', 'final')
            ->where(fn ($q) => $q->where('p.owner_id', $userId)->orWhereNotNull('m.id'))
            ->select('pg.*', 'p.owner_id', 'p.title_ar', 'p.title_en', 'gm.adjustment', 'gm.final_percentage', 'gm.letter_grade as member_letter', 'gm.comments as member_comments')
            ->orderByDesc('pg.graded_at')->orderByDesc('pg.id')
            ->get()->map(fn ($r) => (array) $r)->all();

        return array_map(function (array $r) {
            $r['project_letter_grade'] = $r['letter_grade'];
            if ($r['final_percentage'] !== null && $r['member_letter'] !== null) {
                $r['letter_grade'] = $r['member_letter'];
                $r['total_score'] = round(((float) $r['final_percentage']) / 100 * (float) $r['max_score'], 2);
            }
            return $r;
        }, $rows);
    }

    /**
     * الدرجات النهائية (status='final') لمجموعة owner_id (users.id) —
     * GraduationService::checkEligibility()/transcriptFor() بتستخدمها
     * عشان تلاقي أحدث تقييم نهائي معتمد لمشروع تخرج الطالب.
     */
    public function finalGradesForOwners(array $ownerUserIds): array
    {
        if (empty($ownerUserIds)) {
            return [];
        }

        return DB::table('project_grades as pg')
            ->join('projects as p', 'p.id', '=', 'pg.project_id')
            ->select('pg.*', 'p.owner_id', 'p.title_ar', 'p.title_en')
            ->where('pg.status', 'final')
            ->whereIn('p.owner_id', $ownerUserIds)
            ->orderByDesc('pg.graded_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
