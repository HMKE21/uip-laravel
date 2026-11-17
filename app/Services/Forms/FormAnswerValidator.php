<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Support\DisposableEmail;
use App\Support\Forms\RespondentIdentity;
use App\Support\Forms\EgyptianNationalId;
use App\Support\Forms\EgyptianPhone;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Support\Collection;

/** Server-side validation + normalisation, question by question. Hidden (by logic) questions are ignored. */
class FormAnswerValidator
{
    /**
     * @param Collection $fields ordered FormField models
     * @param array<int|string,mixed> $answers field_id => raw value
     * @param array{lenient?:bool,locale?:string} $opts lenient = draft autosave (no required / no strict errors)
     * @return array{values:array<int,mixed>,errors:array<int,string>,hidden:array<int,bool>}
     */
    public function validate(Form $form, Collection $fields, array $answers, ?FormResponse $response, array $opts = []): array
    {
        $lenient = (bool) ($opts['lenient'] ?? false);
        $ar = ($opts['locale'] ?? 'ar') !== 'en';
        $values = [];
        $errors = [];
        $hidden = [];

        foreach ($fields as $f) {
            if (FormFieldTypes::isLayout($f->type)) {
                continue;
            }
            if (!$this->visible($f, $answers)) {
                $hidden[$f->id] = true;
                continue;
            }
            $raw = $answers[$f->id] ?? $answers[(string) $f->id] ?? null;
            if ($this->isEmpty($raw)) {
                if ($f->required && !$lenient) {
                    $errors[$f->id] = $ar ? 'هذا السؤال مطلوب.' : 'This question is required.';
                }
                continue;
            }
            try {
                $values[$f->id] = $this->normalizeValue($f, $raw, $form, $response, $ar);
            } catch (FormException $e) {
                if (!$lenient) {
                    $errors[$f->id] = $e->getMessage();
                }
            }
        }

        return ['values' => $values, 'errors' => $errors, 'hidden' => $hidden];
    }

    private function isEmpty($v): bool
    {
        return $v === null || $v === '' || $v === [] || (is_string($v) && trim($v) === '');
    }

    public function visible($field, array $answers): bool
    {
        $logic = $field->logic;
        if (!$logic || empty($logic['rules'])) {
            return true;
        }
        $results = [];
        foreach ($logic['rules'] as $r) {
            $v = $answers[$r['field']] ?? $answers[(string) $r['field']] ?? null;
            $target = $r['value'] ?? null;
            $results[] = match ($r['op']) {
                'eq' => is_array($v) ? in_array($target, $v) : (string) $v === (string) $target,
                'neq' => is_array($v) ? !in_array($target, $v) : (string) $v !== (string) $target,
                'contains' => is_array($v) ? in_array($target, $v) : ($v !== null && $target !== null && mb_stripos((string) $v, (string) $target) !== false),
                'gt' => is_numeric($v) && is_numeric($target) && (float) $v > (float) $target,
                'lt' => is_numeric($v) && is_numeric($target) && (float) $v < (float) $target,
                'filled' => !$this->isEmpty($v),
                'empty' => $this->isEmpty($v),
                default => true,
            };
        }
        return ($logic['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    private function fail(bool $ar, string $arMsg, string $enMsg): never
    {
        throw new FormException('invalid', $ar ? $arMsg : $enMsg, 422);
    }

    private function normalizeValue($f, $raw, Form $form, ?FormResponse $response, bool $ar)
    {
        $cfg = (array) $f->config;
        switch ($f->type) {
            case 'short_text':
            case 'long_text':
                $s = trim((string) (is_scalar($raw) ? $raw : ''));
                $max = (int) ($cfg['max_length'] ?? 0) ?: (int) config('forms.limits.max_text_answer');
                if (mb_strlen($s) > $max) {
                    $this->fail($ar, "الحد الأقصى {$max} حرف.", "Maximum {$max} characters.");
                }
                if (!empty($cfg['min_length']) && mb_strlen($s) < (int) $cfg['min_length']) {
                    $this->fail($ar, "الحد الأدنى {$cfg['min_length']} حرف.", "Minimum {$cfg['min_length']} characters.");
                }
                if ($f->type === 'short_text' && $s !== '') {
                    $rule = RespondentIdentity::textContentRule($cfg, (string) $f->label);
                    if ($rule === 'name') {
                        $minWords = max(1, (int) ($cfg['min_words'] ?? 1));
                        [$clean, $err] = RespondentIdentity::name($s, $minWords);
                        if ($err) {
                            $this->fail($ar, RespondentIdentity::message($err, true, $minWords), RespondentIdentity::message($err, false, $minWords));
                        }
                        return $clean;
                    }
                    if ($rule === 'letters' && preg_match('/[^\p{L}\p{M} ]/u', $s)) {
                        $this->fail($ar, 'حروف فقط (من غير أرقام أو رموز).', 'Letters only (no digits or symbols).');
                    }
                    if ($rule === 'digits' && preg_match('/\D/', $s)) {
                        $this->fail($ar, 'أرقام فقط.', 'Digits only.');
                    }
                }
                return $s;

            case 'email':
                return $this->email($cfg, (string) $raw, $ar);

            case 'phone':
                $p = EgyptianPhone::normalize((string) $raw);
                if (!$p) {
                    $this->fail($ar, 'أدخل رقم موبايل مصري صحيح (مثال: 01012345678).', 'Enter a valid Egyptian mobile number (e.g. 01012345678).');
                }
                $allowed = (array) ($cfg['allowed_operators'] ?? []);
                if ($allowed && !in_array(EgyptianPhone::operator($p), $allowed, true)) {
                    $this->fail($ar, 'شبكة هذا الرقم غير مسموح بها في هذا النموذج.', 'This mobile network is not accepted in this form.');
                }
                return $p;

            case 'national_id':
                $info = EgyptianNationalId::parse((string) $raw);
                if (!$info) {
                    $this->fail($ar, 'الرقم القومي غير صحيح (14 رقم).', 'Invalid national ID (14 digits).');
                }
                if (!empty($cfg['min_age']) && $info['age'] < (int) $cfg['min_age']) {
                    $this->fail($ar, "الحد الأدنى للسن {$cfg['min_age']} سنة.", "Minimum age is {$cfg['min_age']}.");
                }
                if (!empty($cfg['max_age']) && $info['age'] > (int) $cfg['max_age']) {
                    $this->fail($ar, "الحد الأقصى للسن {$cfg['max_age']} سنة.", "Maximum age is {$cfg['max_age']}.");
                }
                return EgyptianPhone::digits((string) $raw);

            case 'number':
                $s = str_replace(',', '.', trim((string) $raw));
                $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
                if (!is_numeric($s)) {
                    $this->fail($ar, 'أدخل رقمًا صحيحًا.', 'Enter a valid number.');
                }
                $n = $s + 0;
                if (!empty($cfg['integer_only']) && floor($n) != $n) {
                    $this->fail($ar, 'أدخل رقمًا صحيحًا بدون كسور.', 'Enter a whole number.');
                }
                if (isset($cfg['min']) && $n < $cfg['min']) {
                    $this->fail($ar, "الحد الأدنى {$cfg['min']}.", "Minimum is {$cfg['min']}.");
                }
                if (isset($cfg['max']) && $n > $cfg['max']) {
                    $this->fail($ar, "الحد الأقصى {$cfg['max']}.", "Maximum is {$cfg['max']}.");
                }
                return $n;

            case 'url':
                $u = trim((string) $raw);
                if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL) || mb_strlen($u) > 2000) {
                    $this->fail($ar, 'أدخل رابطًا صحيحًا يبدأ بـ http:// أو https://', 'Enter a valid link starting with http:// or https://');
                }
                return $u;

            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', trim((string) $raw));
                if (!$d || $d->format('Y-m-d') !== trim((string) $raw)) {
                    $this->fail($ar, 'تاريخ غير صحيح.', 'Invalid date.');
                }
                return $d->format('Y-m-d');

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) $raw))) {
                    $this->fail($ar, 'وقت غير صحيح.', 'Invalid time.');
                }
                return trim((string) $raw);

            case 'single_choice':
            case 'dropdown':
                return $this->choice($f, $cfg, $raw, false, $ar);

            case 'multi_choice':
                $arr = is_array($raw) ? $raw : [$raw];
                $vals = array_values(array_unique(array_map(fn ($x) => $this->choice($f, $cfg, $x, true, $ar), $arr)));
                if (!empty($cfg['min_select']) && count($vals) < (int) $cfg['min_select']) {
                    $this->fail($ar, "اختر {$cfg['min_select']} على الأقل.", "Select at least {$cfg['min_select']}.");
                }
                if (!empty($cfg['max_select']) && count($vals) > (int) $cfg['max_select']) {
                    $this->fail($ar, "اختر {$cfg['max_select']} كحد أقصى.", "Select at most {$cfg['max_select']}.");
                }
                return $vals;

            case 'true_false':
                $b = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    $this->fail($ar, 'اختر صح أو خطأ.', 'Choose true or false.');
                }
                return $b;

            case 'scale':
            case 'rating':
                $min = $f->type === 'scale' ? (int) ($cfg['min'] ?? 1) : 1;
                $max = (int) ($cfg['max'] ?? 5);
                if (!is_numeric($raw) || (int) $raw < $min || (int) $raw > $max) {
                    $this->fail($ar, "القيمة لازم تكون بين {$min} و {$max}.", "Value must be between {$min} and {$max}.");
                }
                return (int) $raw;

            case 'file':
                return $this->files($form, $f, $cfg, (array) $raw, $response, $ar);
        }
        return $raw;
    }

    private function email(array $cfg, string $raw, bool $ar): string
    {
        $e = mb_strtolower(trim($raw));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || mb_strlen($e) > 190) {
            $this->fail($ar, 'أدخل بريدًا إلكترونيًا صحيحًا.', 'Enter a valid email address.');
        }
        $domain = substr(strrchr($e, '@'), 1);
        $fmt = $cfg['format'] ?? 'any';
        if ($fmt === 'domains') {
            $doms = (array) ($cfg['domains'] ?? []);
            $ok = false;
            foreach ($doms as $d) {
                if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->fail($ar, 'المسموح فقط بالنطاقات: ' . implode(', ', $doms), 'Only these domains are accepted: ' . implode(', ', $doms));
            }
        } elseif ($fmt === 'regex' && !empty($cfg['regex'])) {
            if (!@preg_match('/' . str_replace('/', '\/', $cfg['regex']) . '/i', $e)) {
                $hint = $cfg['regex_hint'] ?? '';
                $this->fail($ar, $hint !== '' ? $hint : 'صيغة البريد غير مطابقة للمطلوب.', $hint !== '' ? $hint : 'Email does not match the required format.');
            }
        }
        // temporary / disposable addresses: blocked unless the form owner explicitly allows them
        if (empty($cfg['allow_disposable'])) {
            $msg = DisposableEmail::problem($e, $ar ? 'ar' : 'en', !array_key_exists('verify_dns', $cfg) || !empty($cfg['verify_dns']));
            if ($msg) {
                $this->fail($ar, $msg, $msg);
            }
        }
        return $e;
    }

    private function choice($f, array $cfg, $raw, bool $inMulti, bool $ar): string
    {
        $v = trim((string) (is_scalar($raw) ? $raw : ''));
        $ids = array_column((array) ($cfg['options'] ?? []), 'id');
        if (in_array($v, $ids, true)) {
            return $v;
        }
        if (!empty($cfg['allow_other']) && str_starts_with($v, 'other:') && mb_strlen($v) > 6 && mb_strlen($v) <= 306) {
            return $v;
        }
        $this->fail($ar, 'اختيار غير صالح.', 'Invalid choice.');
    }

    private function files(Form $form, $f, array $cfg, array $tokens, ?FormResponse $response, bool $ar): array
    {
        $tokens = array_values(array_unique(array_map('strval', $tokens)));
        $max = (int) ($cfg['max_files'] ?? 1);
        if (count($tokens) > $max) {
            $this->fail($ar, "الحد الأقصى {$max} ملف.", "Maximum {$max} files.");
        }
        $rows = FormFile::where('form_id', $form->id)->where('field_id', $f->id)->whereIn('upload_token', $tokens)->get();
        if ($rows->count() !== count($tokens)) {
            $this->fail($ar, 'ملف غير موجود أو انتهت صلاحية رفعه، ارفعه مرة تانية.', 'A file is missing or its upload expired; please upload it again.');
        }
        foreach ($rows as $r) {
            if ($r->response_id && (!$response || (int) $r->response_id !== (int) $response->id)) {
                $this->fail($ar, 'ملف مرتبط بإجابة أخرى.', 'File belongs to another response.');
            }
        }
        $order = array_flip($tokens);
        return $rows->sortBy(fn ($r) => $order[$r->upload_token])->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }
}
