<?php

namespace App\Repositories;

use App\Models\StudentGroup;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة من app/Repositories/StudentGroupRepository.php القديمة —
 * كانت أول الأمر (بند 4) منقولة جزئيًا (findOwned() بس)، دلوقتي مع بند 16
 * (Groups) اتضاف باقيها: findByName/forUniversityWithCounts/create/
 * memberCount/members. Core\Database::fetchAll/fetchValue -> DB facade.
 */
class StudentGroupRepository
{
    public function find($id): ?StudentGroup
    {
        return StudentGroup::find($id);
    }

    /** Ownership-checked lookup — كل تعديل بيعدي من هنا عشان جامعة متقدرش تلمس مجموعة جامعة تانية. */
    public function findOwned($id, $universityId): ?StudentGroup
    {
        $group = StudentGroup::find($id);
        if (!$group || (int) $group->university_id !== (int) $universityId) {
            return null;
        }
        return $group;
    }

    public function findByName($universityId, string $name): ?StudentGroup
    {
        return StudentGroup::where('university_id', $universityId)->where('name', $name)->first();
    }

    /**
     * كل مجموعات جامعة مع عدد أعضاء حي — الشكل اللي صفحة الطلاب (panel
     * المجموعات) و select "move to group" محتاجينه.
     * @return array<int,array<string,mixed>>
     */
    public function forUniversityWithCounts($universityId): array
    {
        return DB::table('student_groups as g')
            ->select('g.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.group_id', 'g.id');
            }, 'members_count')
            ->where('g.university_id', $universityId)
            ->orderBy('g.name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function create(array $data): StudentGroup
    {
        return StudentGroup::create($data);
    }

    /** عدد الأعضاء الحي لمجموعة — بيتستخدم عشان نطبّق max_members قبل نقل مجموعة طلاب. */
    public function memberCount($groupId): int
    {
        return (int) DB::table('students')->where('group_id', $groupId)->count();
    }

    /**
     * @return array<int,array<string,mixed>> أعضاء مجموعة واحدة، متربطة
     *         باسم/إيميل حسابهم. user_id (اللي كل عضو بيسجل دخول بيه، لازم
     *         لـ messaging/assignee lookups) متضمن جنب id بتاع صف الطالب نفسه.
     */
    public function members($groupId): array
    {
        return DB::table('students as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select('s.id', 's.student_number', 's.user_id', 'u.full_name', 'u.email')
            ->where('s.group_id', $groupId)
            ->orderBy('u.full_name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
