<?php

namespace App\Http\Middleware;

use App\Repositories\AcademicStaffRepository;
use App\Repositories\SupervisorRepository;
use Closure;
use Illuminate\Http\Request;

/**
 * يخلّي حساب المشرف/المعيد (role='supervisor') يستخدم نفس سطح الدكتور
 * (نظام الامتحانات، بنوك الأسئلة، المواد، بروفايل عضو هيئة التدريس) من غير ما
 * نكرر ~15 كنترولر. بيشتغل بعد uip.auth مباشرة على الجروبات اللي المشرف
 * محتاجها بس:
 *
 *   1) بيتأكد إن للمشرف صف في academic_staff (بيتعمل أول مرة تلقائيًا من
 *      بيانات المشرف نفسه — الجامعة، الاسم، الرتبة "معيد") عشان ملكية
 *      الامتحانات/البنوك/النتائج كلها بتعتمد على academic_staff.id.
 *   2) بيحوّل uip_role من 'supervisor' إلى 'academic_staff' لباقي الريكويست
 *      (والأصلي محفوظ في uip_original_role).
 *
 * مفيش أي توسيع صلاحيات برا المسارات اللي الميدلوير متحط عليها، وأي مشرف
 * مش active بيعدّي على حاله (الكنترولرز بترفضه 403 زي الأول).
 */
class UipSupervisorAsStaffMiddleware
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private AcademicStaffRepository $staff
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('uip_role') === 'supervisor') {
            $userId = (int) $request->attributes->get('uip_user_id');
            $supervisor = $this->supervisors->findActiveByUserId($userId);

            if ($supervisor) {
                $this->staff->ensureForSupervisor($supervisor);
                $request->attributes->set('uip_original_role', 'supervisor');
                $request->attributes->set('uip_role', 'academic_staff');
            }
        }

        return $next($request);
    }
}
