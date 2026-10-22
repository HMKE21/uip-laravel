<?php

namespace App\Services;

use App\Services\Export\PdfWriter;
use Illuminate\Support\Facades\DB;

/**
 * الطلبات الإدارية: شهادة قيد / بيان درجات / إفادة.
 * المسار: الطالب ← الكلية (لو للطالب كلية) ← الجامعة. الكلية بتوافق وتحوّل للجامعة، والجامعة بتعتمد (وتقدر تعتمد مباشرة).
 * رفض أي جهة بيقفل الطلب. بعد الاعتماد الطالب بيحمّل المستند PDF (بيتولّد وقت التحميل من بيانات الطالب الحالية).
 * `$scope` هو نفس مصفوفة CourseApiController::scope().
 */
class AdminRequestService
{
    public const TYPES = ['enrollment_certificate', 'transcript', 'statement'];

    public function __construct(
        private NotificationService $notifications,
        private GradingService $grading,
        private AuditLogService $auditLog
    ) {
    }

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    private function typeLabel(string $type, string $l): string
    {
        $m = ['enrollment_certificate' => ['شهادة قيد', 'Enrollment certificate'], 'transcript' => ['بيان درجات', 'Grade statement'], 'statement' => ['إفادة', 'Statement letter']];
        return $m[$type][$this->ar($l) ? 0 : 1] ?? $type;
    }

    // ---------------------------------------------------------------- query

    /** الطلبات اللي الطرف ده يشوفها. */
    private function visible(array $scope)
    {
        $q = DB::table('admin_requests as r')
            ->join('students as s', 's.id', '=', 'r.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('r.university_id', $scope['universityId']);
        return match ($scope['role']) {
            'student' => $q->where('r.student_id', (int) $scope['studentId']),
            'faculty' => $q->where('r.faculty_id', (int) $scope['facultyId']),
            'university' => $q,
            default => $q->whereRaw('1 = 0'),
        };
    }

    private function cols($q)
    {
        return $q->select('r.*', 'u.full_name as student_name', 's.student_number');
    }

    private function present(object $r, string $l, bool $events = false): array
    {
        $row = [
            'id' => (int) $r->id, 'ref_no' => $r->ref_no, 'type' => $r->type, 'type_label' => $this->typeLabel($r->type, $l),
            'status' => $r->status, 'stage' => $r->stage, 'purpose' => $r->purpose, 'message' => $r->message,
            'student' => ['id' => (int) $r->student_id, 'name' => $r->student_name ?? null, 'number' => $r->student_number ?? null],
            'decision_note' => $r->decision_note, 'decided_by_role' => $r->decided_by_role, 'decided_at' => $r->decided_at,
            'created_at' => $r->created_at, 'document_ready' => $r->status === 'approved',
        ];
        if ($events) {
            $row['events'] = DB::table('admin_request_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.request_id', $r->id)->orderBy('e.id')
                ->get(['e.role', 'e.action', 'e.note', 'e.created_at', 'u.full_name'])
                ->map(fn ($e) => ['role' => $e->role, 'action' => $e->action, 'note' => $e->note, 'at' => $e->created_at, 'by' => $e->full_name])->all();
        }
        return $row;
    }

    public function list(array $scope, array $f, string $l): array
    {
        if (!in_array($scope['role'], ['student', 'faculty', 'university'], true)) {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $q = $this->cols($this->visible($scope));
        if (!empty($f['status']) && in_array($f['status'], ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $q->where('r.status', $f['status']);
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $q->where('r.type', $f['type']);
        }
        $rows = $q->orderByDesc('r.id')->limit(300)->get();
        $mine = fn ($r) => $r->status === 'pending' && (
            ($scope['role'] === 'faculty' && $r->stage === 'faculty') || $scope['role'] === 'university'
        );
        return ['success' => true, 'message' => 'OK', 'data' => $rows->map(function ($r) use ($l, $scope, $mine) {
            return $this->present($r, $l, true) + ['can_act' => $scope['role'] !== 'student' && $mine($r), 'can_cancel' => $scope['role'] === 'student' && $r->status === 'pending'];
        })->values()->all()];
    }

    // ---------------------------------------------------------------- student

    public function create(array $scope, string $type, ?string $purpose, ?string $message, string $l): array
    {
        if ($scope['role'] !== 'student' || !$scope['studentId']) {
            return $this->fail($l, 'للطلاب فقط.', 'Students only.', 403);
        }
        if (!in_array($type, self::TYPES, true)) {
            return $this->fail($l, 'نوع الطلب غير صالح.', 'Invalid request type.');
        }
        $purpose = $purpose !== null ? trim($purpose) : null;
        $message = $message !== null ? trim($message) : null;
        if ($type === 'statement' && !$purpose) {
            return $this->fail($l, 'اكتب الجهة أو الغرض من الإفادة.', 'Enter who the letter is for or its purpose.');
        }
        if (DB::table('admin_requests')->where('student_id', $scope['studentId'])->where('type', $type)->where('status', 'pending')->exists()) {
            return $this->fail($l, 'لديك طلب قيد المراجعة من نفس النوع.', 'You already have a pending request of this type.', 409);
        }
        $facultyId = $scope['facultyId'] ?: null;
        $stage = $facultyId ? 'faculty' : 'university';

        $id = DB::transaction(function () use ($scope, $type, $purpose, $message, $facultyId, $stage) {
            $id = DB::table('admin_requests')->insertGetId([
                'student_id' => $scope['studentId'], 'university_id' => $scope['universityId'], 'faculty_id' => $facultyId,
                'type' => $type, 'status' => 'pending', 'stage' => $stage,
                'purpose' => $purpose !== '' ? $purpose : null, 'message' => $message !== '' ? $message : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('admin_requests')->where('id', $id)->update(['ref_no' => 'AR-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            $this->event($id, $scope['userId'], 'student', 'submitted', $message);
            return $id;
        });

        $this->notifyStage($scope['universityId'], $facultyId, $stage, $id, $type, (int) $scope['userId']);
        return ['success' => true, 'message' => $this->ar($l) ? 'تم إرسال طلبك.' : 'Your request was submitted.', 'data' => ['id' => $id, 'stage' => $stage]];
    }

    public function cancel(array $scope, $id, string $l): array
    {
        if ($scope['role'] !== 'student') {
            return $this->fail($l, 'للطلاب فقط.', 'Students only.', 403);
        }
        $n = DB::table('admin_requests')->where('id', (int) $id)->where('student_id', $scope['studentId'])->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        if (!$n) {
            return $this->fail($l, 'الطلب غير موجود أو تم البتّ فيه.', 'Request not found or already handled.', 404);
        }
        $this->event((int) $id, $scope['userId'], 'student', 'cancelled', null);
        return ['success' => true, 'message' => $this->ar($l) ? 'تم إلغاء الطلب.' : 'Request cancelled.'];
    }

    // ---------------------------------------------------------------- faculty / university

    public function approve(array $scope, $id, ?string $note, string $l): array
    {
        return $this->decide($scope, $id, 'approved', $note, $l);
    }

    public function reject(array $scope, $id, ?string $note, string $l): array
    {
        return $this->decide($scope, $id, 'rejected', $note, $l);
    }

    private function decide(array $scope, $id, string $decision, ?string $note, string $l): array
    {
        if (!in_array($scope['role'], ['faculty', 'university'], true)) {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;
        if ($decision === 'rejected' && !$note) {
            return $this->fail($l, 'اكتب سبب الرفض.', 'Please give a reason for the rejection.');
        }
        if (!$this->visible($scope)->where('r.id', (int) $id)->exists()) {
            return $this->fail($l, 'الطلب غير موجود.', 'Request not found.', 404);
        }

        return DB::transaction(function () use ($scope, $id, $decision, $note, $l) {
            $r = DB::table('admin_requests')->where('id', (int) $id)->lockForUpdate()->first();
            if (!$r || $r->status !== 'pending') {
                return $this->fail($l, 'تم البتّ في هذا الطلب بالفعل.', 'This request has already been handled.', 409);
            }
            // الكلية بتتصرف بس وهو عندها؛ الجامعة في أي مرحلة.
            if ($scope['role'] === 'faculty' && $r->stage !== 'faculty') {
                return $this->fail($l, 'الطلب عند الجامعة دلوقتي.', 'The request is with the university now.', 409);
            }

            if ($decision === 'approved' && $scope['role'] === 'faculty') {
                DB::table('admin_requests')->where('id', $r->id)->update(['stage' => 'university', 'updated_at' => now()]);
                $this->event((int) $r->id, $scope['userId'], 'faculty', 'forwarded', $note);
                $this->notifyStage((int) $r->university_id, null, 'university', (int) $r->id, $r->type, (int) DB::table('students')->where('id', $r->student_id)->value('user_id'));
                $this->notifyStudent($r, 'forwarded', $note);
                return ['success' => true, 'message' => $this->ar($l) ? 'تمت الموافقة وتحويل الطلب للجامعة.' : 'Approved and forwarded to the university.'];
            }

            DB::table('admin_requests')->where('id', $r->id)->update([
                'status' => $decision, 'decided_by_user_id' => $scope['userId'], 'decided_by_role' => $scope['role'],
                'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->event((int) $r->id, $scope['userId'], $scope['role'], $decision === 'approved' ? 'approved' : 'rejected', $note);
            $this->auditLog->record($scope['userId'], 'admin_request.' . $decision, 'AdminRequest', $r->id, null, ['type' => $r->type]);
            $this->notifyStudent($r, $decision, $note);
            return ['success' => true, 'message' => $decision === 'approved'
                ? ($this->ar($l) ? 'تم اعتماد الطلب.' : 'Request approved.')
                : ($this->ar($l) ? 'تم رفض الطلب.' : 'Request rejected.')];
        });
    }

    // ---------------------------------------------------------------- document

    /** @return array{success:bool,data?:array{path:string,filename:string},message?:string,code?:int} */
    public function document(array $scope, $id, string $l): array
    {
        if (!in_array($scope['role'], ['student', 'faculty', 'university'], true)) {
            return $this->fail($l, 'غير مسموح.', 'Not allowed.', 403);
        }
        $r = $this->cols($this->visible($scope))->where('r.id', (int) $id)->first();
        if (!$r) {
            return $this->fail($l, 'الطلب غير موجود.', 'Request not found.', 404);
        }
        if ($r->status !== 'approved') {
            return $this->fail($l, 'المستند متاح بعد اعتماد الطلب.', 'The document is available once the request is approved.', 409);
        }
        $ar = $this->ar($l);

        if ($r->type === 'transcript') {
            $res = $this->grading->transcriptPdf(['role' => 'student', 'studentId' => (int) $r->student_id], $l);
            if ($res['success']) {
                $res['data']['filename'] = $r->ref_no . '-transcript.pdf';
            }
            return $res;
        }

        $st = DB::table('students as s')
            ->leftJoin('faculties as f', 'f.id', '=', 's.faculty_id')
            ->leftJoin('universities as un', 'un.id', '=', 's.university_id')
            ->where('s.id', $r->student_id)
            ->first(['f.name_ar as fac_ar', 'f.name_en as fac_en', 'un.official_name_ar as uni_ar', 'un.official_name_en as uni_en']);
        $faculty = $st ? ($ar ? ($st->fac_ar ?: $st->fac_en) : ($st->fac_en ?: $st->fac_ar)) : null;
        $uni = $st ? ($ar ? ($st->uni_ar ?: $st->uni_en) : ($st->uni_en ?: $st->uni_ar)) : null;
        $courses = (int) DB::table('course_enrollments as e')->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $r->student_id)->where('c.status', 'active')->count();

        $rows = [
            [$ar ? 'الاسم' : 'Name', $r->student_name],
            [$ar ? 'الرقم الجامعي' : 'Student number', $r->student_number],
            [$ar ? 'الكلية' : 'Faculty', $faculty ?: '—'],
            [$ar ? 'الجامعة' : 'University', $uni ?: '—'],
            [$ar ? 'الحالة' : 'Status', $ar ? 'مقيّد بالجامعة' : 'Currently enrolled'],
            [$ar ? 'المواد المسجّلة حاليًا' : 'Courses this term', $courses],
        ];
        if ($r->purpose) {
            $rows[] = [$ar ? 'مقدّمة إلى' : 'Issued to', $r->purpose];
        }
        $rows[] = [$ar ? 'رقم الطلب' : 'Reference', $r->ref_no];
        $rows[] = [$ar ? 'تاريخ الإصدار' : 'Issue date', date('Y-m-d', strtotime((string) ($r->decided_at ?: $r->updated_at)))];

        $dir = storage_path('app/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/adminreq_' . (int) $r->id . '_' . date('Ymd_His') . '.pdf';
        PdfWriter::writeTable($path, $this->typeLabel($r->type, $l), [$ar ? 'البند' : 'Item', $ar ? 'القيمة' : 'Value'], $rows, $ar);
        return ['success' => true, 'data' => ['path' => $path, 'filename' => $r->ref_no . '.pdf']];
    }

    // ---------------------------------------------------------------- helpers

    private function event(int $requestId, $userId, string $role, string $action, ?string $note): void
    {
        DB::table('admin_request_events')->insert(['request_id' => $requestId, 'user_id' => $userId, 'role' => $role, 'action' => $action, 'note' => $note, 'created_at' => now()]);
    }

    private function notifyStage($universityId, $facultyId, string $stage, int $requestId, string $type, int $studentUserId): void
    {
        try {
            $uid = $stage === 'faculty' && $facultyId
                ? DB::table('faculties')->where('id', $facultyId)->value('user_id')
                : DB::table('universities')->where('id', $universityId)->value('user_id');
            if (!$uid) {
                return;
            }
            $name = (string) DB::table('users')->where('id', $studentUserId)->value('full_name');
            $link = ($stage === 'faculty' ? '/faculty' : '/university') . '/admin-requests?request=' . $requestId;
            $this->notifications->notify((int) $uid, 'admin_request', 'طلب إداري جديد: ' . $this->typeLabel($type, 'ar'), "الطالب {$name}", $link, 'normal');
        } catch (\Throwable $e) {
            // الإشعارات ما تكسرش الطلب
        }
    }

    private function notifyStudent(object $r, string $result, ?string $note): void
    {
        try {
            $userId = (int) DB::table('students')->where('id', $r->student_id)->value('user_id');
            $label = $this->typeLabel($r->type, 'ar');
            $title = match ($result) {
                'approved' => "تم اعتماد طلبك: {$label}",
                'forwarded' => "طلبك ({$label}) وصل للجامعة",
                default => "تم رفض طلبك: {$label}",
            };
            $this->notifications->notify($userId, 'admin_request_result', $title, $note, '/student/admin-requests', 'normal');
        } catch (\Throwable $e) {
        }
    }
}
