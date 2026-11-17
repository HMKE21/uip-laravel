<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Create / edit forms and their questions, publish, link. */
class FormBuilderService
{
    public const RESERVED_SLUGS = ['new', 'admin', 'api', 'my', 'templates', 'public', 'f', 'forms', 'login', 'register'];

    public function create(int $userId, string $role, array $data): Form
    {
        if (Form::where('owner_user_id', $userId)->count() >= (int) config('forms.limits.max_forms_per_user', 500)) {
            throw new FormException('forms_limit', 'You reached the maximum number of forms.', 422);
        }

        return DB::transaction(function () use ($userId, $role, $data) {
            $mode = ($data['mode'] ?? 'form') === 'exam' ? 'exam' : 'form';
            $form = new Form();
            $form->uuid = (string) Str::uuid();
            $form->public_code = $this->newCode();
            $form->owner_user_id = $userId;
            $form->owner_role = $role;
            $form->mode = $mode;
            $form->status = 'draft';
            $this->fillMeta($form, $data, true);
            $form->settings = FormSettings::normalize((array) ($data['settings'] ?? []), $mode);
            $form->save();
            if (!empty($data['fields']) && is_array($data['fields'])) {
                $this->syncFields($form, $data['fields']);
            }
            return $form;
        });
    }

    public function createFromTemplate(int $userId, string $role, string $key, ?string $title = null): Form
    {
        $tpl = FormTemplates::all()[$key] ?? null;
        if (!$tpl) {
            throw new FormException('template_not_found', 'Template not found.', 404);
        }
        if ($title) {
            $tpl['title'] = $title;
        }
        return $this->create($userId, $role, $tpl);
    }

    public function update(Form $form, array $data): Form
    {
        return DB::transaction(function () use ($form, $data) {
            if (isset($data['mode']) && in_array($data['mode'], ['form', 'exam'], true) && $data['mode'] !== $form->mode) {
                $form->mode = $data['mode'];
            }
            $this->fillMeta($form, $data, false);
            if (isset($data['settings']) && is_array($data['settings'])) {
                $form->settings = FormSettings::normalize($data['settings'], $form->mode, (array) $form->settings);
            }
            $form->save();
            if (array_key_exists('fields', $data) && is_array($data['fields'])) {
                $this->syncFields($form, $data['fields']);
            }
            return $form->fresh();
        });
    }

    private function fillMeta(Form $form, array $d, bool $creating): void
    {
        if (isset($d['title']) || $creating) {
            $t = trim((string) ($d['title'] ?? ''));
            $form->title = $t !== '' ? mb_substr($t, 0, 200) : 'نموذج بدون عنوان';
        }
        if (array_key_exists('description', $d)) {
            $form->description = $d['description'] !== null ? mb_substr((string) $d['description'], 0, 5000) : null;
        }
        if (isset($d['icon']) && in_array($d['icon'], (array) config('forms.icons'), true)) {
            $form->icon = $d['icon'];
        }
        if (isset($d['color']) && in_array($d['color'], (array) config('forms.colors'), true)) {
            $form->color = $d['color'];
        }
        foreach (['opens_at', 'closes_at'] as $k) {
            if (array_key_exists($k, $d)) {
                $form->{$k} = $d[$k] ? date('Y-m-d H:i:s', strtotime((string) $d[$k])) : null;
            }
        }
        if (array_key_exists('slug', $d)) {
            $form->slug = $this->cleanSlug($d['slug'], $form->id);
        }
    }

    private function cleanSlug($slug, ?int $ignoreId): ?string
    {
        $s = Str::slug((string) $slug);
        if ($s === '') {
            return null;
        }
        $s = substr($s, 0, 60);
        if (in_array($s, self::RESERVED_SLUGS, true)) {
            throw new FormException('slug_reserved', 'This link name is reserved.', 422);
        }
        $q = Form::withTrashed()->where(fn ($w) => $w->where('slug', $s)->orWhere('public_code', $s));
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw new FormException('slug_taken', 'This link name is already taken.', 422);
        }
        return $s;
    }

    private function newCode(): string
    {
        do {
            $c = strtolower(Str::random(8));
        } while (Form::withTrashed()->where('public_code', $c)->exists());
        return $c;
    }

    /** Replace the form's questions with the given ordered list (ids kept when present). */
    public function syncFields(Form $form, array $fields): void
    {
        $max = (int) config('forms.limits.max_fields', 200);
        if (count($fields) > $max) {
            throw new FormException('too_many_fields', "A form can have at most {$max} questions.", 422);
        }
        $existing = FormField::where('form_id', $form->id)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($fields) as $i => $raw) {
            $clean = $this->sanitizeField((array) $raw);
            $clean['position'] = $i;
            $id = (int) ($raw['id'] ?? 0);
            if ($id && $existing->has($id)) {
                $existing[$id]->fill($clean)->save();
                $keep[] = $id;
            } else {
                $f = new FormField($clean);
                $f->form_id = $form->id;
                $f->save();
                $keep[] = (int) $f->id;
            }
        }
        $drop = $existing->keys()->diff($keep)->all();
        if ($drop) {
            DB::table('form_answers')->whereIn('field_id', $drop)->delete();
            DB::table('form_files')->whereIn('field_id', $drop)->get(['stored_path'])->each(fn ($r) => app(\App\Services\FileUploadService::class)->delete($r->stored_path));
            DB::table('form_files')->whereIn('field_id', $drop)->delete();
            FormField::whereIn('id', $drop)->delete();
        }
    }

    public function sanitizeField(array $in): array
    {
        $type = (string) ($in['type'] ?? 'short_text');
        if (!FormFieldTypes::valid($type)) {
            throw new FormException('bad_field_type', "Unknown question type: {$type}", 422);
        }
        $cfg = (array) ($in['config'] ?? []);
        $out = ['config' => []];

        if (FormFieldTypes::hasOptions($type)) {
            $opts = [];
            foreach (array_slice((array) ($cfg['options'] ?? []), 0, (int) config('forms.limits.max_options', 200)) as $o) {
                $label = is_array($o) ? (string) ($o['label'] ?? '') : (string) $o;
                $label = trim($label);
                if ($label === '') {
                    continue;
                }
                $id = is_array($o) && !empty($o['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $o['id']) : substr(md5($label . count($opts)), 0, 8);
                $opts[] = ['id' => $id ?: substr(md5($label), 0, 8), 'label' => mb_substr($label, 0, 300)];
            }
            $out['config']['options'] = $opts;
            if ($type === 'multi_choice') {
                $out['config']['min_select'] = isset($cfg['min_select']) ? max(0, (int) $cfg['min_select']) : null;
                $out['config']['max_select'] = isset($cfg['max_select']) ? max(0, (int) $cfg['max_select']) : null;
            }
            $out['config']['allow_other'] = !empty($cfg['allow_other']) && $type !== 'dropdown';
        }
        if ($type === 'email') {
            $out['config']['allow_disposable'] = !empty($cfg['allow_disposable']);
            // format: any | domains (only listed domains) | regex
            $fmt = in_array($cfg['format'] ?? 'any', ['any', 'domains', 'regex'], true) ? $cfg['format'] : 'any';
            $out['config']['format'] = $fmt;
            $out['config']['domains'] = array_values(array_filter(array_map(fn ($d) => ltrim(mb_strtolower(trim((string) $d)), '@'), (array) ($cfg['domains'] ?? []))));
            $rx = trim((string) ($cfg['regex'] ?? ''));
            if ($fmt === 'regex' && ($rx === '' || @preg_match('/' . str_replace('/', '\/', $rx) . '/i', '') === false)) {
                throw new FormException('bad_regex', 'The email pattern is not a valid regular expression.', 422);
            }
            $out['config']['regex'] = mb_substr($rx, 0, 300);
            $out['config']['regex_hint'] = mb_substr(trim((string) ($cfg['regex_hint'] ?? '')), 0, 200);
            $out['config']['verify_dns'] = !array_key_exists('verify_dns', $cfg) || !empty($cfg['verify_dns']);
        }
        if ($type === 'phone') {
            $out['config']['allowed_operators'] = array_values(array_intersect((array) ($cfg['allowed_operators'] ?? []), ['vodafone', 'etisalat', 'orange', 'we']));
        }
        if ($type === 'national_id') {
            $out['config']['min_age'] = isset($cfg['min_age']) ? max(0, (int) $cfg['min_age']) : null;
            $out['config']['max_age'] = isset($cfg['max_age']) ? max(0, (int) $cfg['max_age']) : null;
        }
        if ($type === 'number') {
            foreach (['min', 'max'] as $k) {
                $out['config'][$k] = isset($cfg[$k]) && is_numeric($cfg[$k]) ? (float) $cfg[$k] : null;
            }
            $out['config']['integer_only'] = !empty($cfg['integer_only']);
        }
        if (in_array($type, ['short_text', 'long_text'], true)) {
            $out['config']['min_length'] = isset($cfg['min_length']) ? max(0, (int) $cfg['min_length']) : null;
            $out['config']['max_length'] = isset($cfg['max_length']) ? min((int) config('forms.limits.max_text_answer'), max(1, (int) $cfg['max_length'])) : null;
            if ($type === 'short_text') {
                // allowed content: any | name | letters | digits (null = auto: a question titled like "Name" is letters-only)
                $out['config']['content'] = isset($cfg['content']) && in_array($cfg['content'], ['any', 'name', 'letters', 'digits'], true) ? $cfg['content'] : null;
                $out['config']['min_words'] = isset($cfg['min_words']) ? max(1, min(4, (int) $cfg['min_words'])) : null;
            }
        }
        if ($type === 'scale') {
            $min = (int) ($cfg['min'] ?? 1);
            $max = (int) ($cfg['max'] ?? 5);
            $min = max(0, min(1, $min));
            $max = max($min + 1, min(10, $max));
            $out['config'] += ['min' => $min, 'max' => $max, 'min_label' => mb_substr((string) ($cfg['min_label'] ?? ''), 0, 60), 'max_label' => mb_substr((string) ($cfg['max_label'] ?? ''), 0, 60)];
        }
        if ($type === 'rating') {
            $out['config']['max'] = max(3, min(10, (int) ($cfg['max'] ?? 5)));
        }
        if ($type === 'file') {
            $out['config']['max_files'] = max(1, min((int) config('forms.limits.max_files_per_field'), (int) ($cfg['max_files'] ?? 1)));
            $out['config']['max_kb'] = max(1, min((int) config('forms.limits.max_file_kb'), (int) ($cfg['max_kb'] ?? config('forms.limits.max_file_kb'))));
            $out['config']['allowed_extensions'] = array_values(array_filter(array_map(fn ($e) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $e)), (array) ($cfg['allowed_extensions'] ?? []))));
        }
        if (FormFieldTypes::isLayout($type)) {
            $out['config'] = [];
        }

        $points = FormFieldTypes::isLayout($type) ? 0 : max(0, min(1000, (float) ($in['points'] ?? 0)));
        $correct = isset($in['correct']) && is_array($in['correct']) ? array_values($in['correct']) : null;
        if (FormFieldTypes::hasOptions($type) && $correct) {
            $ids = array_column($out['config']['options'], 'id');
            $correct = array_values(array_intersect($correct, $ids));
        }

        return $out + [
            'type' => $type,
            'label' => mb_substr(trim((string) ($in['label'] ?? '')) ?: 'سؤال بدون عنوان', 0, 500),
            'help' => isset($in['help']) ? mb_substr((string) $in['help'], 0, 2000) : null,
            'required' => !FormFieldTypes::isLayout($type) && !empty($in['required']),
            'logic' => $this->sanitizeLogic($in['logic'] ?? null),
            'points' => $points,
            'correct' => $points > 0 || $correct ? $correct : null,
            'explanation' => isset($in['explanation']) ? mb_substr((string) $in['explanation'], 0, 3000) : null,
        ];
    }

    /** logic = {action: show, match: all|any, rules: [{field: <position or id>, op: eq|neq|contains|gt|lt|filled, value}]} */
    private function sanitizeLogic($logic): ?array
    {
        if (!is_array($logic) || empty($logic['rules'])) {
            return null;
        }
        $rules = [];
        foreach (array_slice((array) $logic['rules'], 0, 10) as $r) {
            $op = $r['op'] ?? 'eq';
            if (!isset($r['field']) || !in_array($op, ['eq', 'neq', 'contains', 'gt', 'lt', 'filled', 'empty'], true)) {
                continue;
            }
            $rules[] = ['field' => (int) $r['field'], 'op' => $op, 'value' => $r['value'] ?? null];
        }
        return $rules ? ['match' => ($logic['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules] : null;
    }

    public function publish(Form $form): Form
    {
        $n = FormField::where('form_id', $form->id)->whereNotIn('type', ['section', 'description'])->count();
        if ($n < 1) {
            throw new FormException('no_questions', 'Add at least one question before publishing.', 422);
        }
        if (($form->setting('access') === 'invited') && !FormField::where('form_id', $form->id)->where('type', 'email')->exists()) {
            throw new FormException('invite_needs_email', 'An invite-only form needs an email question.', 422);
        }
        $form->status = 'published';
        $form->published_at = $form->published_at ?: now();
        $form->save();
        return $form;
    }

    public function close(Form $form): Form
    {
        $form->status = 'closed';
        $form->save();
        return $form;
    }

    public function duplicate(Form $form, int $userId, string $role): Form
    {
        $fields = $form->fields()->get()->map(fn ($f) => $f->only(['type', 'label', 'help', 'required', 'config', 'logic', 'points', 'correct', 'explanation']))->all();
        return $this->create($userId, $role, [
            'title' => $form->title . ' (نسخة)', 'description' => $form->description, 'icon' => $form->icon, 'color' => $form->color,
            'mode' => $form->mode, 'settings' => $form->settings, 'fields' => $fields,
        ]);
    }

    public function publicUrl(Form $form): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        return $base . str_replace('{code}', $form->slug ?: $form->public_code, (string) config('forms.public_path', '/f/{code}'));
    }

    public function isOpen(Form $form): array
    {
        if ($form->status === 'draft') {
            return [false, 'not_published'];
        }
        if ($form->status === 'closed') {
            return [false, 'closed'];
        }
        if ($form->opens_at && now()->lt($form->opens_at)) {
            return [false, 'not_open_yet'];
        }
        if ($form->closes_at && now()->gt($form->closes_at)) {
            return [false, 'closed'];
        }
        $max = $form->setting('max_responses');
        if ($max && (int) $form->responses_count >= (int) $max) {
            return [false, 'full'];
        }
        return [true, null];
    }

    public function canManage(Form $form, ?int $userId, ?string $role, bool $needEdit = true): bool
    {
        if (!$userId) {
            return false;
        }
        if ((int) $form->owner_user_id === $userId || in_array($role, ['admin'], true)) {
            return true;
        }
        $perm = \App\Models\FormCollaborator::where('form_id', $form->id)->where('user_id', $userId)->value('permission');
        return $perm !== null && (!$needEdit || $perm === 'edit');
    }

    public function findByCode(string $code): ?Form
    {
        return Form::where('public_code', $code)->orWhere('slug', $code)->first();
    }
}
