<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * تبعية أي مستخدم لجامعة/كلية/قسم من منظور الأدمن (صفحة Admin > Users).
 *
 * المشكلة اللي بتحلها: الأدمن كان مش شايف الطالب/المشرف/عضو الهيئة تابع
 * لأنهي جامعة، ومفيش أي طريقة ينقله لجامعة تانية أو يشيله من جامعته.
 *
 * القواعد (مبنية على الـ schema الحقيقي):
 *  - طالب: students.university_id nullable => ينفع ينتقل أو يتشال خالص.
 *  - مشرف / عضو هيئة: university_id NOT NULL (+ FK cascade) => ينفع
 *    ينتقل لجامعة تانية بس، مينفعش يتشال من غير جامعة. الإيقاف بيتم
 *    من suspend/delete العاديين.
 *  - حساب كلية: faculties.user_id => عرض فقط (تغيير الكلية بيتم من
 *    بورتال الجامعة، لأن الحساب نفسه هو "الكلية").
 *  - جامعة: عرض + أعداد (طلاب/مشرفين/هيئة/كليات).
 *  - باقي الأدوار (شركة/مستثمر/باحث/أدمن...): مفيش ربط جامعي، بيرجع
 *    kind='none' من غير ما نخترع بيانات.
 */
class AdminUserAffiliationService
{
    /** مشاريع الطالب اللي لسه مفيهاش قرار نهائي => تتبع جامعته الجديدة. */
    private const MOVABLE_PROJECT_STATUSES = ['draft', 'submitted', 'under_review', 'rejected'];

    public function __construct(
        private AccountLifecycleService $lifecycle,
        private AuditLogService $auditLog,
        private NotificationService $notifications
    ) {
    }

    // -- قراءة -----------------------------------------------------------------

    /** @return array<string,mixed> */
    public function describe(int $userId): array
    {
        $kind = $this->lifecycle->kindOf($userId);

        if ($kind === 'student') {
            $s = DB::table('students')->where('user_id', $userId)->first();
            $out = [
                'kind' => 'student', 'editable' => true, 'can_detach' => true,
                'university' => $this->university($s->university_id),
                'faculty' => $this->named('faculties', $s->faculty_id),
                'department' => $this->named('departments', $s->department_id),
                'group' => $s->group_id ? DB::table('student_groups')->where('id', $s->group_id)->value('name') : null,
                'student_number' => $s->student_number,
                'invitation_status' => $s->invitation_status,
                'pending_request' => $this->pendingRequest((int) $s->id),
                'counts' => [
                    'projects' => DB::table('projects')->where('owner_id', $userId)->count(),
                ],
            ];
            // الجامعة اتحذفت وحسابه اتوقف بسببها.
            $suspendedBy = DB::table('users')->where('id', $userId)->value('suspended_by_university_id');
            $out['suspended_by_university'] = $suspendedBy ? $this->university((int) $suspendedBy) : null;
            return $out;
        }

        if ($kind === 'supervisor') {
            $s = DB::table('supervisors')->where('user_id', $userId)->first();
            return [
                'kind' => 'supervisor', 'editable' => true, 'can_detach' => false,
                'detach_blocked_reason' => 'A supervisor must belong to a university. Move them to another university, or suspend/delete the account.',
                'university' => $this->university($s->university_id),
                'faculty' => null, 'department' => null,
                'title' => $s->title ?? null,
                'status' => $s->status ?? null,
                'counts' => [
                    'assignments' => DB::table('supervisor_assignments')->where('supervisor_id', $s->id)->count(),
                ],
            ];
        }

        if ($kind === 'academic_staff') {
            $s = DB::table('academic_staff')->where('user_id', $userId)->first();
            return [
                'kind' => 'academic_staff', 'editable' => true, 'can_detach' => false,
                'detach_blocked_reason' => 'Academic staff must belong to a university. Move them to another university, or suspend/delete the account.',
                'university' => $this->university($s->university_id),
                'faculty' => $this->named('faculties', $s->faculty_id),
                'department' => $this->named('departments', $s->department_id),
                'status' => $s->status ?? null,
                'counts' => [
                    'assignments' => DB::table('staff_assignments')->where('academic_staff_id', $s->id)->where('is_active', 1)->count(),
                ],
            ];
        }

        if ($kind === 'university') {
            $u = DB::table('universities')->where('user_id', $userId)->first();
            if (!$u) {
                return ['kind' => 'none', 'editable' => false, 'can_detach' => false];
            }
            return [
                'kind' => 'university', 'editable' => false, 'can_detach' => false,
                'university' => $this->university((int) $u->id),
                'faculty' => null, 'department' => null,
                'counts' => [
                    'students' => DB::table('students')->where('university_id', $u->id)->count(),
                    'supervisors' => DB::table('supervisors')->where('university_id', $u->id)->count(),
                    'academic_staff' => DB::table('academic_staff')->where('university_id', $u->id)->count(),
                    'faculties' => DB::table('faculties')->where('university_id', $u->id)->count(),
                ],
            ];
        }

        // حساب كلية: faculties.user_id
        $faculty = DB::table('faculties')->where('user_id', $userId)->first();
        if ($faculty) {
            return [
                'kind' => 'faculty', 'editable' => false, 'can_detach' => false,
                'university' => $this->university((int) $faculty->university_id),
                'faculty' => ['id' => (int) $faculty->id, 'name_en' => $faculty->name_en, 'name_ar' => $faculty->name_ar],
                'department' => null,
                'counts' => [
                    'departments' => DB::table('departments')->whereNull('deleted_at')->where('faculty_id', $faculty->id)->count(),
                    'students' => DB::table('students')->where('faculty_id', $faculty->id)->count(),
                    'academic_staff' => DB::table('academic_staff')->where('faculty_id', $faculty->id)->count(),
                ],
            ];
        }

        return ['kind' => 'none', 'editable' => false, 'can_detach' => false];
    }

    /** كل الجامعات الشغالة + هيكلها (كليات/أقسام/برامج) للقوائم المنسدلة. */
    public function options(): array
    {
        $unis = \App\Models\University::applyListable(DB::table('universities'))
            ->orderBy('official_name_en')
            ->get(['id', 'official_name_en', 'official_name_ar', 'verification_status']);

        $faculties = DB::table('faculties')->whereIn('university_id', $unis->pluck('id'))
            ->where('status', '!=', 'archived')
            ->orderBy('name_en')->get(['id', 'university_id', 'name_en', 'name_ar']);
        $departments = DB::table('departments')->whereNull('deleted_at')->whereIn('faculty_id', $faculties->pluck('id'))
            ->where('status', '!=', 'inactive')
            ->orderBy('name_en')->get(['id', 'faculty_id', 'name_en', 'name_ar']);
        $depBy = $departments->groupBy('faculty_id');
        $facBy = $faculties->groupBy('university_id');

        return $unis->map(fn ($u) => [
            'id' => (int) $u->id,
            'name_en' => $u->official_name_en, 'name_ar' => $u->official_name_ar,
            'verification_status' => $u->verification_status,
            'faculties' => ($facBy[$u->id] ?? collect())->map(fn ($f) => [
                'id' => (int) $f->id, 'name_en' => $f->name_en, 'name_ar' => $f->name_ar,
                'departments' => ($depBy[$f->id] ?? collect())->map(fn ($d) => [
                    'id' => (int) $d->id, 'name_en' => $d->name_en, 'name_ar' => $d->name_ar,
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    // -- نقل / تعديل -------------------------------------------------------------

    /**
     * @return array{ok:bool, error:?string, changed:array<string,mixed>}
     */
    public function assign(int $userId, int $universityId, ?int $facultyId, ?int $departmentId, int $actorId): array
    {
        $kind = $this->lifecycle->kindOf($userId);
        $uni = DB::table('universities')->where('id', $universityId)->whereNull('deleted_at')->first();

        // An account created by an admin with the supervisor / academic-staff role has no
        // member row yet (those tables require a university), so it is created on first link.
        if ($kind === 'user' && $uni) {
            $roleSlug = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $userId)->whereIn('r.slug', ['supervisor', 'academic_staff'])->value('r.slug');
            if ($roleSlug) {
                $u = DB::table('users')->where('id', $userId)->first();
                if ($roleSlug === 'supervisor') {
                    DB::table('supervisors')->insert([
                        'university_id' => $universityId, 'user_id' => $userId, 'full_name' => $u->full_name,
                        'name_ar' => $u->name_ar ?? $u->full_name, 'name_en' => $u->name_en ?? $u->full_name,
                        'email' => $u->email, 'status' => 'active', 'invitation_status' => 'accepted', 'accepted_at' => now(),
                    ]);
                } else {
                    DB::table('academic_staff')->insert([
                        'user_id' => $userId, 'university_id' => $universityId, 'status' => 'active',
                        'invitation_status' => 'accepted', 'accepted_at' => now(),
                    ]);
                }
                $kind = $roleSlug;
            }
        }

        if (!in_array($kind, ['student', 'supervisor', 'academic_staff'], true)) {
            return $this->fail('Only students, supervisors and academic staff can be linked to a university from here.');
        }

        if (!$uni) {
            return $this->fail('That university does not exist or was deleted.');
        }

        // الهيكل: كلية => جامعة، قسم => كلية. كل مستوى يحتاج اللي فوقه.
        if ($facultyId && !DB::table('faculties')->where('id', $facultyId)->where('university_id', $universityId)->exists()) {
            return $this->fail('The selected faculty does not belong to that university.');
        }
        if ($departmentId) {
            if (!$facultyId) {
                return $this->fail('Select a faculty before a department.');
            }
            if (!DB::table('departments')->whereNull('deleted_at')->where('id', $departmentId)->where('faculty_id', $facultyId)->exists()) {
                return $this->fail('The selected department does not belong to that faculty.');
            }
        }
        if ($kind === 'supervisor' && ($facultyId || $departmentId)) {
            return $this->fail('Supervisors are linked to a university only, not to a faculty or department.');
        }

        $before = $this->describe($userId);
        $oldUniId = $before['university']['id'] ?? null;
        $moved = $oldUniId !== null && (int) $oldUniId !== $universityId;

        $result = ['moved_university' => $moved, 'projects_moved' => 0, 'assignments_removed' => 0];

        DB::beginTransaction();
        try {
            if ($kind === 'student') {
                $result = array_merge($result, $this->assignStudent($userId, $universityId, $facultyId, $departmentId, $oldUniId, $actorId));
            } elseif ($kind === 'supervisor') {
                $sup = DB::table('supervisors')->where('user_id', $userId)->first();
                if ($moved || $oldUniId === null) {
                    $email = $sup->email ?? DB::table('users')->where('id', $userId)->value('email');
                    $clash = DB::table('supervisors')->where('university_id', $universityId)
                        ->where('email', $email)->where('id', '!=', $sup->id)->exists();
                    if ($clash) {
                        DB::rollBack();
                        return $this->fail('That university already has a supervisor with the same email.');
                    }
                    // التكليفات (نطاق/مشروع) كلها تخص الجامعة القديمة.
                    $result['assignments_removed'] = DB::table('supervisor_assignments')->where('supervisor_id', $sup->id)->delete();
                }
                DB::table('supervisors')->where('id', $sup->id)->update(['university_id' => $universityId, 'department' => null]);
                $this->releaseUniversitySuspension($userId, $universityId);
            } else { // academic_staff
                $st = DB::table('academic_staff')->where('user_id', $userId)->first();
                if ($moved || $oldUniId === null) {
                    // الرتب/المناصب (عميد، رئيس قسم...) مرتبطة بكلية/قسم الجامعة القديمة => تتقفل.
                    $result['assignments_removed'] = DB::table('staff_assignments')
                        ->where('academic_staff_id', $st->id)->where('is_active', 1)
                        ->update(['is_active' => 0, 'end_date' => now()->toDateString()]);
                }
                DB::table('academic_staff')->where('id', $st->id)->update([
                    'university_id' => $universityId, 'faculty_id' => $facultyId, 'department_id' => $departmentId,
                ]);
                $this->releaseUniversitySuspension($userId, $universityId);
            }
            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();
            if ($e->getMessage() === 'graduated') {
                return $this->fail('This student already graduated from their current university, so they cannot be moved. Revoke the graduation record first.');
            }
            report($e);
            return $this->fail('Could not update the affiliation.');
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->fail('Could not update the affiliation.');
        }

        $after = $this->describe($userId);
        $this->auditLog->record($actorId, $moved ? 'user.affiliation_transfer' : 'user.affiliation_update', 'User', $userId, $this->brief($before), $this->brief($after) + $result);

        $uniName = $uni->official_name_en ?: $uni->official_name_ar;
        $this->notifyUser($userId, 'affiliation_changed',
            'Your university affiliation was updated',
            'The platform administration linked your account to ' . $uniName . '.',
            $kind);
        if ($moved && $uni->user_id) {
            $this->notifications->notify($uni->user_id, 'member_joined_by_admin', 'A new member was added to your university',
                'The platform administration added a ' . str_replace('_', ' ', $kind) . ' to ' . $uniName . '.', '/university/dashboard');
        }

        return ['ok' => true, 'error' => null, 'changed' => $result];
    }

    /**
     * يشيل الطالب من أي جامعة (university_id = NULL). للطالب بس.
     * @return array{ok:bool, error:?string, changed:array<string,mixed>}
     */
    public function detach(int $userId, int $actorId): array
    {
        $kind = $this->lifecycle->kindOf($userId);
        if ($kind !== 'student') {
            $reason = $this->describe($userId)['detach_blocked_reason'] ?? 'Only students can be removed from a university.';
            return $this->fail($reason);
        }

        $s = DB::table('students')->where('user_id', $userId)->first();
        if (!$s->university_id) {
            return $this->fail('This student is not linked to any university.');
        }
        $before = $this->describe($userId);
        $oldUniId = (int) $s->university_id;

        DB::beginTransaction();
        try {
            $projects = DB::table('projects')->where('owner_id', $userId)
                ->where('university_id', $oldUniId)->whereIn('status', self::MOVABLE_PROJECT_STATUSES)
                ->update(['university_id' => null]);

            DB::table('students')->where('id', $s->id)->update([
                'university_id' => null, 'faculty_id' => null, 'department_id' => null,
                'faculty' => null, 'department' => null, 'group_id' => null, 'expected_graduation_date' => null,
            ]);
            $this->rejectPendingRequests((int) $s->id, $actorId);
            $this->clearUniversitySuspension($userId);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->fail('Could not remove the student from the university.');
        }

        $changed = ['projects_moved' => $projects, 'moved_university' => false];
        $this->auditLog->record($actorId, 'user.affiliation_remove', 'User', $userId, $this->brief($before), ['university_id' => null] + $changed);
        $this->notifyUser($userId, 'affiliation_removed',
            'You were removed from your university',
            'The platform administration unlinked your account from its university. You can request to join a university again from your profile.',
            'student');

        return ['ok' => true, 'error' => null, 'changed' => $changed];
    }

    // -- helpers ---------------------------------------------------------------

    private function assignStudent(int $userId, int $universityId, ?int $facultyId, ?int $departmentId, ?int $oldUniId, int $actorId): array
    {
        $s = DB::table('students')->where('user_id', $userId)->first();
        $moved = $oldUniId !== null && $oldUniId !== $universityId;
        $joining = $oldUniId === null;

        // خريج بسجل تخرج ساري من جامعته: النقل هيكسر السجل.
        if ($moved && DB::table('graduation_records')->where('student_id', $s->id)->where('university_id', $oldUniId)->where('status', 'graduated')->exists()) {
            throw new \RuntimeException('graduated');
        }

        $facultyChanged = (int) ($s->faculty_id ?? 0) !== (int) ($facultyId ?? 0);
        $deptChanged = (int) ($s->department_id ?? 0) !== (int) ($departmentId ?? 0);

        $facultyRow = $facultyId ? DB::table('faculties')->where('id', $facultyId)->first() : null;
        $deptRow = $departmentId ? DB::table('departments')->where('id', $departmentId)->first() : null;

        $update = [
            'university_id' => $universityId,
            'faculty_id' => $facultyId, 'department_id' => $departmentId,
            'faculty' => $facultyRow?->name_en, 'department' => $deptRow?->name_en,
        ];
        // المجموعة تخص جامعة/كلية/قسم معيّن.
        if ($moved || $facultyChanged || $deptChanged) {
            $update['group_id'] = null;
        }
        if ($moved) {
            $update['expected_graduation_date'] = null;
        }
        DB::table('students')->where('id', $s->id)->update($update);

        $projects = 0;
        if ($moved) {
            $projects = DB::table('projects')->where('owner_id', $userId)->where('university_id', $oldUniId)
                ->whereIn('status', self::MOVABLE_PROJECT_STATUSES)->update(['university_id' => $universityId]);
        } elseif ($joining) {
            $projects = DB::table('projects')->where('owner_id', $userId)->whereNull('university_id')
                ->whereIn('status', self::MOVABLE_PROJECT_STATUSES)->update(['university_id' => $universityId]);
        }

        // أي طلب انضمام معلّق بقى ملوش لازمة: قرار الأدمن بيحسمه.
        $this->rejectPendingRequests((int) $s->id, $actorId);
        $this->releaseUniversitySuspension($userId, $universityId);

        return ['moved_university' => $moved, 'projects_moved' => $projects];
    }

    /** حساب اتوقف بسبب حذف جامعته، واتنقل لجامعة شغالة => يرجع لحالته الأصلية. */
    private function releaseUniversitySuspension(int $userId, int $newUniversityId): void
    {
        $row = DB::table('users')->where('id', $userId)->first(['suspended_by_university_id', 'status_before_university_removal', 'status']);
        if (!$row || !$row->suspended_by_university_id) {
            return;
        }
        if ((int) $row->suspended_by_university_id === $newUniversityId) {
            return; // لسه تابع لنفس الجامعة المحذوفة — الاسترجاع بيتم بترجيع الجامعة.
        }
        $this->clearUniversitySuspension($userId);
    }

    private function clearUniversitySuspension(int $userId): void
    {
        $row = DB::table('users')->where('id', $userId)->first(['suspended_by_university_id', 'status_before_university_removal', 'status']);
        if (!$row || !$row->suspended_by_university_id) {
            return;
        }
        DB::table('users')->where('id', $userId)->update([
            'status' => $row->status === 'suspended' ? ($row->status_before_university_removal ?: 'active') : $row->status,
            'suspended_by_university_id' => null,
            'status_before_university_removal' => null,
        ]);
    }

    private function rejectPendingRequests(int $studentId, int $actorId): void
    {
        DB::table('student_university_requests')->where('student_id', $studentId)->where('status', 'pending')
            ->update(['status' => 'rejected', 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
    }

    private function pendingRequest(int $studentId): ?array
    {
        $r = DB::table('student_university_requests as r')
            ->join('universities as u', 'u.id', '=', 'r.university_id')
            ->where('r.student_id', $studentId)->where('r.status', 'pending')
            ->orderByDesc('r.id')->first(['r.id', 'u.official_name_en', 'u.official_name_ar', 'r.created_at']);

        return $r ? ['id' => (int) $r->id, 'name_en' => $r->official_name_en, 'name_ar' => $r->official_name_ar, 'created_at' => $r->created_at] : null;
    }

    private function university($id): ?array
    {
        if (!$id) {
            return null;
        }
        $q = DB::table('universities')->where('id', $id);
        $u = $q->first(['id', 'official_name_en', 'official_name_ar', 'verification_status', 'deleted_at']);

        return $u ? [
            'id' => (int) $u->id, 'name_en' => $u->official_name_en, 'name_ar' => $u->official_name_ar,
            'verification_status' => $u->verification_status, 'deleted' => $u->deleted_at !== null,
        ] : null;
    }

    private function named(string $table, $id): ?array
    {
        if (!$id) {
            return null;
        }
        $r = DB::table($table)->where('id', $id)->first(['id', 'name_en', 'name_ar']);

        return $r ? ['id' => (int) $r->id, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar] : null;
    }

    private function brief(array $d): array
    {
        return [
            'university_id' => $d['university']['id'] ?? null,
            'faculty_id' => $d['faculty']['id'] ?? null,
            'department_id' => $d['department']['id'] ?? null,
        ];
    }

    private function notifyUser(int $userId, string $type, string $title, string $body, string $kind): void
    {
        $link = match ($kind) {
            'student' => '/student/profile',
            'supervisor' => '/supervisor/dashboard',
            default => '/academic-staff/dashboard',
        };
        $this->notifications->notify($userId, $type, $title, $body, $link);
    }

    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'changed' => []];
    }
}
