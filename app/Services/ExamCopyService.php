<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionPool;
use App\Models\ExamTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * نسخ امتحان (إعادة استخدام امتحان الترم اللي فات) + قوالب.
 *
 * النسخة دايمًا draft وملكها نفس المدرس، وبتاخد: كل الإعدادات، الأسئلة اليدوية (بدرجاتها وترتيبها)، وإعدادات
 * الـ question pools. أسئلة الـ pools المسحوبة (source=pool) متتنسخش لأنها بتتكوّن وقت المحاولة. الأسئلة
 * المحذوفة من البنك بتتخطى (وبترجع في skipped_questions). مبتتنسخش: المحاولات، النتايج، التظلمات، التشابه،
 * استثناءات الطلاب، ولا تذكيرات. المواعيد بتتفضى (لازم تتحدد من جديد) إلا لو اتبعتت؛ الاستهداف (الطلاب)
 * بيتنسخ بس لو copy_targets=true لأن دفعة الترم الجديد غالبًا مختلفة. الأصل عمره ما بيتغير.
 */
class ExamCopyService
{
    public function __construct(private AuditLogService $auditLog)
    {
    }

    /**
     * @param array{title?:?string,start_at?:?string,end_at?:?string,academic_year?:?string,semester?:?string,copy_targets?:bool} $opts
     * @return array{exam:Exam,copied_questions:int,skipped_questions:int,copied_pools:int,copied_targets:int}
     */
    public function duplicate(Exam $source, $staff, array $opts = []): array
    {
        return DB::transaction(function () use ($source, $staff, $opts) {
            $copy = $source->replicate(['results_published_at', 'access_password']);
            $copy->title = trim($opts['title'] ?? '') !== '' ? trim($opts['title']) : $this->defaultTitle($source->title);
            $copy->status = 'draft';
            $copy->is_template = false;
            $copy->results_published_at = null;
            $copy->start_at = $this->toAppTimezone($opts['start_at'] ?? null);
            $copy->end_at = $this->toAppTimezone($opts['end_at'] ?? null);
            if (array_key_exists('academic_year', $opts) && $opts['academic_year'] !== null) {
                $copy->academic_year = $opts['academic_year'];
            }
            if (array_key_exists('semester', $opts) && $opts['semester'] !== null) {
                $copy->semester = $opts['semester'];
            }
            $copy->created_by_academic_staff_id = $staff->id;
            $copy->save();

            $copied = 0;
            $skipped = 0;
            $total = 0.0;
            $order = 0;
            $rows = ExamQuestion::with('question')->where('exam_id', $source->id)->where('source', 'manual')->orderBy('sort_order')->get();
            foreach ($rows as $row) {
                if (!$row->question) { // السؤال اتحذف من البنك
                    $skipped++;
                    continue;
                }
                ExamQuestion::create([
                    'exam_id' => $copy->id, 'question_id' => $row->question_id, 'marks_override' => $row->marks_override,
                    'sort_order' => $order++, 'source' => 'manual',
                ]);
                $total += (float) ($row->marks_override ?? $row->question->marks);
                $copied++;
            }

            $pools = 0;
            foreach (ExamQuestionPool::where('exam_id', $source->id)->orderBy('sort_order')->get() as $config) {
                $clone = $config->replicate();
                $clone->exam_id = $copy->id;
                $clone->save();
                $total += (float) $config->questions_to_select * (float) $config->marks_per_question;
                $pools++;
            }

            $targets = 0;
            if (!empty($opts['copy_targets'])) {
                foreach (ExamTarget::where('exam_id', $source->id)->get() as $t) {
                    ExamTarget::create([
                        'exam_id' => $copy->id, 'faculty_id' => $t->faculty_id, 'department_id' => $t->department_id,
                        'academic_year' => $t->academic_year, 'group_id' => $t->group_id,
                        'student_id' => $t->student_id,
                    ]);
                    $targets++;
                }
            }

            $copy->total_marks = round($total, 2);
            $copy->save();

            $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_duplicated', 'Exam', $copy->id, null,
                ['source_exam_id' => $source->id, 'questions' => $copied, 'skipped' => $skipped, 'pools' => $pools, 'targets' => $targets]);

            return ['exam' => $copy, 'copied_questions' => $copied, 'skipped_questions' => $skipped, 'copied_pools' => $pools, 'copied_targets' => $targets];
        });
    }

    /**
     * يعلّم الامتحان كقالب (أو يشيل العلامة). القالب لازم يفضل draft ومينفعش يتنشر طول ما هو قالب
     * (ExamSystemService::publishExam بيرفض).
     * @throws \InvalidArgumentException
     */
    public function setTemplate(Exam $exam, bool $isTemplate, $staff): Exam
    {
        if ($isTemplate && $exam->status !== 'draft') {
            throw new \InvalidArgumentException('Only a draft exam can be saved as a template. Duplicate it first, then mark the copy as a template.');
        }
        $exam->is_template = $isTemplate;
        $exam->save();
        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_template_' . ($isTemplate ? 'on' : 'off'), 'Exam', $exam->id);
        return $exam;
    }

    private function defaultTitle(string $title): string
    {
        return mb_substr('Copy of ' . $title, 0, 200);
    }

    private function toAppTimezone($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
