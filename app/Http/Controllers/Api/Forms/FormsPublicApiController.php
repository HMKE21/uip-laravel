<?php

namespace App\Http\Controllers\Api\Forms;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Services\Forms\FormAnalyticsService;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormException;
use App\Services\Forms\FormFileService;
use App\Services\Forms\FormSubmissionService;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/f/{code}/* — what a respondent (guest or logged-in, inside or outside the platform) uses.
 * Routes use uip.auth.optional: a valid Bearer token identifies the user, otherwise the request is a guest.
 * A guest owns a response through its secret edit_token (header X-Form-Token or body edit_token).
 */
class FormsPublicApiController extends Controller
{
    public function __construct(
        private FormBuilderService $builder,
        private FormSubmissionService $subs,
        private FormFileService $files,
        private FormAnalyticsService $analytics
    ) {
    }

    private function actor(Request $r): array
    {
        return $this->subs->actor($r->attributes->get('uip_user_id') ? (int) $r->attributes->get('uip_user_id') : null, $r->attributes->get('uip_role'));
    }

    private function ctx(Request $r): array
    {
        return [
            'ip' => $r->ip(), 'ua' => $r->userAgent(), 'device_id' => $r->header('X-Device-Id'),
            'locale' => $r->header('X-Locale', 'ar') === 'en' ? 'en' : 'ar',
        ];
    }

    private function token(Request $r): ?string
    {
        return $r->header('X-Form-Token') ?: ($r->input('edit_token') ?: null);
    }

    private function fail(FormException $e): JsonResponse
    {
        return $this->apiError($e->getMessage(), ['code' => $e->errorCode] + $e->extra, $e->httpStatus);
    }

    private function form(string $code): ?Form
    {
        return $this->builder->findByCode($code);
    }

    private function basics(Form $f): array
    {
        return [
            'title' => $f->title, 'description' => $f->description, 'icon' => $f->icon, 'color' => $f->color, 'mode' => $f->mode,
            'code' => $f->slug ?: $f->public_code, 'closes_at' => $f->closes_at?->toIso8601String(), 'opens_at' => $f->opens_at?->toIso8601String(),
        ];
    }

    /** Questions as the respondent sees them: no answer key, optional stable shuffle for exams. */
    private function publicFields(Form $form, ?FormResponse $resp)
    {
        $fields = $form->fields()->get();
        if ($form->isExam() && $resp) {
            $seed = crc32($resp->uuid);
            if ($form->setting('exam.shuffle_questions')) {
                // keep sections/descriptions in place relative to the first question: shuffle only gradable questions
                $q = $fields->filter(fn ($f) => !FormFieldTypes::isLayout($f->type))->values()->all();
                mt_srand($seed);
                shuffle($q);
                mt_srand();
                $fields = collect($q);
            }
        }
        return $fields->map(function (FormField $f) use ($form, $resp) {
            $cfg = (array) $f->config;
            if ($form->isExam() && $resp && $form->setting('exam.shuffle_options') && !empty($cfg['options'])) {
                $o = $cfg['options'];
                mt_srand(crc32($resp->uuid . '|' . $f->id));
                shuffle($o);
                mt_srand();
                $cfg['options'] = $o;
            }
            return [
                'id' => (int) $f->id, 'type' => $f->type, 'label' => $f->label, 'help' => $f->help, 'required' => (bool) $f->required,
                'config' => $cfg ?: (object) [], 'logic' => $f->logic, 'points' => (float) $f->points,
            ];
        })->values()->all();
    }

    private function examSettings(Form $form): ?array
    {
        if (!$form->isExam()) {
            return null;
        }
        $e = (array) $form->setting('exam', []);
        return array_intersect_key($e, array_flip(['duration_minutes', 'block_tab_switch', 'fullscreen_required', 'block_copy_paste', 'max_violations', 'one_question_at_a_time']));
    }

    // ---------------------------------------------------------------- GET /f/{code}

    public function show(Request $r, string $code): JsonResponse
    {
        $form = $this->form($code);
        if (!$form || $form->status === 'draft' && (int) $form->owner_user_id !== (int) $r->attributes->get('uip_user_id')) {
            return $this->apiError('Form not found.', ['code' => 'not_found'], 404);
        }
        $actor = $this->actor($r);
        $data = ['form' => $this->basics($form), 'access' => $form->setting('access', 'public'), 'state' => 'open', 'fields' => null, 'exam' => $this->examSettings($form),
            'edit_after_submit' => (bool) $form->setting('edit_after_submit.allowed', false) && !$form->isExam(), 'one_per_device' => (bool) $form->setting('one_per_device', false),
            'ask_name' => \App\Support\Forms\RespondentIdentity::resolve((array) ($form->settings ?? []))['name'] !== 'off',
            'identity' => \App\Support\Forms\RespondentIdentity::resolve((array) ($form->settings ?? []))];
        $isOwnerPreview = $form->status === 'draft';
        try {
            if (!$isOwnerPreview) {
                $this->subs->assertAccess($form, $actor);
            }
        } catch (FormException $e) {
            $data['state'] = $e->errorCode; // not_open_yet | closed | full | login_required | role_not_allowed | not_invited
            $data['message'] = $e->getMessage();
            return $this->apiSuccess($data);
        }
        $data['fields'] = $this->publicFields($form, null);
        return $this->apiSuccess($data);
    }

    // ---------------------------------------------------------------- start / resume

    public function start(Request $r, string $code): JsonResponse
    {
        $form = $this->form($code);
        if (!$form) {
            return $this->apiError('Form not found.', ['code' => 'not_found'], 404);
        }
        try {
            $res = $this->subs->start($form, $this->actor($r), $this->ctx($r));
        } catch (FormException $e) {
            return $this->fail($e);
        }
        /** @var FormResponse $resp */
        $resp = $res['response'];
        return $this->apiSuccess([
            'response_uuid' => $resp->uuid, 'edit_token' => $resp->edit_token, 'resumed' => $res['resumed'], 'mode' => $res['mode'],
            'status' => $resp->status, 'seconds_left' => $this->subs->secondsLeft($resp), 'violations' => (int) $resp->violations,
            'fields' => $this->publicFields($form, $resp), 'answers' => $this->myAnswers($resp), 'exam' => $this->examSettings($form),
        ], $res['resumed'] ? 'Resumed.' : 'Started.', $res['resumed'] ? 200 : 201);
    }

    private function myAnswers(FormResponse $resp): array
    {
        $ans = $this->subs->rawAnswers($resp);
        $fileIds = [];
        foreach ($ans as $v) {
            if (is_array($v) && $v && array_is_list($v) && is_int($v[0] ?? null)) {
                $fileIds = array_merge($fileIds, $v);
            }
        }
        // only file fields hold integer lists
        $fileFieldIds = FormField::where('form_id', $resp->form_id)->where('type', 'file')->pluck('id')->all();
        $out = [];
        foreach ($ans as $fid => $v) {
            if (in_array($fid, $fileFieldIds, true)) {
                $out[$fid] = FormFile::whereIn('id', (array) $v)->get()->map(fn ($f) => $this->files->toArray($f))->values()->all();
            } else {
                $out[$fid] = $v;
            }
        }
        return $out;
    }

    private function owned(Request $r, Form $form, string $uuid): ?FormResponse
    {
        return $this->subs->findOwned($form, $uuid, $this->token($r), $this->actor($r));
    }

    // ---------------------------------------------------------------- autosave / events

    public function draft(Request $r, string $code, string $uuid): JsonResponse
    {
        $form = $this->form($code);
        $resp = $form ? $this->owned($r, $form, $uuid) : null;
        if (!$resp) {
            return $this->apiError('Response not found.', ['code' => 'not_found'], 404);
        }
        try {
            $resp = $this->subs->saveDraft($form, $resp, (array) $r->input('answers', []), $this->ctx($r) + ['respondent_name' => $r->input('respondent_name'), 'respondent' => (array) $r->input('respondent', [])]);
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess(['saved_at' => now()->toIso8601String(), 'seconds_left' => $this->subs->secondsLeft($resp)]);
    }

    public function event(Request $r, string $code, string $uuid): JsonResponse
    {
        $form = $this->form($code);
        $resp = $form ? $this->owned($r, $form, $uuid) : null;
        if (!$resp || !$form->isExam()) {
            return $this->apiError('Response not found.', ['code' => 'not_found'], 404);
        }
        $out = $this->subs->logEvent($form, $resp, (string) $r->input('type'), $r->input('detail'));
        return $this->apiSuccess($out + ['seconds_left' => $this->subs->secondsLeft($resp->fresh())]);
    }

    // ---------------------------------------------------------------- submit

    public function submit(Request $r, string $code): JsonResponse
    {
        $form = $this->form($code);
        if (!$form) {
            return $this->apiError('Form not found.', ['code' => 'not_found'], 404);
        }
        $resp = null;
        if ($uuid = $r->input('response_uuid')) {
            $resp = $this->owned($r, $form, (string) $uuid);
            if (!$resp) {
                return $this->apiError('Response not found.', ['code' => 'not_found'], 404);
            }
        }
        try {
            $res = $this->subs->submit($form, $resp, $this->actor($r), $this->ctx($r), (array) $r->input('answers', []), [
                'honeypot' => $r->input('website') ?: $r->input('hp'), 'opened_at_ms' => $r->input('opened_at_ms'), 'respondent_name' => $r->input('respondent_name'),
                'respondent' => (array) $r->input('respondent', []),
            ]);
        } catch (FormException $e) {
            return $this->fail($e);
        }
        $done = $res['response'];
        return $this->apiSuccess(['response_uuid' => $done->uuid, 'edit_token' => $done->edit_token, 'late' => $res['late']] + $this->subs->resultFor($form, $done), 'Submitted.', 201);
    }

    // ---------------------------------------------------------------- my response (result / edit)

    public function mine(Request $r, string $code, string $uuid): JsonResponse
    {
        $form = $this->form($code);
        $resp = $form ? $this->owned($r, $form, $uuid) : null;
        if (!$resp) {
            return $this->apiError('Response not found.', ['code' => 'not_found'], 404);
        }
        $data = $this->subs->resultFor($form, $resp) + ['response_uuid' => $resp->uuid, 'answers' => $this->myAnswers($resp), 'fields' => $this->publicFields($form, $resp)];
        // answer review for exams, only when the owner allows it and the score is already visible
        if ($form->isExam() && $form->setting('exam.show_correct_answers') && !empty($data['score'])) {
            $detail = $this->analytics->responseDetail($form, $resp)['answers'];
            $expl = FormField::where('form_id', $form->id)->pluck('explanation', 'id');
            $data['review'] = array_map(fn ($a) => [
                'field_id' => $a['field_id'], 'is_correct' => $a['is_correct'], 'points' => $a['points'], 'max_points' => $a['max_points'],
                'correct_display' => $a['correct_display'], 'feedback' => $a['feedback'], 'explanation' => $expl[$a['field_id']] ?? null,
            ], $detail);
        }
        return $this->apiSuccess($data);
    }

    public function edit(Request $r, string $code, string $uuid): JsonResponse
    {
        $form = $this->form($code);
        $resp = $form ? $this->owned($r, $form, $uuid) : null;
        if (!$resp) {
            return $this->apiError('Response not found.', ['code' => 'not_found'], 404);
        }
        try {
            $resp = $this->subs->edit($form, $resp, $this->actor($r), $this->ctx($r), (array) $r->input('answers', []));
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess($this->subs->resultFor($form, $resp), 'Response updated.');
    }

    // ---------------------------------------------------------------- files

    /** POST /f/{code}/files  multipart: field_id, file */
    public function upload(Request $r, string $code): JsonResponse
    {
        $form = $this->form($code);
        if (!$form) {
            return $this->apiError('Form not found.', ['code' => 'not_found'], 404);
        }
        try {
            $this->subs->assertAccess($form, $this->actor($r));
            $field = FormField::where('form_id', $form->id)->where('id', (int) $r->input('field_id'))->first();
            if (!$field) {
                throw new FormException('not_file_field', 'Question not found.', 404);
            }
            if ($r->input('response_uuid')) {
                $resp = $this->owned($r, $form, (string) $r->input('response_uuid'));
                if (!$resp || ($resp->isFinal() && !$this->subs->canEdit($form, $resp))) {
                    throw new FormException('forbidden', 'You cannot add files to this response.', 403);
                }
                if (!$resp->isFinal() && $resp->deadline_at && now()->gt($resp->deadline_at->copy()->addSeconds((int) config('forms.exam.grace_seconds', 20)))) {
                    throw new FormException('time_over', 'Time is over.', 409);
                }
            }
            $file = $this->files->upload($form, $field, $r->file('file'));
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess($this->files->toArray($file), 'File uploaded.', 201);
    }

    public function deleteUpload(Request $r, string $code, string $token): JsonResponse
    {
        $form = $this->form($code);
        if (!$form) {
            return $this->apiError('Form not found.', ['code' => 'not_found'], 404);
        }
        // only files not yet attached to a submitted answer can be discarded here (the answer edit flow handles the rest)
        return $this->files->deleteUnattached($form, $token) ? $this->apiSuccess(null, 'File removed.') : $this->apiError('File not found.', null, 404);
    }

    /** GET /forms/files/{id}?disp=inline|attachment — signed (relative) link, no auth header needed for <img>/<video>/<iframe>. */
    public function serveFile(Request $r, $id)
    {
        $f = FormFile::find((int) $id);
        $path = $f ? $this->files->absolutePath($f) : null;
        if (!$f || !$path) {
            return $this->apiError('File not found.', null, 404);
        }
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300'];
        $mime = $this->files->inlineMime($f);
        $name = $f->original_name;
        if ($r->query('disp') !== 'attachment' && $mime) {
            if ($f->extension === 'svg') {
                $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
            }
            return response()->file($path, $headers + ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . rawurlencode($name) . '"']);
        }
        return response()->download($path, $name, $headers + ['Content-Type' => 'application/octet-stream']);
    }
}
