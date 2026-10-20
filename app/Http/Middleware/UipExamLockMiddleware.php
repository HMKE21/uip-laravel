<?php

namespace App\Http\Middleware;

use App\Repositories\ExamAttemptRepository;
use App\Repositories\StudentRepository;
use Closure;
use Illuminate\Http\Request;

/**
 * وضع قفل الامتحان على مستوى السيرفر: الطالب اللي عنده محاولة in_progress
 * (وقتها لسه ماعداش) ما يقدرش يستخدم الـ AI Assistant خالص — حتى لو كلّم الـ API
 * مباشرة بعد ما الفرونت اخفى الزرار. لازم يتحط بعد uip.auth (محتاج uip_user_id/uip_role).
 * بيأثر على الطلاب بس؛ باقي الأدوار بتعدّي من غير أي query.
 */
class UipExamLockMiddleware
{
    public function __construct(
        private ExamAttemptRepository $attempts,
        private StudentRepository $students
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $next($request);
        }

        $student = $this->students->findByUserId((int) $request->attributes->get('uip_user_id'));
        if ($student && $this->attempts->activeAttemptForStudent($student->id)) {
            return response()->json([
                'success' => false,
                'message' => 'The AI assistant is disabled while an exam is in progress.',
                'data'    => ['code' => 'exam_in_progress'],
                'errors'  => null,
                'meta'    => (object) [],
            ], 423);
        }

        return $next($request);
    }
}
