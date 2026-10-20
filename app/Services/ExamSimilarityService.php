<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamSimilarityFlag;
use App\Support\TextSimilarity;
use Illuminate\Support\Facades\DB;

/**
 * كشف التشابه بين إجابات short_answer/essay جوه امتحان واحد. المقارنة بين طالبين مختلفين بس،
 * على نفس السؤال، ومحاولات منتهية (مش in_progress/cancelled). الإشارات للمراجعة البشرية — مفيش أي
 * تأثير على الدرجة أو المحاولة. الحدود (سقف عدد الإجابات لكل سؤال، أقل عدد كلمات) بتحمي من الـ O(n²).
 */
class ExamSimilarityService
{
    public const MIN_WORDS = 20;
    public const MAX_ANSWERS_PER_QUESTION = 300;
    public const DEFAULT_THRESHOLD = 60.0; // نسبة %
    public const STATUSES = ['pending', 'confirmed', 'dismissed'];

    public function __construct(private AuditLogService $auditLog)
    {
    }

    /** @return array{flags_found:int,questions_analyzed:int,truncated_questions:int[]} */
    public function analyze(Exam $exam, float $threshold, $staff): array
    {
        $threshold = max(30.0, min(100.0, $threshold));
        $questions = DB::table('exam_questions as eq')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->where('eq.exam_id', $exam->id)
            ->whereIn('q.type', ['short_answer', 'essay'])
            ->pluck('eq.id')->all();

        $found = 0;
        $truncated = [];
        foreach ($questions as $eqId) {
            $rows = DB::table('exam_answers as ans')
                ->join('exam_attempts as a', 'a.id', '=', 'ans.exam_attempt_id')
                ->where('a.exam_id', $exam->id)
                ->where('ans.exam_question_id', $eqId)
                ->whereIn('a.status', ['submitted', 'auto_submitted', 'expired', 'grading', 'graded'])
                ->whereNotNull('ans.answer_text')
                ->select('ans.exam_attempt_id', 'a.student_id', 'ans.answer_text')
                ->orderBy('ans.id')
                ->limit(self::MAX_ANSWERS_PER_QUESTION + 1)
                ->get();
            if ($rows->count() > self::MAX_ANSWERS_PER_QUESTION) {
                $truncated[] = (int) $eqId;
                $rows = $rows->take(self::MAX_ANSWERS_PER_QUESTION);
            }

            $docs = [];
            foreach ($rows as $r) {
                $words = TextSimilarity::words((string) $r->answer_text);
                if (count($words) < self::MIN_WORDS) {
                    continue;
                }
                $docs[] = ['attempt' => (int) $r->exam_attempt_id, 'student' => (int) $r->student_id, 'sh' => TextSimilarity::shingles($words)];
            }

            $n = count($docs);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($docs[$i]['student'] === $docs[$j]['student']) {
                        continue; // محاولتين لنفس الطالب مش غش
                    }
                    $res = TextSimilarity::compare($docs[$i]['sh'], $docs[$j]['sh']);
                    if ($res['similarity'] < $threshold) {
                        continue;
                    }
                    [$a, $b] = $docs[$i]['attempt'] < $docs[$j]['attempt']
                        ? [$docs[$i]['attempt'], $docs[$j]['attempt']] : [$docs[$j]['attempt'], $docs[$i]['attempt']];
                    $flag = ExamSimilarityFlag::firstOrNew(['exam_question_id' => $eqId, 'attempt_a_id' => $a, 'attempt_b_id' => $b]);
                    $flag->exam_id = $exam->id;
                    $flag->similarity = $res['similarity'];
                    $flag->matched_words = $res['matched_words'];
                    if (!$flag->exists) {
                        $flag->status = 'pending';
                    }
                    $flag->save();
                    $found++;
                }
            }
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.similarity_analyzed', 'Exam', $exam->id, null,
            ['threshold' => $threshold, 'flags' => $found]);

        return ['flags_found' => $found, 'questions_analyzed' => count($questions), 'truncated_questions' => $truncated];
    }

    /** @return array<int,array<string,mixed>> الأعلى تشابهًا أولًا، بإجابتي الطرفين. */
    public function listForExam(Exam $exam, ?string $status = null): array
    {
        $q = DB::table('exam_similarity_flags as f')
            ->join('exam_attempts as aa', 'aa.id', '=', 'f.attempt_a_id')
            ->join('exam_attempts as ab', 'ab.id', '=', 'f.attempt_b_id')
            ->join('students as sa', 'sa.id', '=', 'aa.student_id')
            ->join('students as sb', 'sb.id', '=', 'ab.student_id')
            ->join('users as ua', 'ua.id', '=', 'sa.user_id')
            ->join('users as ub', 'ub.id', '=', 'sb.user_id')
            ->join('exam_questions as eq', 'eq.id', '=', 'f.exam_question_id')
            ->join('questions as qq', 'qq.id', '=', 'eq.question_id')
            ->leftJoin('exam_answers as ansa', fn ($j) => $j->on('ansa.exam_attempt_id', '=', 'f.attempt_a_id')->on('ansa.exam_question_id', '=', 'f.exam_question_id'))
            ->leftJoin('exam_answers as ansb', fn ($j) => $j->on('ansb.exam_attempt_id', '=', 'f.attempt_b_id')->on('ansb.exam_question_id', '=', 'f.exam_question_id'))
            ->where('f.exam_id', $exam->id)
            ->orderByDesc('f.similarity')
            ->select(
                'f.id', 'f.exam_question_id', 'f.attempt_a_id', 'f.attempt_b_id', 'f.similarity', 'f.matched_words',
                'f.status', 'f.reviewed_at', 'f.review_note',
                'qq.prompt as question_prompt',
                'sa.student_number as student_a_number', 'ua.full_name as student_a_name', 'ansa.answer_text as answer_a',
                'sb.student_number as student_b_number', 'ub.full_name as student_b_name', 'ansb.answer_text as answer_b'
            );
        if ($status !== null) {
            $q->where('f.status', $status);
        }

        return $q->limit(500)->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'exam_question_id' => (int) $r->exam_question_id,
            'question_prompt' => $r->question_prompt,
            'similarity' => (float) $r->similarity,
            'matched_words' => (int) $r->matched_words,
            'status' => $r->status,
            'review_note' => $r->review_note,
            'reviewed_at' => $r->reviewed_at,
            'a' => ['attempt_id' => (int) $r->attempt_a_id, 'student_number' => $r->student_a_number, 'student_name' => $r->student_a_name, 'answer' => $r->answer_a],
            'b' => ['attempt_id' => (int) $r->attempt_b_id, 'student_number' => $r->student_b_number, 'student_name' => $r->student_b_name, 'answer' => $r->answer_b],
        ])->all();
    }

    /** @return array<string,int> status => عدد. */
    public function countsForExam(Exam $exam): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (DB::table('exam_similarity_flags')->where('exam_id', $exam->id)->select('status', DB::raw('count(*) as c'))->groupBy('status')->get() as $r) {
            $counts[$r->status] = (int) $r->c;
        }
        return $counts;
    }

    /** @throws \InvalidArgumentException */
    public function review(Exam $exam, int $flagId, string $status, ?string $note, $staff): ExamSimilarityFlag
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        // الملكية: الإشارة لازم تتبع الامتحان ده (اللي المدرس أثبت إنه بتاعه) — مش بس أي id.
        $flag = ExamSimilarityFlag::where('id', $flagId)->where('exam_id', $exam->id)->first();
        if (!$flag) {
            throw new \InvalidArgumentException('Flag not found.');
        }
        $old = $flag->status;
        $flag->status = $status;
        $flag->review_note = $note;
        $flag->reviewed_by_academic_staff_id = $status === 'pending' ? null : $staff->id;
        $flag->reviewed_at = $status === 'pending' ? null : now();
        $flag->save();

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.similarity_reviewed', 'ExamSimilarityFlag', $flag->id,
            ['status' => $old], ['status' => $status]);

        return $flag;
    }
}
