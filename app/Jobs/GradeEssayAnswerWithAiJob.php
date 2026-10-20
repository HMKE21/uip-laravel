<?php

namespace App\Jobs;

use App\Services\ExamGradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Exam & Assessment System — Round 6 (Phase 38 — "AI grading may be
 * expensive. Do not block the entire application unnecessarily... use
 * [the] existing queue/job infrastructure"). أول Job فعلي جوه المشروع —
 * القيمة الافتراضية QUEUE_CONNECTION=database (config/queue.php) شغالة
 * من غير أي إعداد إضافي؛ لو محتاج تشغيل sync وقت التطوير، غيّرها في
 * .env بدل ما تعدّل هنا.
 *
 * بياخد exam_grade_id بس (مش موديل كامل — نفس سبب استخدام IDs عادية في
 * ExamAttemptService: تجنب أي stale-model serialization لو الصف اتغيّر
 * بين وقت الـ dispatch ووقت التنفيذ). كل منطق التصحيح الفعلي جوه
 * ExamGradingService::runAiGrading() — الـ Job هنا غلاف رفيع بس، وده
 * مقصود: باقي الكود (regrade، الفحص المباشر لو حبيت تشغّل بره الـ queue)
 * بينادي نفس الميثود مباشرة من غير الحاجة لـ Job على الإطلاق.
 *
 * handle() عمدًا مبيرميش أي استثناء لفوق — runAiGrading() نفسها بتلقط
 * أي فشل في الاتصال بالـ AI وتسجله في exam_ai_gradings (status=failed)
 * من غير ما تحط أي درجة وهمية. لو فشل غير متوقع (مثلاً DB connection)
 * حصل، الـ retry الطبيعي بتاع الـ queue (tries=2) هو الحماية.
 */
class GradeEssayAnswerWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(private int $examGradeId)
    {
    }

    public function handle(ExamGradingService $grading): void
    {
        $grading->runAiGrading($this->examGradeId);
    }
}
