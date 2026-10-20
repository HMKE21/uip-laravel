<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مواعيد التسليم والمناقشة. الأولوية لموعد الكلية (لو فيه) على موعد الجامعة العام،
 * وبيتاخد الأحدث موعدًا جوه نفس المستوى. لو الجدول لسه معموش migration بنرجّع null بدل ما نكسر التقديم.
 */
class ProjectDeadlineService
{
    private function available(): bool
    {
        try {
            return Schema::hasTable('project_deadlines');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** الموعد المنطبق على مشروع (حسب جامعة وكلية صاحبه) أو null. */
    public function forProject(Project $project): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $student = DB::table('students')->where('user_id', $project->owner_id)->first(['university_id', 'faculty_id']);
        $uni = $project->university_id ?: ($student->university_id ?? null);
        if (!$uni) {
            return null;
        }
        return $this->applicable((int) $uni, $student->faculty_id ?? null);
    }

    public function applicable(int $universityId, $facultyId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $base = DB::table('project_deadlines')->where('university_id', $universityId)->where('is_active', 1);
        $row = null;
        if ($facultyId) {
            $row = (clone $base)->where('faculty_id', $facultyId)->orderByDesc('submission_deadline')->orderByDesc('id')->first();
        }
        $row = $row ?: (clone $base)->whereNull('faculty_id')->orderByDesc('submission_deadline')->orderByDesc('id')->first();
        return $row ? (array) $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listFor(int $universityId, $facultyId = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $q = DB::table('project_deadlines')->where('university_id', $universityId);
        if ($facultyId) {
            $q->where('faculty_id', $facultyId);
        }
        return $q->orderByDesc('submission_deadline')->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function create(int $universityId, $facultyId, int $userId, array $data): int
    {
        return (int) DB::table('project_deadlines')->insertGetId([
            'university_id'       => $universityId,
            'faculty_id'          => $facultyId ?: null,
            'title'               => mb_substr(trim((string) $data['title']), 0, 190),
            'submission_deadline' => $data['submission_deadline'] ?: null,
            'defense_starts_at'   => $data['defense_starts_at'] ?: null,
            'defense_ends_at'     => $data['defense_ends_at'] ?: null,
            'is_active'           => 1,
            'created_by'          => $userId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function delete(int $id, int $universityId, $facultyId = null): bool
    {
        $q = DB::table('project_deadlines')->where('id', $id)->where('university_id', $universityId);
        if ($facultyId) {
            $q->where('faculty_id', $facultyId);
        }
        return $q->delete() > 0;
    }
}
