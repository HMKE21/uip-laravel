<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * التقويم الأكاديمي: الكل (طالب/دكتور/كلية/جامعة) بيشوف أحداث الجامعة + أحداث كليته، مضاف لها فترات التسجيل والفصول الصيفية.
 * الإدارة: الجامعة بتدير أي حدث، والكلية بتدير أحداث كليتها بس. `$scope` هو نفس مصفوفة FeeApiController::scope().
 */
class AcademicCalendarService
{
    public const KINDS = ['term', 'exams', 'holiday', 'deadline', 'registration', 'other'];

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    private function canManage(array $scope): bool
    {
        return ($scope['role'] ?? null) === 'university' || (($scope['role'] ?? null) === 'faculty' && !empty($scope['facultyId']));
    }

    /** أحداث النطاق اللي المستخدم يشوفها. */
    private function visible(array $scope)
    {
        $q = DB::table('academic_calendar_events')->where('university_id', $scope['universityId']);
        if (($scope['role'] ?? null) !== 'university') {
            $fid = (int) ($scope['facultyId'] ?? 0);
            $q->where(fn ($w) => $w->whereNull('faculty_id')->when($fid, fn ($x) => $x->orWhere('faculty_id', $fid)));
        }
        return $q;
    }

    private function present(object $e, bool $manage, array $scope): array
    {
        $editable = $manage && (($scope['role'] ?? null) === 'university' || (int) $e->faculty_id === (int) ($scope['facultyId'] ?? 0));
        return [
            'id' => (int) $e->id, 'source' => 'event', 'title' => $e->title, 'kind' => $e->kind, 'starts_on' => $e->starts_on, 'ends_on' => $e->ends_on,
            'note' => $e->note, 'faculty_id' => $e->faculty_id ? (int) $e->faculty_id : null, 'editable' => $editable,
        ];
    }

    public function index(array $scope, array $f, string $l): array
    {
        $manage = $this->canManage($scope);
        $q = $this->visible($scope);
        if (!empty($f['kind']) && in_array($f['kind'], self::KINDS, true) && $f['kind'] !== 'registration') {
            $q->where('kind', $f['kind']);
        }
        $items = $q->orderBy('starts_on')->limit(1000)->get()->map(fn ($e) => $this->present($e, $manage, $scope))->all();

        // مشتقّة: فترات التسجيل + الفصول الصيفية (قراءة فقط).
        $fid = (int) ($scope['facultyId'] ?? 0);
        $periods = DB::table('registration_periods')->where(fn ($w) => $w
            ->where(fn ($x) => $x->where('scope_type', 'university')->where('scope_id', $scope['universityId']))
            ->when($fid, fn ($x) => $x->orWhere(fn ($y) => $y->where('scope_type', 'faculty')->where('scope_id', $fid))))->get();
        $summer = DB::table('summer_terms')->where('university_id', $scope['universityId'])
            ->where(fn ($w) => $w->whereNull('faculty_id')->when($fid, fn ($x) => $x->orWhere('faculty_id', $fid)))->get();
        $derived = [];
        foreach ($periods as $p) {
            $derived[] = ['id' => 'rp' . $p->id, 'source' => 'registration_period', 'title' => $p->name, 'kind' => 'registration', 'starts_on' => substr((string) $p->opens_at, 0, 10), 'ends_on' => substr((string) $p->closes_at, 0, 10), 'note' => null, 'faculty_id' => $p->scope_type === 'faculty' ? (int) $p->scope_id : null, 'editable' => false];
        }
        foreach ($summer as $t) {
            $derived[] = ['id' => 'st' . $t->id, 'source' => 'summer_registration', 'title' => $t->name . ($this->ar($l) ? ' (تسجيل)' : ' (registration)'), 'kind' => 'registration', 'starts_on' => substr((string) $t->opens_at, 0, 10), 'ends_on' => substr((string) $t->closes_at, 0, 10), 'note' => null, 'faculty_id' => $t->faculty_id ? (int) $t->faculty_id : null, 'editable' => false];
            if ($t->starts_on) {
                $derived[] = ['id' => 'sc' . $t->id, 'source' => 'summer_term', 'title' => $t->name, 'kind' => 'term', 'starts_on' => $t->starts_on, 'ends_on' => $t->ends_on, 'note' => null, 'faculty_id' => $t->faculty_id ? (int) $t->faculty_id : null, 'editable' => false];
            }
        }
        if (!empty($f['kind']) && in_array($f['kind'], self::KINDS, true)) {
            $derived = array_values(array_filter($derived, fn ($d) => $d['kind'] === $f['kind']));
        }
        $all = array_merge($items, $derived);
        usort($all, fn ($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        return ['success' => true, 'message' => 'OK', 'data' => ['can_manage' => $manage, 'faculties' => $this->faculties($scope), 'events' => $all]];
    }

    public function save(array $scope, ?int $id, array $d, string $l): array
    {
        if (!$this->canManage($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $ends = !empty($d['ends_on']) ? $d['ends_on'] : null;
        if ($ends && $ends < $d['starts_on']) {
            return $this->fail($l, 'تاريخ النهاية قبل البداية.', 'The end date is before the start date.');
        }
        $row = [
            'title' => trim($d['title']), 'kind' => in_array($d['kind'] ?? 'other', self::KINDS, true) ? $d['kind'] : 'other',
            'starts_on' => $d['starts_on'], 'ends_on' => $ends, 'note' => isset($d['note']) && trim($d['note']) !== '' ? mb_substr(trim($d['note']), 0, 500) : null,
            'updated_at' => now(),
        ];
        if ($id) {
            $cur = $this->visible($scope)->where('id', $id)->first();
            $mine = $cur && (($scope['role'] ?? null) === 'university' || (int) $cur->faculty_id === (int) $scope['facultyId']);
            if (!$mine) {
                return $this->fail($l, 'الحدث غير موجود أو مش من صلاحيتك.', 'Event not found or not yours to edit.', 404);
            }
            DB::table('academic_calendar_events')->where('id', $id)->update($row);
            $msg = $this->ar($l) ? 'تم تعديل الحدث.' : 'Event updated.';
        } else {
            // الجامعة تقدر تحدد كلية (faculty_id) أو تسيبها للجامعة كلها؛ الكلية دايماً لكليتها.
            $faculty = ($scope['role'] ?? null) === 'faculty' ? (int) $scope['facultyId'] : (!empty($d['faculty_id']) ? (int) $d['faculty_id'] : null);
            if ($faculty && ($scope['role'] ?? null) === 'university' && !DB::table('faculties')->where('id', $faculty)->where('university_id', $scope['universityId'])->exists()) {
                return $this->fail($l, 'الكلية غير موجودة.', 'Faculty not found.', 404);
            }
            DB::table('academic_calendar_events')->insert($row + ['university_id' => $scope['universityId'], 'faculty_id' => $faculty, 'created_by_user_id' => $scope['userId'], 'created_at' => now()]);
            $msg = $this->ar($l) ? 'تم إضافة الحدث.' : 'Event added.';
        }
        return $this->index($scope, [], $l) + ['message' => $msg];
    }

    public function delete(array $scope, int $id, string $l): array
    {
        if (!$this->canManage($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $cur = $this->visible($scope)->where('id', $id)->first();
        if (!$cur || (($scope['role'] ?? null) === 'faculty' && (int) $cur->faculty_id !== (int) $scope['facultyId'])) {
            return $this->fail($l, 'الحدث غير موجود أو مش من صلاحيتك.', 'Event not found or not yours to delete.', 404);
        }
        DB::table('academic_calendar_events')->where('id', $id)->delete();
        return $this->index($scope, [], $l) + ['message' => $this->ar($l) ? 'تم حذف الحدث.' : 'Event deleted.'];
    }

    /** قايمة كليات الجامعة (للجامعة وهي بتختار نطاق الحدث). */
    public function faculties(array $scope): array
    {
        if (($scope['role'] ?? null) !== 'university') {
            return [];
        }
        return DB::table('faculties')->where('university_id', $scope['universityId'])->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($f) => ['id' => (int) $f->id, 'name_ar' => $f->name_ar, 'name_en' => $f->name_en])->all();
    }
}
