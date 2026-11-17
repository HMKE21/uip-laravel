<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Response life-cycle: access -> start -> autosave -> submit -> (edit) + exam mode + duplicate prevention.
 *
 * actor = ['user_id'=>?int,'role'=>?string,'email'=>?string]
 * ctx   = ['ip'=>?string,'ua'=>?string,'device_id'=>?string,'locale'=>string]
 */
class FormSubmissionService
{
    private const VIOLATION_MAP = [
        'block_tab_switch' => ['tab_switch', 'blur'],
        'fullscreen_required' => ['fullscreen_exit'],
        'block_copy_paste' => ['copy', 'paste', 'cut'],
    ];

    public function __construct(
        private FormBuilderService $builder,
        private FormAnswerValidator $validator,
        private FormGradingService $grading,
        private FormFileService $files
    ) {
    }

    // ------------------------------------------------------------------ access

    public function actor(?int $userId, ?string $role): array
    {
        $email = $userId ? DB::table('users')->where('id', $userId)->value('email') : null;
        return ['user_id' => $userId, 'role' => $role, 'email' => $email ? mb_strtolower($email) : null];
    }

    public function deviceHash(array $ctx): ?string
    {
        $id = trim((string) ($ctx['device_id'] ?? ''));
        return $id === '' ? null : hash('sha256', 'form-device|' . $id . '|' . config('app.key'));
    }

    /** @throws FormException */
    public function assertAccess(Form $form, array $actor): void
    {
        [$open, $why] = $this->builder->isOpen($form);
        if (!$open) {
            throw new FormException($why, match ($why) {
                'not_published' => 'This form is not published.',
                'not_open_yet' => 'This form is not open yet.',
                'full' => 'This form reached its maximum number of responses.',
                default => 'This form is closed.',
            }, 403, ['opens_at' => $form->opens_at?->toIso8601String()]);
        }
        $access = $form->setting('access', 'public');
        if ($access !== 'public' && !$actor['user_id']) {
            throw new FormException('login_required', 'Please log in to fill this form.', 401);
        }
        if ($access === 'roles' && !in_array($actor['role'], (array) $form->setting('allowed_roles', []), true)) {
            throw new FormException('role_not_allowed', 'This form is not available for your account type.', 403);
        }
        if ($access === 'invited' && $actor['user_id']) {
            $inv = (array) $form->setting('invited_emails', []);
            // email field may still prove the invite at submit time; a logged-in account with a different email is checked then
            if ($actor['email'] && !in_array($actor['email'], $inv, true) && !$this->hasEmailField($form)) {
                throw new FormException('not_invited', 'You are not on the invite list.', 403);
            }
        }
    }

    private function hasEmailField(Form $form): bool
    {
        return $form->fields()->where('type', 'email')->exists();
    }

    // ------------------------------------------------------------------ start / resume

    /** @return array{response:FormResponse,resumed:bool,mode:string} mode: fill | edit */
    public function start(Form $form, array $actor, array $ctx): array
    {
        $this->assertAccess($form, $actor);
        $device = $this->deviceHash($ctx);
        if ($form->setting('one_per_device') && !$device) {
            throw new FormException('device_required', 'Device identification is required for this form.', 400);
        }

        // resume own unfinished / existing response
        $existing = $this->findExisting($form, $actor, $device);
        if ($existing) {
            if ($existing->isFinal()) {
                if ($this->canEdit($form, $existing)) {
                    return ['response' => $existing, 'resumed' => true, 'mode' => 'edit'];
                }
                throw new FormException('already_submitted', 'You have already submitted this form.', 409, ['response_uuid' => $existing->uuid]);
            }
            return ['response' => $this->checkExamDeadline($form, $existing), 'resumed' => true, 'mode' => 'fill'];
        }

        $r = new FormResponse();
        $r->form_id = $form->id;
        $r->uuid = (string) Str::uuid();
        $r->edit_token = Str::random(48);
        $r->user_id = $actor['user_id'];
        $r->role = $actor['role'];
        $r->device_hash = $device;
        $r->ip = $form->setting('collect_ip', true) ? ($ctx['ip'] ?? null) : null;
        $r->user_agent = mb_substr((string) ($ctx['ua'] ?? ''), 0, 300);
        $r->status = 'in_progress';
        $r->started_at = now();
        if ($form->isExam() && (int) $form->setting('exam.duration_minutes', 0) > 0) {
            $deadline = now()->addMinutes((int) $form->setting('exam.duration_minutes'));
            if ($form->closes_at && $form->closes_at->lt($deadline)) {
                $deadline = $form->closes_at;
            }
            $r->deadline_at = $deadline;
        }
        $r->save();
        return ['response' => $r, 'resumed' => false, 'mode' => 'fill'];
    }

    private function findExisting(Form $form, array $actor, ?string $device): ?FormResponse
    {
        $q = FormResponse::where('form_id', $form->id);
        $q->where(function ($w) use ($form, $actor, $device) {
            $any = false;
            if ($actor['user_id']) {
                $w->orWhere('user_id', $actor['user_id']);
                $any = true;
            }
            if ($device && ($form->setting('one_per_device') || !$actor['user_id'])) {
                $w->orWhere('device_hash', $device);
                $any = true;
            }
            if (!$any) {
                $w->whereRaw('1 = 0');
            }
        });
        $mine = $q->orderByDesc('id')->get();
        if ($mine->isEmpty()) {
            return null;
        }
        // a final response only blocks when the form limits repeats; otherwise an unfinished draft is resumed and new ones may start
        $unfinished = $mine->first(fn ($r) => !$r->isFinal());
        $limits = $form->isExam() || $form->setting('one_per_device') || in_array('user', (array) $form->setting('unique_by', []), true);
        if ($limits) {
            return $mine->first(fn ($r) => $r->isFinal()) ?: $unfinished;
        }
        return $unfinished;
    }

    public function findOwned(Form $form, string $uuid, ?string $token, array $actor): ?FormResponse
    {
        $r = FormResponse::where('form_id', $form->id)->where('uuid', $uuid)->first();
        if (!$r) {
            return null;
        }
        $byToken = $token && $r->edit_token && hash_equals($r->edit_token, $token);
        $byUser = $actor['user_id'] && (int) $r->user_id === (int) $actor['user_id'];
        return ($byToken || $byUser) ? $r : null;
    }

    // ------------------------------------------------------------------ exam helpers

    /** If the exam time is over, finalise from the autosaved draft. */
    private function checkExamDeadline(Form $form, FormResponse $r): FormResponse
    {
        if (!$r->isFinal() && $r->deadline_at && now()->gt($r->deadline_at->copy()->addSeconds((int) config('forms.exam.grace_seconds', 20)))) {
            return $this->finalize($form, $r, 'auto_submitted');
        }
        return $r;
    }

    public function secondsLeft(FormResponse $r): ?int
    {
        return $r->deadline_at ? max(0, (int) now()->diffInSeconds($r->deadline_at, false)) : null;
    }

    public function logEvent(Form $form, FormResponse $r, string $type, ?string $detail = null): array
    {
        $type = mb_substr(preg_replace('/[^a-z_]/', '', strtolower($type)), 0, 30);
        if ($type === '' || $r->isFinal()) {
            return ['violations' => (int) $r->violations, 'auto_submitted' => $r->status === 'auto_submitted'];
        }
        $counted = false;
        foreach (self::VIOLATION_MAP as $setting => $types) {
            if ($form->setting("exam.{$setting}") && in_array($type, $types, true) && $form->isExam()) {
                $counted = true;
            }
        }
        DB::table('form_events')->insert([
            'response_id' => $r->id, 'type' => $type, 'detail' => $detail ? mb_substr($detail, 0, 190) : null,
            'counted' => $counted, 'created_at' => now(),
        ]);
        if ($counted) {
            $r->increment('violations');
            $r->refresh();
            $max = (int) $form->setting('exam.max_violations', 0);
            if ($max > 0 && $r->violations >= $max && $form->setting('exam.auto_submit_on_max_violations', true)) {
                $this->finalize($form, $r, 'auto_submitted');
                return ['violations' => (int) $r->violations, 'auto_submitted' => true];
            }
        }
        return ['violations' => (int) $r->violations, 'auto_submitted' => false];
    }

    // ------------------------------------------------------------------ answers

    private function fieldsFor(Form $form): Collection
    {
        return $form->fields()->get();
    }

    public function rawAnswers(FormResponse $r): array
    {
        return DB::table('form_answers')->where('response_id', $r->id)->get()
            ->mapWithKeys(fn ($a) => [(int) $a->field_id => json_decode($a->value, true)])->all();
    }

    public function saveDraft(Form $form, FormResponse $r, array $answers, array $ctx): FormResponse
    {
        if ($r->isFinal()) {
            throw new FormException('already_submitted', 'This response is already submitted.', 409);
        }
        $r = $this->checkExamDeadline($form, $r);
        if ($r->isFinal()) {
            throw new FormException('time_over', 'Time is over; your saved answers were submitted.', 409);
        }
        $fields = $this->fieldsFor($form);
        $res = $this->validator->validate($form, $fields, $answers, $r, ['lenient' => true, 'locale' => $ctx['locale'] ?? 'ar']);
        $this->storeAnswers($r, $res['values'], $fields, true);
        if (($name = $this->cleanName($ctx['respondent_name'] ?? null)) !== null && !$r->user_id) {
            $r->respondent_name = $name;
            $r->save();
        }
        return $r;
    }

    private function storeAnswers(FormResponse $r, array $values, Collection $fields, bool $replaceAll): void
    {
        $now = now();
        $keep = [];
        foreach ($values as $fid => $val) {
            DB::table('form_answers')->updateOrInsert(
                ['response_id' => $r->id, 'field_id' => $fid],
                ['value' => json_encode($val, JSON_UNESCAPED_UNICODE), 'updated_at' => $now, 'created_at' => $now]
            );
            $keep[] = $fid;
        }
        if ($replaceAll) {
            $del = DB::table('form_answers')->where('response_id', $r->id);
            if ($keep) {
                $del->whereNotIn('field_id', $keep);
            }
            $del->delete();
        }
        // attach uploaded files and drop those no longer referenced
        $fileIds = [];
        foreach ($fields as $f) {
            if ($f->type === 'file' && isset($values[$f->id])) {
                $fileIds = array_merge($fileIds, (array) $values[$f->id]);
            }
        }
        $this->files->attachToResponse((int) $r->id, $fileIds);
        $this->files->detachUnused((int) $r->id, $fileIds);
    }

    /** Submit (creating the response when the form is a plain one-shot form). */
    public function submit(Form $form, ?FormResponse $r, array $actor, array $ctx, array $answers, array $meta = []): array
    {
        if (!empty($meta['honeypot'])) {
            throw new FormException('spam', 'Submission rejected.', 422);
        }
        $this->assertAccess($form, $actor);
        if (!$r) {
            $r = $this->start($form, $actor, $ctx)['response'];
            if ($r->isFinal()) {
                throw new FormException('already_submitted', 'You have already submitted this form.', 409);
            }
        }
        if ($r->isFinal()) {
            throw new FormException('already_submitted', 'This response is already submitted.', 409);
        }

        // exam: too late => finalize from the last autosave, ignoring what was posted now
        if ($r->deadline_at && now()->gt($r->deadline_at->copy()->addSeconds((int) config('forms.exam.grace_seconds', 20)))) {
            $r = $this->finalize($form, $r, 'auto_submitted');
            return ['response' => $r, 'late' => true];
        }
        if (!$form->isExam() && !empty($meta['opened_at_ms']) && ((int) (microtime(true) * 1000) - (int) $meta['opened_at_ms']) / 1000 < (int) config('forms.min_fill_seconds', 2)) {
            throw new FormException('too_fast', 'Submitted too fast; please try again.', 429);
        }

        $fields = $this->fieldsFor($form);
        $res = $this->validator->validate($form, $fields, $answers, $r, ['locale' => $ctx['locale'] ?? 'ar']);
        if ($res['errors']) {
            throw new FormException('validation_failed', 'Some answers need your attention.', 422, ['errors' => $res['errors']]);
        }
        $this->assertIdentity($form, $r, $fields, $res['values'], $actor, $ctx);
        $name = $this->cleanName($meta['respondent_name'] ?? null);
        if (!$actor['user_id'] && $form->setting('ask_name', true) && $name === null && !$r->respondent_name) {
            throw new FormException('name_required', 'Please enter your name.', 422);
        }
        if ($name !== null && !$actor['user_id']) {
            $r->respondent_name = $name;
        }

        DB::transaction(function () use ($form, $r, $res, $fields) {
            $this->storeAnswers($r, $res['values'], $fields, true);
            $this->fillIdentity($r, $fields, $res['values']);
            $r->save();
            $this->finalize($form, $r, 'submitted', $fields);
        });

        return ['response' => $r->fresh(), 'late' => false];
    }

    private function cleanName($v): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        return $v === '' ? null : mb_substr($v, 0, 190);
    }

    /** Duplicate rules: unique_by email/phone/national_id/user + invite list. */
    private function assertIdentity(Form $form, FormResponse $r, Collection $fields, array $values, array $actor, array $ctx): void
    {
        $ident = $this->identityValues($fields, $values);
        if ($form->setting('access') === 'invited') {
            $inv = (array) $form->setting('invited_emails', []);
            $mail = $ident['email'] ?? $actor['email'];
            if (!$mail || !in_array(mb_strtolower($mail), $inv, true)) {
                throw new FormException('not_invited', 'This email is not on the invite list.', 403);
            }
        }
        foreach ((array) $form->setting('unique_by', []) as $key) {
            $col = ['email' => 'email', 'phone' => 'phone', 'national_id' => 'national_id', 'user' => 'user_id'][$key] ?? null;
            $val = $key === 'user' ? $actor['user_id'] : ($ident[$key] ?? null);
            if (!$col || $val === null) {
                continue;
            }
            $dup = FormResponse::where('form_id', $form->id)->where('id', '!=', $r->id)->where($col, $val)
                ->whereIn('status', ['submitted', 'graded', 'auto_submitted'])->exists();
            if ($dup) {
                $msg = ['email' => 'This email already submitted this form.', 'phone' => 'This phone number already submitted this form.',
                    'national_id' => 'This national ID already submitted this form.', 'user' => 'You have already submitted this form.'][$key];
                throw new FormException('duplicate_' . $key, $msg, 409);
            }
        }
        $device = $this->deviceHash($ctx);
        if ($form->setting('one_per_device') && $device) {
            $dup = FormResponse::where('form_id', $form->id)->where('id', '!=', $r->id)->where('device_hash', $device)
                ->whereIn('status', ['submitted', 'graded', 'auto_submitted'])->exists();
            if ($dup) {
                throw new FormException('duplicate_device', 'This device has already submitted this form.', 409);
            }
        }
    }

    private function identityValues(Collection $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (in_array($f->type, ['email', 'phone', 'national_id'], true) && isset($values[$f->id]) && !isset($out[$f->type])) {
                $out[$f->type] = (string) $values[$f->id];
            }
        }
        return $out;
    }

    private function fillIdentity(FormResponse $r, Collection $fields, array $values): void
    {
        $id = $this->identityValues($fields, $values);
        $r->email = $id['email'] ?? $r->email;
        $r->phone = $id['phone'] ?? $r->phone;
        $r->national_id = $id['national_id'] ?? $r->national_id;
    }

    /** Mark final, grade, bump counters, notify owner. */
    public function finalize(Form $form, FormResponse $r, string $status, ?Collection $fields = null): FormResponse
    {
        $fields ??= $this->fieldsFor($form);
        $r->status = $status;
        $r->submitted_at = now();
        $r->duration_seconds = $r->started_at ? max(0, (int) $r->started_at->diffInSeconds(now())) : null;
        $r->edit_token = $r->edit_token ?: Str::random(48);
        $r->save();
        $this->grading->gradeResponse($form, $r, $fields);
        $r->refresh();
        if ($r->status === 'in_progress') { // grading never leaves an in_progress status behind a final submit
            $r->status = $status;
            $r->save();
        }

        Form::where('id', $form->id)->update(['responses_count' => DB::raw('responses_count + 1'), 'last_response_at' => now()]);
        $this->notifyOwner($form, $r);
        return $r;
    }

    private function notifyOwner(Form $form, FormResponse $r): void
    {
        if (!$form->setting('notify_owner', true)) {
            return;
        }
        $n = (int) $form->responses_count + 1;
        if ($n > 20 && $n % 10 !== 0) { // avoid flooding on popular forms
            return;
        }
        try {
            app(\App\Services\NotificationService::class)->notify(
                (int) $form->owner_user_id, 'form_response', 'رد جديد على: ' . $form->title,
                $n > 20 ? "وصل عدد الردود إلى {$n}." : 'وصل رد جديد على النموذج.',
                '/forms/' . $form->id . '/responses'
            );
        } catch (\Throwable $e) {
            // notifications must never break a submission
        }
    }

    // ------------------------------------------------------------------ edit after submit

    public function canEdit(Form $form, FormResponse $r): bool
    {
        $e = (array) $form->setting('edit_after_submit', []);
        if (empty($e['allowed']) || !$r->isFinal() || $form->isExam()) {
            return false;
        }
        [$open] = $this->builder->isOpen($form);
        $closedForEdit = $form->status === 'closed' || ($form->closes_at && now()->gt($form->closes_at));
        if ($closedForEdit) {
            return false;
        }
        if (($e['window'] ?? 'until_close') === 'minutes') {
            return $r->submitted_at && (int) $r->submitted_at->diffInMinutes(now()) < (int) ($e['minutes'] ?? 60);
        }
        return true;
    }

    public function edit(Form $form, FormResponse $r, array $actor, array $ctx, array $answers): FormResponse
    {
        if (!$this->canEdit($form, $r)) {
            throw new FormException('edit_not_allowed', 'Editing this response is not allowed.', 403);
        }
        $fields = $this->fieldsFor($form);
        $res = $this->validator->validate($form, $fields, $answers, $r, ['locale' => $ctx['locale'] ?? 'ar']);
        if ($res['errors']) {
            throw new FormException('validation_failed', 'Some answers need your attention.', 422, ['errors' => $res['errors']]);
        }
        $this->assertIdentity($form, $r, $fields, $res['values'], $actor, $ctx);
        DB::transaction(function () use ($form, $r, $res, $fields) {
            // keep an audit trail of what the respondent had before
            DB::table('form_events')->insert([
                'response_id' => $r->id, 'type' => 'edited', 'detail' => mb_substr(json_encode($this->rawAnswers($r), JSON_UNESCAPED_UNICODE), 0, 190),
                'counted' => false, 'created_at' => now(),
            ]);
            $this->storeAnswers($r, $res['values'], $fields, true);
            $this->fillIdentity($r, $fields, $res['values']);
            $r->edits_count = (int) $r->edits_count + 1;
            $r->save();
            $this->grading->gradeResponse($form, $r, $fields);
        });
        return $r->fresh();
    }

    // ------------------------------------------------------------------ what the respondent may see afterwards

    public function resultFor(Form $form, FormResponse $r): array
    {
        $out = ['status' => $r->status, 'submitted_at' => $r->submitted_at?->toIso8601String(), 'can_edit' => $this->canEdit($form, $r),
            'confirmation_message' => (string) $form->setting('confirmation_message', ''), 'redirect_url' => (string) $form->setting('redirect_url', '')];
        if ($form->isExam() || $r->max_score) {
            $mode = $form->isExam() ? $form->setting('exam.show_score', 'immediately') : 'immediately';
            $closed = $form->status === 'closed' || ($form->closes_at && now()->gt($form->closes_at));
            $show = $mode === 'immediately' || ($mode === 'after_close' && $closed);
            if ($show && !$r->needs_manual) {
                $pct = $r->max_score > 0 ? round($r->score / $r->max_score * 100, 1) : null;
                $out['score'] = ['score' => $r->score, 'max' => $r->max_score, 'percent' => $pct,
                    'passed' => $pct !== null ? $pct >= (int) $form->setting('exam.passing_percent', 50) : null];
            } else {
                $out['score'] = null;
                $out['score_pending'] = $r->needs_manual ? 'manual_grading' : ($mode === 'never' ? 'hidden' : 'after_close');
            }
        }
        return $out;
    }

    /** Cron backstop: submit exams whose time ran out and nobody came back. */
    public function autoSubmitExpired(): int
    {
        $n = 0;
        $rows = FormResponse::where('status', 'in_progress')->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now()->subSeconds((int) config('forms.exam.grace_seconds', 20)))->limit(500)->get();
        foreach ($rows as $r) {
            $form = Form::find($r->form_id);
            if ($form) {
                $this->finalize($form, $r, 'auto_submitted');
                $n++;
            }
        }
        return $n;
    }

    public function deleteResponse(Form $form, FormResponse $r): void
    {
        DB::transaction(function () use ($form, $r) {
            $this->files->deleteForResponse((int) $r->id);
            DB::table('form_answers')->where('response_id', $r->id)->delete();
            DB::table('form_events')->where('response_id', $r->id)->delete();
            $wasFinal = $r->isFinal();
            $r->delete();
            if ($wasFinal) {
                Form::where('id', $form->id)->where('responses_count', '>', 0)->update(['responses_count' => DB::raw('responses_count - 1')]);
            }
        });
    }
}
