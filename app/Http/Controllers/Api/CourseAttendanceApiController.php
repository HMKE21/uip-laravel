<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\CourseAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * الحضور والغياب (QR + جيوفنس)
 *
 *  مديرو المادة (مدرّس / كلية / جامعة):
 *    GET    /attendance/courses/{id}/windows          — تحضيرات المادة
 *    POST   /attendance/courses/{id}/windows          — فتح تحضير (+ موقع المدرّس)
 *    GET    /attendance/courses/{id}/report           — نسب الحضور والحرمان
 *    GET    /attendance/windows/{wid}                 — تفاصيل + قايمة الطلاب
 *    GET    /attendance/windows/{wid}/qr              — توكن الـ QR الحالي (بيتغيّر كل ~30 ثانية)
 *    POST   /attendance/windows/{wid}/close           — قفل + تسجيل الغياب
 *    PUT    /attendance/windows/{wid}/students/{sid}  — تعديل يدوي للحالة
 *  الطالب:
 *    POST   /attendance/check-in                      — تسجيل الحضور (token + lat/lng/accuracy + device_id)
 *    GET    /attendance/my                            — حضوري ونسب الغياب
 *  الكلية / الجامعة:
 *    GET    /attendance/locations                     — مواقع الكليات
 *    PUT    /attendance/locations/{facultyId}         — تحديد موقع ونطاق الكلية
 */
class CourseAttendanceApiController extends Controller
{
    public function __construct(
        private CourseAttendanceService $attendance,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $staff,
        private StudentRepository $students
    ) {
    }

    /** نفس CourseApiController::scope() بالظبط. */
    private function scope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');
        $base = ['role' => $role, 'userId' => $userId, 'facultyId' => null, 'staffId' => null, 'studentId' => null];

        switch ($role) {
            case 'university':
                $user = User::find($userId);
                $u = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
                return $base + ['universityId' => $u->id];
            case 'faculty':
                $f = $this->faculties->findByUserId($userId);
                return $f ? array_merge($base, ['universityId' => $f->university_id, 'facultyId' => (int) $f->id]) : null;
            case 'academic_staff':
                $a = $this->staff->findByUserId($userId);
                return $a ? array_merge($base, ['universityId' => $a->university_id, 'facultyId' => $a->faculty_id ? (int) $a->faculty_id : null, 'staffId' => (int) $a->id]) : null;
            case 'student':
                $s = $this->students->findByUserId($userId);
                return ($s && $s->university_id)
                    ? array_merge($base, ['universityId' => $s->university_id, 'facultyId' => $s->faculty_id ? (int) $s->faculty_id : null, 'studentId' => (int) $s->id])
                    : null;
        }
        return null;
    }

    private function locale(Request $r): string
    {
        return $r->input('locale') === 'en' ? 'en' : 'ar';
    }

    private function respond(array $res, int $okStatus = 200)
    {
        if ($res['success']) {
            return $this->apiSuccess($res['data'] ?? null, $res['message'] ?? 'OK', $okStatus);
        }
        return $this->apiError($res['message'] ?? 'Error', null, $res['code'] ?? 422);
    }

    private function denied()
    {
        return $this->apiError('Your account is not linked to a university.', null, 403);
    }

    private function invalid($v)
    {
        return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
    }

    public function windows(Request $request, $id)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->listWindows($s, $id, $this->locale($request))) : $this->denied();
    }

    public function open(Request $request, $id)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), [
            'session_id' => 'nullable|integer|min:1',
            'minutes'    => 'nullable|integer|min:1|max:600',
            'lat'        => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng'        => 'nullable|numeric|between:-180,180|required_with:lat',
            'accuracy'   => 'nullable|numeric|min:0|max:100000',
            'title'      => 'nullable|string|max:200',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        return $this->respond($this->attendance->open($s, $id, $request->only(['session_id', 'minutes', 'lat', 'lng', 'accuracy', 'title']), $this->locale($request)), 201);
    }

    public function report(Request $request, $id)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->report($s, $id, $this->locale($request))) : $this->denied();
    }

    public function show(Request $request, $wid)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->detail($s, $wid, $this->locale($request))) : $this->denied();
    }

    public function qr(Request $request, $wid)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->qr($s, $wid, $this->locale($request))) : $this->denied();
    }

    public function close(Request $request, $wid)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->close($s, $wid, $this->locale($request))) : $this->denied();
    }

    public function mark(Request $request, $wid, $sid)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:' . implode(',', CourseAttendanceService::STATUSES)]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        return $this->respond($this->attendance->mark($s, $wid, $sid, (string) $request->input('status'), $this->locale($request)));
    }

    public function checkIn(Request $request)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), [
            'token'     => 'required|string|max:120',
            'lat'       => 'required|numeric|between:-90,90',
            'lng'       => 'required|numeric|between:-180,180',
            'accuracy'  => 'required|numeric|min:0|max:100000',
            'device_id' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        return $this->respond($this->attendance->checkIn($s, $request->only(['token', 'lat', 'lng', 'accuracy', 'device_id']), $this->locale($request)));
    }

    public function mine(Request $request)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->mine($s, $this->locale($request))) : $this->denied();
    }

    public function locations(Request $request)
    {
        $s = $this->scope($request);
        return $s ? $this->respond($this->attendance->locations($s)) : $this->denied();
    }

    public function setLocation(Request $request, $facultyId)
    {
        $s = $this->scope($request);
        if (!$s) {
            return $this->denied();
        }
        $v = Validator::make($request->all(), [
            'lat'      => 'required|numeric|between:-90,90',
            'lng'      => 'required|numeric|between:-180,180',
            'radius_m' => 'nullable|integer|between:50,5000',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        return $this->respond($this->attendance->setLocation($s, $facultyId, $request->only(['lat', 'lng', 'radius_m']), $this->locale($request)));
    }
}
