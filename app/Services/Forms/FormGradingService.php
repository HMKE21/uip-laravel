<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormResponse;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Auto-grading for gradable questions + total score + manual grading for the rest. */
class FormGradingService
{
    public function isGraded(Form $form, Collection $fields): bool
    {
        return $form->isExam() || $fields->contains(fn ($f) => (float) $f->points > 0);
    }

    /** @return array{is_correct:?bool,points:?float,manual:bool} points = null => needs manual grading */
    public function gradeField(FormField $f, $value): array
    {
        $max = (float) $f->points;
        if ($max <= 0 || FormFieldTypes::isLayout($f->type)) {
            return ['is_correct' => null, 'points' => 0.0, 'manual' => false];
        }
        $correct = $f->correct;
        if (!FormFieldTypes::autoGradable($f->type) || $correct === null || $correct === []) {
            return ['is_correct' => null, 'points' => null, 'manual' => true];
        }
        if ($value === null || $value === '' || $value === []) {
            return ['is_correct' => false, 'points' => 0.0, 'manual' => false];
        }

        switch ($f->type) {
            case 'single_choice':
            case 'dropdown':
                $ok = in_array($value, $correct, true);
                break;
            case 'multi_choice':
                $a = array_map('strval', (array) $value);
                $b = array_map('strval', $correct);
                sort($a);
                sort($b);
                if ($a === $b) {
                    $ok = true;
                } else {
                    // partial credit: right picks minus wrong picks, floor at 0
                    $right = count(array_intersect($a, $b));
                    $wrong = count(array_diff($a, $b));
                    $pts = round(max(0, ($right - $wrong) / max(1, count($b))) * $max, 2);
                    return ['is_correct' => false, 'points' => $pts, 'manual' => false];
                }
                break;
            case 'true_false':
                $ok = (bool) $value === (bool) $correct[0];
                break;
            case 'number':
                $ok = false;
                foreach ($correct as $c) {
                    if (is_numeric($c) && abs((float) $value - (float) $c) < 0.0000001) {
                        $ok = true;
                    }
                }
                break;
            case 'short_text':
                $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
                $ok = false;
                foreach ($correct as $c) {
                    if ($norm($c) === $norm($value)) {
                        $ok = true;
                    }
                }
                break;
            default: // scale / rating
                $ok = in_array((int) $value, array_map('intval', $correct), true);
        }
        return ['is_correct' => $ok, 'points' => $ok ? $max : 0.0, 'manual' => false];
    }

    /** Grade every answer of a response and store the total. */
    public function gradeResponse(Form $form, FormResponse $response, Collection $fields): FormResponse
    {
        if (!$this->isGraded($form, $fields)) {
            return $response;
        }
        $answers = DB::table('form_answers')->where('response_id', $response->id)->get()->keyBy('field_id');
        $total = 0.0;
        $max = 0.0;
        $manual = false;
        foreach ($fields as $f) {
            if ((float) $f->points <= 0 || FormFieldTypes::isLayout($f->type)) {
                continue;
            }
            $max += (float) $f->points;
            $row = $answers->get($f->id);
            $val = $row ? json_decode($row->value, true) : null;
            if ($row && $row->points !== null && $this->isManualRow($f)) {
                $total += (float) $row->points; // keep what the grader already gave
                continue;
            }
            $g = $this->gradeField($f, $val);
            if ($g['manual']) {
                $manual = $manual || ($row !== null && $val !== null);
                $total += $row && $row->points !== null ? (float) $row->points : 0;
                continue;
            }
            $total += (float) $g['points'];
            if ($row) {
                DB::table('form_answers')->where('id', $row->id)->update(['is_correct' => $g['is_correct'], 'points' => $g['points']]);
            }
        }
        $response->score = round($total, 2);
        $response->max_score = round($max, 2);
        $response->needs_manual = $manual && !$this->allManualGraded($response, $fields);
        if (!$response->needs_manual && $response->status === 'submitted') {
            $response->status = 'graded';
        }
        $response->save();
        return $response;
    }

    private function isManualRow(FormField $f): bool
    {
        return !FormFieldTypes::autoGradable($f->type) || empty($f->correct);
    }

    private function allManualGraded(FormResponse $r, Collection $fields): bool
    {
        $manualIds = $fields->filter(fn ($f) => (float) $f->points > 0 && $this->isManualRow($f))->pluck('id');
        if ($manualIds->isEmpty()) {
            return true;
        }
        $pending = DB::table('form_answers')->where('response_id', $r->id)->whereIn('field_id', $manualIds)->whereNull('points')->count();
        return $pending === 0;
    }

    /** Teacher grades one answer manually. */
    public function manualGrade(Form $form, FormResponse $response, int $fieldId, float $points, ?string $feedback): FormResponse
    {
        $field = FormField::where('form_id', $form->id)->where('id', $fieldId)->first();
        if (!$field || (float) $field->points <= 0) {
            throw new FormException('not_gradable', 'This question has no points.', 422);
        }
        $points = max(0, min((float) $field->points, $points));
        DB::table('form_answers')->updateOrInsert(
            ['response_id' => $response->id, 'field_id' => $fieldId],
            ['points' => $points, 'feedback' => $feedback !== null ? mb_substr($feedback, 0, 3000) : null, 'updated_at' => now(), 'created_at' => now()]
        );
        return $this->gradeResponse($form, $response->fresh(), $form->fields()->get());
    }
}
