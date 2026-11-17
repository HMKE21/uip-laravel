<?php

namespace App\Services\Forms;

use App\Helpers\UserAgentParser;
use App\Models\Form;
use App\Models\FormResponse;
use App\Support\Forms\EgyptianNationalId;
use App\Support\Forms\EgyptianPhone;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Support\Facades\DB;

/** Charts data + per-question stats + response browsing + CSV export for a form's owner. */
class FormAnalyticsService
{
    private const FINAL = ['submitted', 'graded', 'auto_submitted'];

    public function __construct(private FormFileService $files)
    {
    }

    public function summary(Form $form): array
    {
        $fields = $form->fields()->get();
        $base = DB::table('form_responses')->where('form_id', $form->id);
        $final = (clone $base)->whereIn('status', self::FINAL);
        $total = (clone $final)->count();
        $inProgress = (clone $base)->where('status', 'in_progress')->count();

        $perDay = (clone $final)->where('submitted_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(submitted_at) as d, COUNT(*) as c')->groupBy('d')->orderBy('d')->pluck('c', 'd')->all();
        $timeline = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $timeline[] = ['date' => $d, 'count' => (int) ($perDay[$d] ?? 0)];
        }

        $byHour = array_fill(0, 24, 0);
        foreach ((clone $final)->whereNotNull('submitted_at')->pluck('submitted_at') as $t) {
            $byHour[(int) date('G', strtotime((string) $t))]++;
        }

        $devices = [];
        $browsers = [];
        foreach ((clone $final)->pluck('user_agent') as $ua) {
            $p = UserAgentParser::parse($ua ?: null);
            $devices[$p['device']] = ($devices[$p['device']] ?? 0) + 1;
            $browsers[$p['browser']] = ($browsers[$p['browser']] ?? 0) + 1;
        }
        $roles = (clone $final)->selectRaw("COALESCE(role, 'guest') as r, COUNT(*) as c")->groupBy('r')->pluck('c', 'r')->all();

        $avgDur = (clone $final)->whereNotNull('duration_seconds')->avg('duration_seconds');
        $out = [
            'totals' => [
                'responses' => $total, 'in_progress' => $inProgress,
                'completion_rate' => ($total + $inProgress) > 0 ? round($total / ($total + $inProgress) * 100, 1) : null,
                'avg_duration_seconds' => $avgDur !== null ? (int) round($avgDur) : null,
                'last_response_at' => $form->last_response_at?->toIso8601String(),
                'edited' => (clone $final)->where('edits_count', '>', 0)->count(),
            ],
            'timeline' => $timeline,
            'by_hour' => array_map(fn ($h, $c) => ['hour' => $h, 'count' => $c], array_keys($byHour), $byHour),
            'devices' => $this->pairs($devices),
            'browsers' => $this->pairs($browsers),
            'roles' => $this->pairs($roles),
            'questions' => $this->questionStats($form, $fields),
        ];

        $graded = (new FormGradingService())->isGraded($form, $fields);
        if ($graded) {
            $out['exam'] = $this->examStats($form, $fields);
        }
        return $out;
    }

    private function pairs(array $m): array
    {
        arsort($m);
        return array_map(fn ($k, $v) => ['label' => (string) $k, 'count' => (int) $v], array_keys($m), $m);
    }

    private function questionStats(Form $form, $fields): array
    {
        $final = DB::table('form_responses')->where('form_id', $form->id)->whereIn('status', self::FINAL)->pluck('id')->all();
        $totalFinal = count($final);
        $out = [];
        foreach ($fields as $f) {
            if (FormFieldTypes::isLayout($f->type)) {
                continue;
            }
            $rows = $final ? DB::table('form_answers')->where('field_id', $f->id)->whereIn('response_id', $final)->get(['value', 'is_correct', 'points']) : collect();
            $vals = $rows->map(fn ($r) => json_decode($r->value, true))->filter(fn ($v) => $v !== null && $v !== '' && $v !== []);
            $answered = $vals->count();
            $stat = ['field_id' => (int) $f->id, 'type' => $f->type, 'label' => $f->label, 'answered' => $answered, 'skipped' => max(0, $totalFinal - $answered)];
            $cfg = (array) $f->config;

            switch ($f->type) {
                case 'single_choice':
                case 'dropdown':
                case 'multi_choice':
                    $labels = collect((array) ($cfg['options'] ?? []))->pluck('label', 'id')->all();
                    $counts = [];
                    foreach ($vals as $v) {
                        foreach ((array) $v as $x) {
                            $key = str_starts_with((string) $x, 'other:') ? 'other' : (string) $x;
                            $counts[$key] = ($counts[$key] ?? 0) + 1;
                        }
                    }
                    $stat['distribution'] = [];
                    foreach ($labels as $id => $label) {
                        $stat['distribution'][] = ['id' => $id, 'label' => $label, 'count' => (int) ($counts[$id] ?? 0), 'correct' => in_array($id, (array) $f->correct, true)];
                    }
                    if (isset($counts['other'])) {
                        $stat['distribution'][] = ['id' => 'other', 'label' => 'Other', 'count' => $counts['other'], 'correct' => false];
                    }
                    break;
                case 'true_false':
                    $t = $vals->filter(fn ($v) => $v === true)->count();
                    $stat['distribution'] = [['label' => 'true', 'count' => $t], ['label' => 'false', 'count' => $answered - $t]];
                    break;
                case 'scale':
                case 'rating':
                    $min = $f->type === 'scale' ? (int) ($cfg['min'] ?? 1) : 1;
                    $max = (int) ($cfg['max'] ?? 5);
                    $c = array_fill($min, $max - $min + 1, 0);
                    foreach ($vals as $v) {
                        if (isset($c[(int) $v])) {
                            $c[(int) $v]++;
                        }
                    }
                    $stat['distribution'] = array_map(fn ($k, $n) => ['label' => (string) $k, 'count' => $n], array_keys($c), $c);
                    $stat['average'] = $answered ? round($vals->avg(), 2) : null;
                    if ($f->type === 'scale' && $min === 0 && $max === 10 && $answered) {
                        $prom = $vals->filter(fn ($v) => $v >= 9)->count();
                        $det = $vals->filter(fn ($v) => $v <= 6)->count();
                        $stat['nps'] = (int) round(($prom - $det) / $answered * 100);
                    }
                    break;
                case 'number':
                    $stat['min'] = $answered ? $vals->min() : null;
                    $stat['max'] = $answered ? $vals->max() : null;
                    $stat['average'] = $answered ? round($vals->avg(), 2) : null;
                    break;
                case 'email':
                    $d = [];
                    foreach ($vals as $v) {
                        $dom = substr(strrchr((string) $v, '@') ?: '@?', 1);
                        $d[$dom] = ($d[$dom] ?? 0) + 1;
                    }
                    $stat['distribution'] = array_slice($this->pairs($d), 0, 10);
                    break;
                case 'phone':
                    $d = [];
                    foreach ($vals as $v) {
                        $op = EgyptianPhone::operator((string) $v) ?? 'unknown';
                        $d[$op] = ($d[$op] ?? 0) + 1;
                    }
                    $stat['distribution'] = $this->pairs($d);
                    break;
                case 'national_id':
                    $gov = [];
                    $gen = [];
                    $age = ['<18' => 0, '18-24' => 0, '25-34' => 0, '35-44' => 0, '45+' => 0];
                    foreach ($vals as $v) {
                        $i = EgyptianNationalId::parse((string) $v);
                        if (!$i) {
                            continue;
                        }
                        $gov[$i['governorate']] = ($gov[$i['governorate']] ?? 0) + 1;
                        $gen[$i['gender']] = ($gen[$i['gender']] ?? 0) + 1;
                        $a = $i['age'];
                        $age[$a < 18 ? '<18' : ($a < 25 ? '18-24' : ($a < 35 ? '25-34' : ($a < 45 ? '35-44' : '45+')))]++;
                    }
                    $stat['governorates'] = array_slice($this->pairs($gov), 0, 15);
                    $stat['gender'] = $this->pairs($gen);
                    $stat['ages'] = $this->pairs($age);
                    break;
                case 'date':
                    $stat['min'] = $answered ? $vals->min() : null;
                    $stat['max'] = $answered ? $vals->max() : null;
                    break;
                case 'file':
                    $q = DB::table('form_files')->where('field_id', $f->id)->whereNotNull('response_id');
                    $stat['files'] = (clone $q)->count();
                    $stat['total_bytes'] = (int) (clone $q)->sum('size_bytes');
                    $stat['kinds'] = $this->pairs((clone $q)->selectRaw('kind, COUNT(*) as c')->groupBy('kind')->pluck('c', 'kind')->all());
                    break;
                default: // text-like: latest samples + top words
                    $stat['samples'] = $vals->take(-8)->map(fn ($v) => mb_substr((string) $v, 0, 200))->values()->all();
                    if (in_array($f->type, ['short_text', 'long_text'], true) && $answered >= 3) {
                        $stat['top_words'] = $this->topWords($vals->all());
                    }
            }

            if ((float) $f->points > 0) {
                $graded = $rows->filter(fn ($r) => $r->points !== null);
                $stat['points'] = (float) $f->points;
                $stat['correct_rate'] = $graded->count() ? round($rows->filter(fn ($r) => $r->is_correct === 1 || $r->is_correct === true)->count() / $graded->count() * 100, 1) : null;
                $stat['avg_points'] = $graded->count() ? round($graded->avg('points'), 2) : null;
            }
            $out[] = $stat;
        }
        return $out;
    }

    private function topWords(array $texts): array
    {
        $stop = ['the', 'and', 'for', 'with', 'this', 'that', 'من', 'في', 'على', 'إلى', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ان', 'أن', 'لا', 'ما', 'هو', 'هي'];
        $c = [];
        foreach ($texts as $t) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $t), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                if (mb_strlen($w) < 3 || in_array($w, $stop, true)) {
                    continue;
                }
                $c[$w] = ($c[$w] ?? 0) + 1;
            }
        }
        arsort($c);
        return array_slice($this->pairs($c), 0, 15);
    }

    private function examStats(Form $form, $fields): array
    {
        $rows = DB::table('form_responses')->where('form_id', $form->id)->whereIn('status', self::FINAL)->whereNotNull('max_score')->get(['id', 'score', 'max_score', 'duration_seconds', 'violations', 'needs_manual']);
        $pcts = $rows->map(fn ($r) => $r->max_score > 0 ? $r->score / $r->max_score * 100 : 0);
        $pass = (int) $form->setting('exam.passing_percent', 50);
        $buckets = array_fill(0, 10, 0);
        foreach ($pcts as $p) {
            $buckets[min(9, (int) floor($p / 10))]++;
        }
        $sorted = $pcts->sort()->values();
        $n = $sorted->count();
        return [
            'graded_count' => $n,
            'pending_manual' => $rows->where('needs_manual', 1)->count(),
            'average_percent' => $n ? round($pcts->avg(), 1) : null,
            'median_percent' => $n ? round($sorted[(int) floor(($n - 1) / 2)] + (($n % 2 === 0) ? ($sorted[(int) ($n / 2)] - $sorted[(int) floor(($n - 1) / 2)]) / 2 : 0), 1) : null,
            'highest_percent' => $n ? round($pcts->max(), 1) : null,
            'lowest_percent' => $n ? round($pcts->min(), 1) : null,
            'pass_percent' => $pass,
            'pass_rate' => $n ? round($pcts->filter(fn ($p) => $p >= $pass)->count() / $n * 100, 1) : null,
            'distribution' => array_map(fn ($i, $c) => ['label' => ($i * 10) . '-' . ($i === 9 ? 100 : $i * 10 + 9), 'count' => $c], array_keys($buckets), $buckets),
            'with_violations' => $rows->where('violations', '>', 0)->count(),
            'avg_violations' => $n ? round($rows->avg('violations'), 2) : null,
            'hardest_questions' => collect($this->questionStats($form, $fields))->filter(fn ($q) => isset($q['correct_rate']) && $q['correct_rate'] !== null)
                ->sortBy('correct_rate')->take(5)->map(fn ($q) => ['field_id' => $q['field_id'], 'label' => $q['label'], 'correct_rate' => $q['correct_rate']])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ browsing responses

    public function responsesPage(Form $form, array $f): array
    {
        $q = DB::table('form_responses as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->where('r.form_id', $form->id);
        $status = $f['status'] ?? null;
        if ($status === 'final' || $status === null) {
            $q->whereIn('r.status', self::FINAL);
        } elseif ($status !== 'all') {
            $q->where('r.status', $status);
        }
        if (!empty($f['search'])) {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['search']) . '%';
            $q->where(fn ($w) => $w->where('r.email', 'like', $s)->orWhere('r.phone', 'like', $s)->orWhere('u.full_name', 'like', $s)->orWhere('r.national_id', 'like', $s));
        }
        if (!empty($f['needs_manual'])) {
            $q->where('r.needs_manual', 1);
        }
        if (!empty($f['flagged'])) {
            $q->where('r.violations', '>', 0);
        }
        $sortMap = ['submitted_at' => 'r.submitted_at', 'score' => 'r.score', 'duration' => 'r.duration_seconds', 'violations' => 'r.violations'];
        $col = $sortMap[$f['sort'] ?? 'submitted_at'] ?? 'r.submitted_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $total = (clone $q)->count();
        $per = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $items = $q->orderBy($col, $dir)->orderBy('r.id', 'desc')->forPage($page, $per)
            ->get(['r.id', 'r.uuid', 'r.status', 'r.email', 'r.phone', 'r.role', 'r.submitted_at', 'r.started_at', 'r.duration_seconds', 'r.score', 'r.max_score', 'r.needs_manual', 'r.violations', 'r.edits_count', 'u.full_name'])
            ->map(fn ($r) => (array) $r)->all();
        return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total, 'total_pages' => (int) ceil($total / $per)];
    }

    public function responseDetail(Form $form, FormResponse $r): array
    {
        $fields = $form->fields()->get();
        $ans = DB::table('form_answers')->where('response_id', $r->id)->get()->keyBy('field_id');
        $fileRows = \App\Models\FormFile::where('response_id', $r->id)->get()->keyBy('id');
        $items = [];
        foreach ($fields as $f) {
            if (FormFieldTypes::isLayout($f->type)) {
                continue;
            }
            $a = $ans->get($f->id);
            $val = $a ? json_decode($a->value, true) : null;
            $display = $this->display($f, $val, $fileRows);
            $items[] = [
                'field_id' => (int) $f->id, 'type' => $f->type, 'label' => $f->label, 'value' => $val, 'display' => $display,
                'files' => $f->type === 'file' && $val ? collect((array) $val)->map(fn ($id) => $fileRows->get($id))->filter()->map(fn ($x) => $this->files->toArray($x))->values()->all() : null,
                'points' => $a?->points !== null ? (float) $a->points : null, 'max_points' => (float) $f->points,
                'is_correct' => $a && $a->is_correct !== null ? (bool) $a->is_correct : null, 'feedback' => $a->feedback ?? null,
                'correct_display' => (float) $f->points > 0 && $f->correct ? $this->display($f, count((array) $f->correct) > 1 || $f->type === 'multi_choice' ? $f->correct : $f->correct[0], collect()) : null,
            ];
        }
        $events = DB::table('form_events')->where('response_id', $r->id)->orderBy('id')->limit(300)->get(['type', 'detail', 'counted', 'created_at']);
        $user = $r->user_id ? DB::table('users')->where('id', $r->user_id)->first(['id', 'full_name', 'email']) : null;
        return [
            'response' => $r->only(['id', 'uuid', 'status', 'email', 'phone', 'national_id', 'role', 'score', 'max_score', 'needs_manual', 'violations', 'edits_count', 'duration_seconds', 'ip']) + [
                'started_at' => $r->started_at?->toIso8601String(), 'submitted_at' => $r->submitted_at?->toIso8601String(),
                'user' => $user ? (array) $user : null, 'device' => UserAgentParser::parse($r->user_agent),
            ],
            'answers' => $items, 'events' => $events,
        ];
    }

    private function display($f, $val, $fileRows)
    {
        if ($val === null || $val === '' || $val === []) {
            return null;
        }
        $labels = collect((array) (($f->config['options'] ?? [])))->pluck('label', 'id')->all();
        $lab = fn ($x) => str_starts_with((string) $x, 'other:') ? mb_substr((string) $x, 6) : ($labels[$x] ?? (string) $x);
        return match ($f->type) {
            'single_choice', 'dropdown' => $lab($val),
            'multi_choice' => implode('، ', array_map($lab, (array) $val)),
            'true_false' => $val ? 'true' : 'false',
            'file' => implode('، ', array_map(fn ($id) => $fileRows->get($id)?->original_name ?? "#{$id}", (array) $val)),
            default => is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE),
        };
    }

    // ------------------------------------------------------------------ CSV

    public function exportCsv(Form $form, $handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
        $fields = $form->fields()->get()->filter(fn ($f) => !FormFieldTypes::isLayout($f->type))->values();
        $graded = (new FormGradingService())->isGraded($form, $form->fields()->get());
        $head = ['#', 'Status', 'Submitted at', 'Name', 'Email', 'Phone', 'Role', 'Duration (s)'];
        if ($graded) {
            array_push($head, 'Score', 'Max', 'Percent');
        }
        if ($form->isExam()) {
            $head[] = 'Violations';
        }
        foreach ($fields as $f) {
            $head[] = $f->label;
        }
        fputcsv($handle, array_map([$this, 'safe'], $head));

        DB::table('form_responses as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->where('r.form_id', $form->id)->whereIn('r.status', self::FINAL)
            ->orderBy('r.id')->select('r.*', 'u.full_name')->chunk(300, function ($rows) use ($handle, $fields, $form, $graded) {
                $ids = $rows->pluck('id')->all();
                $ans = DB::table('form_answers')->whereIn('response_id', $ids)->get()->groupBy('response_id');
                $fileRows = \App\Models\FormFile::whereIn('response_id', $ids)->get()->keyBy('id');
                foreach ($rows as $r) {
                    $by = ($ans->get($r->id) ?? collect())->keyBy('field_id');
                    $line = [$r->id, $r->status, $r->submitted_at, $r->full_name, $r->email, $r->phone, $r->role, $r->duration_seconds];
                    if ($graded) {
                        array_push($line, $r->score, $r->max_score, $r->max_score > 0 ? round($r->score / $r->max_score * 100, 1) : '');
                    }
                    if ($form->isExam()) {
                        $line[] = $r->violations;
                    }
                    foreach ($fields as $f) {
                        $a = $by->get($f->id);
                        $line[] = $a ? $this->display($f, json_decode($a->value, true), $fileRows) : '';
                    }
                    fputcsv($handle, array_map([$this, 'safe'], $line));
                }
            });
    }

    /** CSV-injection guard: cells starting with = + - @ are prefixed with a quote. */
    private function safe($v)
    {
        $v = (string) $v;
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
    }
}
