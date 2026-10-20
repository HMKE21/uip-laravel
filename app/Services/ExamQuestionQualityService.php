<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\QuestionBank;
use Illuminate\Support\Facades\DB;

/**
 * تحليل جودة السؤال (Item Analysis) — بيساعد المدرس يحسّن بنك الأسئلة.
 *
 * لكل سؤال، من المحاولات المصحّحة (status=graded) اللي ظهر فيها السؤال:
 *  - difficulty (p)   = مجموع الدرجات اللي الطلاب خدوها ÷ مجموع الدرجة الكاملة. بيشتغل مع كل الأنواع
 *                       (للموضوعي = نسبة اللي جاوبوا صح). 0 = محدش جاوب صح، 1 = الكل جاوب صح.
 *  - discrimination (D) = p عند أعلى 27% من الطلاب (حسب درجتهم الكلية في الامتحان) − p عند أدنى 27%.
 *                       بيقيس هل السؤال بيفرّق بين الطالب القوي والضعيف. سالب = الضعاف جاوبوه أحسن من
 *                       الأقوياء (غالبًا مفتاح إجابة غلط أو سؤال مضلل). مش بيتحسب لو العينة أقل من MIN_FOR_DISCRIMINATION.
 *  - options (للـ MCQ/multi_select/true_false) = كام % اختار كل اختيار، عند الكل وعند الأعلى والأدنى؛
 *                       مشتتات (distractors) محدش بيختارها = ملهاش لازمة.
 * محاولة الطالب بتتحسب ملاحظة مستقلة (لو الامتحان فيه أكتر من محاولة). المحاولات الملغية/غير المصححة مبتتحسبش.
 */
class ExamQuestionQualityService
{
    public const MIN_FOR_DISCRIMINATION = 10;
    private const GROUP_FRACTION = 0.27;
    private const OBJECTIVE_TYPES = ['mcq', 'multi_select', 'true_false'];

    // ---------------------------------------------------------------
    // Entry points
    // ---------------------------------------------------------------

    /** تحليل كل أسئلة امتحان واحد. */
    public function forExam(Exam $exam): array
    {
        $obs = $this->observations([(int) $exam->id]);
        $answers = $this->selectedOptions([(int) $exam->id]);
        $meta = $this->questionMeta([(int) $exam->id]);

        $byEq = [];
        foreach ($obs as $o) {
            $byEq[$o['exam_question_id']][] = $o;
        }

        $items = [];
        foreach ($meta as $eqId => $m) {
            $rows = $byEq[$eqId] ?? [];
            $stats = self::analyze($rows);
            $options = in_array($m['type'], self::OBJECTIVE_TYPES, true)
                ? $this->optionStats($m['options'], $rows, $stats['_upper'], $stats['_lower'], $answers)
                : null;
            unset($stats['_upper'], $stats['_lower']);
            $items[] = array_merge([
                'exam_question_id' => $eqId,
                'question_id'      => $m['question_id'],
                'type'             => $m['type'],
                'prompt'           => mb_strlen($m['prompt']) > 200 ? mb_substr($m['prompt'], 0, 200) . '…' : $m['prompt'],
                'max_marks'        => $m['max_marks'],
                'options'          => $options,
            ], $stats, self::verdict($stats, $options));
        }

        return [
            'exam_id'                  => $exam->id,
            'graded_attempts'          => count(array_unique(array_column($obs, 'attempt_id'))),
            'min_for_discrimination'   => self::MIN_FOR_DISCRIMINATION,
            'summary'                  => self::summary($items),
            'items'                    => $items,
        ];
    }

    /** تحليل أسئلة بنك كامل مجمّعة عبر كل الامتحانات اللي استخدمتها. */
    public function forBank(QuestionBank $bank): array
    {
        $questions = DB::table('questions')->where('question_bank_id', $bank->id)->whereNull('deleted_at')
            ->select('id', 'type', 'prompt', 'difficulty', 'topic')->orderBy('id')->get();
        $qIds = $questions->pluck('id')->all();

        $examIds = $qIds ? DB::table('exam_questions')->whereIn('question_id', $qIds)->distinct()->pluck('exam_id')->map(fn ($v) => (int) $v)->all() : [];
        $obs = $examIds ? array_values(array_filter($this->observations($examIds), fn ($o) => in_array($o['question_id'], $qIds, true))) : [];

        // التحليل لكل (امتحان، سؤال) على حدة — الأعلى/الأدنى لازم يتحددوا جوه نفس الامتحان.
        $groups = [];
        foreach ($obs as $o) {
            $groups[$o['question_id']][$o['exam_id']][] = $o;
        }

        $items = [];
        foreach ($questions as $q) {
            $perExam = $groups[$q->id] ?? [];
            $n = 0; $sumMarks = 0.0; $sumMax = 0.0; $dNum = 0.0; $dDen = 0;
            foreach ($perExam as $rows) {
                $s = self::analyze($rows);
                $n += $s['sample_size'];
                foreach ($rows as $r) { $sumMarks += $r['marks']; $sumMax += $r['max']; }
                if ($s['discrimination'] !== null) { $dNum += $s['discrimination'] * $s['sample_size']; $dDen += $s['sample_size']; }
            }
            $stats = [
                'sample_size'    => $n,
                'difficulty'     => $sumMax > 0 ? round($sumMarks / $sumMax, 3) : null,
                'discrimination' => $dDen > 0 ? round($dNum / $dDen, 3) : null,
            ];
            $items[] = array_merge([
                'question_id'      => (int) $q->id,
                'type'             => $q->type,
                'prompt'           => mb_strlen($q->prompt) > 200 ? mb_substr($q->prompt, 0, 200) . '…' : $q->prompt,
                'bank_difficulty'  => $q->difficulty,
                'topic'            => $q->topic,
                'exams_count'      => count($perExam),
            ], $stats, self::verdict($stats, null));
        }

        return [
            'bank_id'                => $bank->id,
            'min_for_discrimination' => self::MIN_FOR_DISCRIMINATION,
            'summary'                => self::summary($items),
            'items'                  => $items,
        ];
    }

    // ---------------------------------------------------------------
    // Pure maths (no DB) — بيتختبر لوحده
    // ---------------------------------------------------------------

    /**
     * @param array<int,array{attempt_id:int,marks:float,max:float,pct:float}> $rows ملاحظة لكل محاولة جاوبت السؤال
     * @return array{sample_size:int,difficulty:?float,discrimination:?float,upper_difficulty:?float,lower_difficulty:?float,correct_pct:?float,group_size:?int,_upper:array,_lower:array}
     */
    public static function analyze(array $rows): array
    {
        $n = count($rows);
        $sumMax = array_sum(array_column($rows, 'max'));
        $out = [
            'sample_size' => $n, 'difficulty' => null, 'discrimination' => null,
            'upper_difficulty' => null, 'lower_difficulty' => null, 'correct_pct' => null, 'group_size' => null,
            '_upper' => [], '_lower' => [],
        ];
        if ($n === 0 || $sumMax <= 0) {
            return $out;
        }
        $out['difficulty'] = round(array_sum(array_column($rows, 'marks')) / $sumMax, 3);
        $full = count(array_filter($rows, fn ($r) => $r['max'] > 0 && $r['marks'] >= $r['max'] - 1e-9));
        $out['correct_pct'] = round($full / $n * 100, 1);

        if ($n >= self::MIN_FOR_DISCRIMINATION) {
            usort($rows, fn ($a, $b) => $b['pct'] <=> $a['pct']);
            $g = max(1, (int) round($n * self::GROUP_FRACTION));
            $upper = array_slice($rows, 0, $g);
            $lower = array_slice($rows, -$g);
            $ratio = function (array $grp) {
                $mx = array_sum(array_column($grp, 'max'));
                return $mx > 0 ? array_sum(array_column($grp, 'marks')) / $mx : 0.0;
            };
            $out['group_size'] = $g;
            $out['upper_difficulty'] = round($ratio($upper), 3);
            $out['lower_difficulty'] = round($ratio($lower), 3);
            $out['discrimination'] = round($ratio($upper) - $ratio($lower), 3);
            $out['_upper'] = array_column($upper, 'attempt_id');
            $out['_lower'] = array_column($lower, 'attempt_id');
        }
        return $out;
    }

    /** @return array{flags:string[],verdict:string,discrimination_label:?string,difficulty_label:?string} */
    public static function verdict(array $s, ?array $options): array
    {
        $flags = [];
        $p = $s['difficulty'];
        $d = $s['discrimination'];

        if ($s['sample_size'] === 0 || $p === null) {
            return ['flags' => ['no_data'], 'verdict' => 'insufficient', 'difficulty_label' => null, 'discrimination_label' => null];
        }
        $diffLabel = $p < 0.20 ? 'very_hard' : ($p < 0.40 ? 'hard' : ($p > 0.90 ? 'very_easy' : ($p > 0.80 ? 'easy' : 'good')));
        // عينة أقل من الحد الأدنى = الأرقام مش موثوقة، نعرضها بس من غير أحكام.
        if ($s['sample_size'] < self::MIN_FOR_DISCRIMINATION) {
            return ['flags' => ['low_sample'], 'verdict' => 'insufficient', 'difficulty_label' => $diffLabel, 'discrimination_label' => null];
        }
        if ($p < 0.20) { $flags[] = 'too_hard'; }
        if ($p > 0.90) { $flags[] = 'too_easy'; }
        if ($d === null) {
            $flags[] = 'low_sample';
        }
        $discLabel = null;
        if ($d !== null) {
            $discLabel = $d >= 0.40 ? 'excellent' : ($d >= 0.30 ? 'good' : ($d >= 0.20 ? 'acceptable' : ($d >= 0 ? 'poor' : 'negative')));
            if ($d < 0) {
                $flags[] = 'negative_discrimination';
            } elseif ($d < 0.20 && !in_array('too_easy', $flags, true) && !in_array('too_hard', $flags, true)) {
                // سقف/قاع (الكل صح أو الكل غلط) بيدّي D=0 طبيعي، فمبنعتبرهوش ضعف تمييز لوحده.
                $flags[] = 'low_discrimination';
            }
        }
        foreach ($options ?? [] as $o) {
            if (!$o['is_correct'] && ($o['weak'] ?? false)) { $flags[] = 'weak_distractor'; break; }
        }

        if (in_array('negative_discrimination', $flags, true)) {
            $verdict = 'revise';
        } elseif (array_intersect($flags, ['too_hard', 'too_easy', 'low_discrimination', 'weak_distractor'])) {
            $verdict = 'review';
        } elseif ($d === null) {
            $verdict = 'insufficient';
        } else {
            $verdict = 'good';
        }
        return ['flags' => $flags, 'verdict' => $verdict, 'difficulty_label' => $diffLabel, 'discrimination_label' => $discLabel];
    }

    private static function summary(array $items): array
    {
        $c = ['good' => 0, 'review' => 0, 'revise' => 0, 'insufficient' => 0];
        foreach ($items as $i) {
            $c[$i['verdict']]++;
        }
        return $c;
    }

    // ---------------------------------------------------------------
    // Data loading
    // ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function observations(array $examIds): array
    {
        $rows = DB::table('exam_grades as g')
            ->join('exam_attempts as a', 'a.id', '=', 'g.exam_attempt_id')
            ->join('exam_questions as eq', 'eq.id', '=', 'g.exam_question_id')
            ->whereIn('a.exam_id', $examIds)
            ->where('a.status', 'graded')
            ->whereNotNull('a.percentage')
            ->whereNotNull('g.marks_awarded')
            ->where('g.max_marks', '>', 0)
            ->select('a.exam_id', 'g.exam_attempt_id', 'g.exam_question_id', 'eq.question_id', 'g.marks_awarded', 'g.max_marks', 'a.percentage')
            ->get();

        return $rows->map(fn ($r) => [
            'exam_id'          => (int) $r->exam_id,
            'attempt_id'       => (int) $r->exam_attempt_id,
            'exam_question_id' => (int) $r->exam_question_id,
            'question_id'      => (int) $r->question_id,
            'marks'            => (float) $r->marks_awarded,
            'max'              => (float) $r->max_marks,
            'pct'              => (float) $r->percentage,
        ])->all();
    }

    /** @return array<int,array<string,mixed>> exam_question_id => meta (+ options) */
    private function questionMeta(array $examIds): array
    {
        $rows = DB::table('exam_questions as eq')
            ->join('questions as q', 'q.id', '=', 'eq.question_id')
            ->whereIn('eq.exam_id', $examIds)
            ->orderBy('eq.sort_order')->orderBy('eq.id')
            ->select('eq.id as eq_id', 'eq.marks_override', 'q.id as question_id', 'q.type', 'q.prompt', 'q.marks')
            ->get();
        $qIds = $rows->pluck('question_id')->unique()->all();
        $opts = $qIds ? DB::table('question_options')->whereIn('question_id', $qIds)->orderBy('sort_order')->orderBy('id')->get()->groupBy('question_id') : collect();

        $meta = [];
        foreach ($rows as $r) {
            $meta[(int) $r->eq_id] = [
                'question_id' => (int) $r->question_id,
                'type'        => $r->type,
                'prompt'      => (string) $r->prompt,
                'max_marks'   => (float) ($r->marks_override ?? $r->marks),
                'options'     => ($opts[$r->question_id] ?? collect())->map(fn ($o) => [
                    'id' => (int) $o->id, 'text' => $o->option_text, 'is_correct' => (bool) $o->is_correct,
                ])->values()->all(),
            ];
        }
        return $meta;
    }

    /** @return array<int,array<int,int[]>> exam_question_id => attempt_id => selected option ids */
    private function selectedOptions(array $examIds): array
    {
        $out = [];
        $rows = DB::table('exam_answers as ans')
            ->join('exam_attempts as a', 'a.id', '=', 'ans.exam_attempt_id')
            ->whereIn('a.exam_id', $examIds)->where('a.status', 'graded')
            ->select('ans.exam_question_id', 'ans.exam_attempt_id', 'ans.selected_option_ids')
            ->get();
        foreach ($rows as $r) {
            $ids = json_decode($r->selected_option_ids ?? '[]', true);
            $out[(int) $r->exam_question_id][(int) $r->exam_attempt_id] = array_map('intval', is_array($ids) ? $ids : []);
        }
        return $out;
    }

    private function optionStats(array $options, array $rows, array $upperIds, array $lowerIds, array $answers): array
    {
        if (!$options || !$rows) {
            return array_map(fn ($o) => $o + ['selected_pct' => null, 'upper_pct' => null, 'lower_pct' => null, 'weak' => false], $options);
        }
        $eqId = $rows[0]['exam_question_id'];
        $attemptIds = array_column($rows, 'attempt_id');
        $n = count($attemptIds);
        $pct = function (int $optId, array $ids) use ($eqId, $answers) {
            if (!$ids) { return null; }
            $c = 0;
            foreach ($ids as $aid) {
                if (in_array($optId, $answers[$eqId][$aid] ?? [], true)) { $c++; }
            }
            return round($c / count($ids) * 100, 1);
        };
        return array_map(function ($o) use ($pct, $attemptIds, $upperIds, $lowerIds, $n) {
            $sel = $pct($o['id'], $attemptIds);
            return $o + [
                'selected_pct' => $sel,
                'upper_pct'    => $pct($o['id'], $upperIds),
                'lower_pct'    => $pct($o['id'], $lowerIds),
                // مشتت محدش (أقل من 5%) بيختاره، ومحتاج عينة 20+ عشان الحكم يبقى معقول.
                'weak'         => !$o['is_correct'] && $n >= 20 && $sel !== null && $sel < 5.0,
            ];
        }, $options);
    }
}
