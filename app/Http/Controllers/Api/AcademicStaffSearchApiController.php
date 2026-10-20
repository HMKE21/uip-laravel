<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/academic-staff/search?q= — بحث شريط الأعلى في بوابة الدكتور:
 * امتحاناته، بنوك أسئلته، أسئلة بنوكه، والطلاب اللي دخلوا امتحاناته.
 * قراءة فقط، ومقيّد دايمًا بعضو الهيئة الموثّق (من التوكن) — مفيش أي نتيجة
 * من بيانات دكتور تاني.
 */
class AcademicStaffSearchApiController extends Controller
{
    private const PER_TYPE = 8;

    public function __construct(private AcademicStaffRepository $staff)
    {
    }

    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can use this search.', null, 403);
        }

        $staff = $this->staff->findByUserId((int) $request->attributes->get('uip_user_id'));
        $q = trim((string) $request->query('q', ''));
        $q = mb_substr($q, 0, 100);

        if (!$staff || mb_strlen($q) < 2) {
            return $this->apiSuccess(['q' => $q, 'exams' => [], 'question_banks' => [], 'questions' => [], 'students' => []], 'Search results retrieved successfully.');
        }

        $like = '%' . addcslashes($q, '\\%_') . '%';
        $sid = (int) $staff->id;

        $exams = DB::table('exams')
            ->where('created_by_academic_staff_id', $sid)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('subject', 'like', $like))
            ->orderByDesc('id')->limit(self::PER_TYPE)
            ->get(['id', 'title', 'subject', 'status', 'start_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'subject' => $r->subject, 'status' => $r->status, 'start_at' => $r->start_at])
            ->all();

        $banks = DB::table('question_banks')
            ->where('created_by_academic_staff_id', $sid)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('subject', 'like', $like))
            ->orderByDesc('id')->limit(self::PER_TYPE)
            ->get(['id', 'title', 'subject', 'status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'subject' => $r->subject, 'status' => $r->status])
            ->all();

        $questions = DB::table('questions as qs')
            ->join('question_banks as b', 'b.id', '=', 'qs.question_bank_id')
            ->where('b.created_by_academic_staff_id', $sid)->whereNull('b.deleted_at')->whereNull('qs.deleted_at')
            ->where(fn ($w) => $w->where('qs.prompt', 'like', $like)->orWhere('qs.topic', 'like', $like))
            ->orderByDesc('qs.id')->limit(self::PER_TYPE)
            ->get(['qs.id', 'qs.prompt', 'qs.type', 'qs.topic', 'b.id as bank_id', 'b.title as bank_title'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'prompt' => mb_substr(strip_tags((string) $r->prompt), 0, 160), 'type' => $r->type,
                'topic' => $r->topic, 'bank_id' => (int) $r->bank_id, 'bank_title' => $r->bank_title,
            ])->all();

        // الطلاب اللي ليهم محاولات في امتحانات الدكتور ده بس.
        $students = DB::table('exam_attempts as a')
            ->join('exams as e', 'e.id', '=', 'a.exam_id')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('e.created_by_academic_staff_id', $sid)
            ->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('s.student_number', 'like', $like))
            ->groupBy('s.id', 'u.full_name', 's.student_number')
            ->orderBy('u.full_name')->limit(self::PER_TYPE)
            ->get(['s.id', 'u.full_name', 's.student_number', DB::raw('COUNT(a.id) as attempts')])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->full_name, 'student_number' => $r->student_number, 'attempts' => (int) $r->attempts])
            ->all();

        return $this->apiSuccess([
            'q' => $q, 'exams' => $exams, 'question_banks' => $banks, 'questions' => $questions, 'students' => $students,
        ], 'Search results retrieved successfully.');
    }
}
