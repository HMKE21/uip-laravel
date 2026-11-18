<?php

namespace App\Http\Controllers\Api\Forms;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormCollaborator;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Services\Forms\FormAnalyticsService;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormException;
use App\Services\Forms\FormFileService;
use App\Services\Forms\FormGradingService;
use App\Services\Forms\FormSubmissionService;
use App\Services\Forms\FormTemplates;
use App\Support\Forms\FormFieldTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/v1/forms/* — for the owner (any role) and collaborators: build, publish, responses, analytics, export. */
class FormsManageApiController extends Controller
{
    public function __construct(
        private FormBuilderService $builder,
        private FormSubmissionService $submissions,
        private FormGradingService $grading,
        private FormAnalyticsService $analytics,
        private FormFileService $files
    ) {
    }

    private function uid(Request $r): int { return (int) $r->attributes->get('uip_user_id'); }
    private function role(Request $r): ?string { return $r->attributes->get('uip_role'); }

    private function fail(FormException $e): JsonResponse
    {
        return $this->apiError($e->getMessage(), ['code' => $e->errorCode] + $e->extra, $e->httpStatus);
    }

    /** Unknown failure while saving: log the real cause, answer with a clear, retryable message (the builder retries by itself). */
    private function unexpected(\Throwable $e, string $op): JsonResponse
    {
        \Log::error('forms.' . $op . ' failed: ' . $e->getMessage(), ['exception' => $e]);
        return $this->apiError('تعذّر الحفظ مؤقتاً، هنحاول تاني تلقائياً.', ['code' => 'save_failed', 'retryable' => true], 500);
    }

    /** @return Form|JsonResponse */
    private function load(Request $r, $id, bool $needEdit = true)
    {
        $form = Form::find((int) $id);
        if (!$form || !$this->builder->canManage($form, $this->uid($r), $this->role($r), $needEdit)) {
            return $this->apiError('Form not found.', null, 404); // 404 (not 403) so ids cannot be probed
        }
        return $form;
    }

    private function card(Form $f): array
    {
        return [
            'id' => (int) $f->id, 'uuid' => $f->uuid, 'title' => $f->title, 'description' => $f->description, 'icon' => $f->icon, 'color' => $f->color,
            'mode' => $f->mode, 'status' => $f->status, 'public_code' => $f->public_code, 'slug' => $f->slug, 'url' => $this->builder->publicUrl($f),
            'responses_count' => (int) $f->responses_count, 'opens_at' => $f->opens_at?->toIso8601String(), 'closes_at' => $f->closes_at?->toIso8601String(),
            'published_at' => $f->published_at?->toIso8601String(), 'last_response_at' => $f->last_response_at?->toIso8601String(),
            'created_at' => $f->created_at?->toIso8601String(), 'updated_at' => $f->updated_at?->toIso8601String(),
        ];
    }

    private function full(Form $f): array
    {
        return $this->card($f) + [
            'settings' => \App\Services\Forms\FormSettings::publicView($f->settings),
            'fields' => $f->fields()->get()->map(fn ($x) => [
                'id' => (int) $x->id, 'type' => $x->type, 'label' => $x->label, 'help' => $x->help, 'required' => (bool) $x->required, 'position' => (int) $x->position,
                'config' => $x->config ?: (object) [], 'logic' => $x->logic, 'points' => (float) $x->points, 'correct' => $x->correct, 'explanation' => $x->explanation,
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------- meta

    /** GET /forms/meta — field types, templates, icons, colors, roles (for the builder UI). */
    public function meta(): JsonResponse
    {
        return $this->apiSuccess([
            'field_types' => collect(FormFieldTypes::TYPES)->map(fn ($v, $k) => ['type' => $k] + $v)->values()->all(),
            'templates' => collect(FormTemplates::all())->map(fn ($t, $k) => ['key' => $k, 'title' => $t['title'], 'description' => $t['description'], 'icon' => $t['icon'], 'color' => $t['color'], 'mode' => $t['mode'], 'questions' => count($t['fields'])])->values()->all(),
            'icons' => config('forms.icons'), 'colors' => config('forms.colors'), 'roles' => config('roles.available'),
            'limits' => config('forms.limits'),
        ]);
    }

    // ---------------------------------------------------------------- CRUD

    /** GET /forms?scope=mine|shared&status=&mode=&search= */
    public function index(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        $q = Form::query();
        $sharedIds = FormCollaborator::where('user_id', $uid)->pluck('form_id');
        if ($r->query('scope') === 'shared') {
            $q->whereIn('id', $sharedIds);
        } elseif ($r->query('scope') === 'all' && $this->role($r) === 'admin') {
            // admin sees everything
        } else {
            $q->where('owner_user_id', $uid);
        }
        if (in_array($r->query('status'), ['draft', 'published', 'closed'], true)) {
            $q->where('status', $r->query('status'));
        }
        if (in_array($r->query('mode'), ['form', 'exam'], true)) {
            $q->where('mode', $r->query('mode'));
        }
        if ($s = trim((string) $r->query('search', ''))) {
            $q->where('title', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%');
        }
        $per = max(1, min(60, (int) $r->query('per_page', 24)));
        $p = $q->orderByDesc('updated_at')->paginate($per);
        return $this->apiSuccess($p->getCollection()->map(fn ($f) => $this->card($f))->all(), 'Forms retrieved successfully.', 200, [
            'page' => $p->currentPage(), 'perPage' => $per, 'total' => $p->total(), 'totalPages' => $p->lastPage(),
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        try {
            if ($tpl = $r->input('template')) {
                $form = $this->builder->createFromTemplate($this->uid($r), (string) $this->role($r), (string) $tpl, $r->input('title'));
            } else {
                $form = $this->builder->create($this->uid($r), (string) $this->role($r), $r->all());
            }
        } catch (FormException $e) {
            return $this->fail($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e, 'create');
        }
        return $this->apiSuccess($this->full($form), 'Form created successfully.', 201);
    }

    public function show(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id, false);
        return $form instanceof JsonResponse ? $form : $this->apiSuccess($this->full($form));
    }

    public function update(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        try {
            $form = $this->builder->update($form, $r->all());
        } catch (FormException $e) {
            return $this->fail($e);
        } catch (\Throwable $e) {
            return $this->unexpected($e, 'update');
        }
        return $this->apiSuccess($this->full($form), 'Form saved.');
    }

    public function destroy(Request $r, $id): JsonResponse
    {
        $form = Form::find((int) $id);
        if (!$form || ((int) $form->owner_user_id !== $this->uid($r) && $this->role($r) !== 'admin')) {
            return $this->apiError('Form not found.', null, 404);
        }
        foreach (FormResponse::where('form_id', $form->id)->cursor() as $resp) {
            $this->submissions->deleteResponse($form, $resp);
        }
        foreach (FormFile::where('form_id', $form->id)->get() as $f) { // unattached uploads
            app(\App\Services\FileUploadService::class)->delete($f->stored_path);
            $f->delete();
        }
        $form->delete(); // soft delete keeps the link reserved
        return $this->apiSuccess(null, 'Form deleted.');
    }

    public function publish(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        try {
            $form = $this->builder->publish($form);
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess($this->card($form), 'Form published.');
    }

    public function close(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id);
        return $form instanceof JsonResponse ? $form : $this->apiSuccess($this->card($this->builder->close($form)), 'Form closed.');
    }

    public function duplicate(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        try {
            $copy = $this->builder->duplicate($form, $this->uid($r), (string) $this->role($r));
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess($this->full($copy), 'Form duplicated.', 201);
    }

    /** POST /forms/{id}/slug-check {slug} */
    public function slugCheck(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $slug = \Illuminate\Support\Str::slug((string) $r->input('slug'));
        $taken = $slug === '' || in_array($slug, FormBuilderService::RESERVED_SLUGS, true)
            || Form::withTrashed()->where('id', '!=', $form->id)->where(fn ($w) => $w->where('slug', $slug)->orWhere('public_code', $slug))->exists();
        return $this->apiSuccess(['slug' => $slug, 'available' => !$taken]);
    }

    // ---------------------------------------------------------------- collaborators

    public function collaborators(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $rows = DB::table('form_collaborators as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('c.form_id', $form->id)
            ->get(['c.user_id', 'c.permission', 'u.full_name', 'u.email']);
        return $this->apiSuccess($rows->all());
    }

    /** PUT /forms/{id}/collaborators {email, permission} — owner only. */
    public function addCollaborator(Request $r, $id): JsonResponse
    {
        $form = Form::find((int) $id);
        if (!$form || (int) $form->owner_user_id !== $this->uid($r)) {
            return $this->apiError('Form not found.', null, 404);
        }
        $user = DB::table('users')->where('email', mb_strtolower(trim((string) $r->input('email'))))->first(['id']);
        if (!$user || (int) $user->id === $this->uid($r)) {
            return $this->apiError('User not found.', null, 404);
        }
        $perm = $r->input('permission') === 'edit' ? 'edit' : 'view';
        FormCollaborator::updateOrCreate(['form_id' => $form->id, 'user_id' => $user->id], ['permission' => $perm]);
        return $this->apiSuccess(null, 'Collaborator saved.');
    }

    public function removeCollaborator(Request $r, $id, $userId): JsonResponse
    {
        $form = Form::find((int) $id);
        if (!$form || (int) $form->owner_user_id !== $this->uid($r)) {
            return $this->apiError('Form not found.', null, 404);
        }
        FormCollaborator::where('form_id', $form->id)->where('user_id', (int) $userId)->delete();
        return $this->apiSuccess(null, 'Collaborator removed.');
    }

    // ---------------------------------------------------------------- responses

    public function analytics(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id, false);
        return $form instanceof JsonResponse ? $form : $this->apiSuccess($this->analytics->summary($form));
    }

    public function responses(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $res = $this->analytics->responsesPage($form, $r->query());
        return $this->apiSuccess($res['items'], 'Responses retrieved successfully.', 200, [
            'page' => $res['page'], 'perPage' => $res['per_page'], 'total' => $res['total'], 'totalPages' => $res['total_pages'],
        ]);
    }

    private function loadResponse(Request $r, $id, $rid)
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return [$form, null];
        }
        $resp = FormResponse::where('form_id', $form->id)->where('id', (int) $rid)->first();
        return $resp ? [$form, $resp] : [$this->apiError('Response not found.', null, 404), null];
    }

    public function showResponse(Request $r, $id, $rid): JsonResponse
    {
        [$form, $resp] = $this->loadResponse($r, $id, $rid);
        return $resp ? $this->apiSuccess($this->analytics->responseDetail($form, $resp)) : $form;
    }

    /** POST /forms/{id}/responses/{rid}/grade {field_id, points, feedback} */
    public function grade(Request $r, $id, $rid): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $resp = FormResponse::where('form_id', $form->id)->where('id', (int) $rid)->first();
        if (!$resp || !$resp->isFinal()) {
            return $this->apiError('Response not found.', null, 404);
        }
        try {
            $resp = $this->grading->manualGrade($form, $resp, (int) $r->input('field_id'), (float) $r->input('points', 0), $r->input('feedback'));
        } catch (FormException $e) {
            return $this->fail($e);
        }
        return $this->apiSuccess($this->analytics->responseDetail($form, $resp), 'Grade saved.');
    }

    /** POST /forms/{id}/regrade — re-run auto grading for every response (after fixing a wrong answer key). */
    public function regrade(Request $r, $id): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $fields = $form->fields()->get();
        $n = 0;
        FormResponse::where('form_id', $form->id)->whereIn('status', ['submitted', 'graded', 'auto_submitted'])->chunkById(200, function ($rows) use ($form, $fields, &$n) {
            foreach ($rows as $resp) {
                $this->grading->gradeResponse($form, $resp, $fields);
                $n++;
            }
        });
        return $this->apiSuccess(['regraded' => $n], 'Responses regraded.');
    }

    public function destroyResponse(Request $r, $id, $rid): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $resp = FormResponse::where('form_id', $form->id)->where('id', (int) $rid)->first();
        if (!$resp) {
            return $this->apiError('Response not found.', null, 404);
        }
        $this->submissions->deleteResponse($form, $resp);
        return $this->apiSuccess(null, 'Response deleted.');
    }

    /** POST /forms/{id}/responses/{rid}/reopen — give an exam taker another chance (resets to in_progress with a fresh timer). */
    public function reopen(Request $r, $id, $rid): JsonResponse
    {
        $form = $this->load($r, $id);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $resp = FormResponse::where('form_id', $form->id)->where('id', (int) $rid)->first();
        if (!$resp || !$resp->isFinal()) {
            return $this->apiError('Response not found.', null, 404);
        }
        $resp->status = 'in_progress';
        $resp->submitted_at = null;
        $resp->score = null;
        $resp->violations = 0;
        $mins = (int) $form->setting('exam.duration_minutes', 0);
        $resp->deadline_at = $mins > 0 ? now()->addMinutes($mins) : null;
        $resp->save();
        Form::where('id', $form->id)->where('responses_count', '>', 0)->update(['responses_count' => DB::raw('responses_count - 1')]);
        return $this->apiSuccess(null, 'Response reopened for the respondent.');
    }

    // ---------------------------------------------------------------- files + export

    public function exportCsv(Request $r, $id)
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $name = 'form-' . $form->id . '-responses-' . date('Ymd-His') . '.csv';
        return response()->streamDownload(function () use ($form) {
            $h = fopen('php://output', 'w');
            $this->analytics->exportCsv($form, $h);
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /forms/{id}/files-archive?field_id= — every uploaded file in one zip. */
    public function filesArchive(Request $r, $id)
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $tmp = $this->files->buildArchive($form, $r->query('field_id') ? (int) $r->query('field_id') : null);
        if (!$tmp) {
            return $this->apiError('There are no files to download.', null, 404);
        }
        return response()->download($tmp, 'form-' . $form->id . '-files.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /** GET /forms/{id}/files/{fileId}/preview — text/code content or zip entry listing (auth, owner side). */
    public function filePreview(Request $r, $id, $fileId): JsonResponse
    {
        $form = $this->load($r, $id, false);
        if ($form instanceof JsonResponse) {
            return $form;
        }
        $f = FormFile::where('form_id', $form->id)->where('id', (int) $fileId)->first();
        if (!$f) {
            return $this->apiError('File not found.', null, 404);
        }
        try {
            return $this->apiSuccess($this->files->toArray($f) + ['content' => in_array($this->files->previewType($f), ['text', 'archive'], true) ? $this->files->textPreview($f) : null]);
        } catch (FormException $e) {
            return $this->fail($e);
        }
    }
}
