<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\FaqIntentRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Admin/FaqIntentController.php القديمة —
 * /api/v1/admin/faq-intents/*. CRUD + تفعيل/تعطيل + أنالتكس لـ
 * faq_intents/faq_unmatched_log، تحت نفس زوج middleware
 * (uip.auth + uip.admin) اللي كل مجموعة /admin/* تانية بتستخدمه. كل
 * تعديل بيتسجل audit زي AiAssistantSettingsController::update().
 */
class FaqIntentController extends Controller
{
    public function __construct(
        private FaqIntentRepository $repo,
        private AuditLogService $auditLog
    ) {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** GET /api/v1/admin/faq-intents — كل الـ intents (فعّالة أو لأ)، لشاشة إدارة الأدمن. */
    public function index(Request $request)
    {
        return $this->apiSuccess($this->repo->all(), 'FAQ intents retrieved successfully.');
    }

    /** GET /api/v1/admin/faq-intents/{id} */
    public function show(Request $request, $id)
    {
        $intent = $this->repo->find((int) $id);
        if (!$intent) {
            return $this->apiError('FAQ intent not found.', null, 404);
        }
        return $this->apiSuccess($intent->toArray(), 'FAQ intent retrieved successfully.');
    }

    /** POST /api/v1/admin/faq-intents */
    public function store(Request $request)
    {
        $data = $this->validatedPayload($request);
        if ($data === null) {
            return $this->apiError('intent_key, question_ar/question_en, and answer_ar/answer_en are required.', null, 422);
        }

        if ($this->repo->findByKey($data['intent_key'])) {
            return $this->apiError('An intent with this key already exists.', null, 422);
        }

        $intent = $this->repo->create($data, $this->uid($request));
        $this->auditLog->record($this->uid($request), 'faq_intent.created', 'faq_intent', $intent->id, null, $intent->toArray(), $request->ip());

        return $this->apiSuccess($intent->toArray(), 'FAQ intent created successfully.', 201);
    }

    /** PUT /api/v1/admin/faq-intents/{id} */
    public function update(Request $request, $id)
    {
        $intentId = (int) $id;
        $before = $this->repo->find($intentId);
        if (!$before) {
            return $this->apiError('FAQ intent not found.', null, 404);
        }

        $data = $this->validatedPayload($request, false);
        if ($data === null) {
            return $this->apiError('Invalid FAQ intent data.', null, 422);
        }

        $beforeArray = $before->toArray();
        $intent = $this->repo->update($intentId, $data, $this->uid($request));
        $this->auditLog->record($this->uid($request), 'faq_intent.updated', 'faq_intent', $intentId, $beforeArray, $intent->toArray(), $request->ip());

        return $this->apiSuccess($intent->toArray(), 'FAQ intent updated successfully.');
    }

    /** POST /api/v1/admin/faq-intents/{id}/active — body: is_active */
    public function setActive(Request $request, $id)
    {
        $intentId = (int) $id;
        $intent = $this->repo->setActive($intentId, (bool) $request->input('is_active', true));
        if (!$intent) {
            return $this->apiError('FAQ intent not found.', null, 404);
        }
        $this->auditLog->record($this->uid($request), 'faq_intent.active_toggled', 'faq_intent', $intentId, null, ['is_active' => $intent->is_active], $request->ip());

        return $this->apiSuccess($intent->toArray(), 'FAQ intent updated successfully.');
    }

    /** DELETE /api/v1/admin/faq-intents/{id} */
    public function destroy(Request $request, $id)
    {
        $intentId = (int) $id;
        $before = $this->repo->find($intentId);
        if (!$before || !$this->repo->delete($intentId)) {
            return $this->apiError('FAQ intent not found.', null, 404);
        }
        $this->auditLog->record($this->uid($request), 'faq_intent.deleted', 'faq_intent', $intentId, $before->toArray(), null, $request->ip());

        return $this->apiSuccess(['deleted' => true], 'FAQ intent deleted successfully.');
    }

    // -- Analytics --------------------------------------------------------------

    /** GET /api/v1/admin/faq-intents/unmatched — query: limit */
    public function unmatched(Request $request)
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        return $this->apiSuccess($this->repo->topUnmatched($limit), 'Unmatched questions retrieved successfully.');
    }

    /** GET /api/v1/admin/faq-intents/top-matched */
    public function topMatched(Request $request)
    {
        $intents = $this->repo->all();
        usort($intents, fn ($a, $b) => $b['hit_count'] <=> $a['hit_count']);
        $rows = array_map(fn ($i) => [
            'intent_key'  => $i['intent_key'],
            'category'    => $i['category'],
            'hit_count'   => $i['hit_count'],
            'last_hit_at' => $i['last_hit_at'],
            'is_active'   => $i['is_active'],
        ], $intents);

        return $this->apiSuccess($rows, 'Top matched intents retrieved successfully.');
    }

    /**
     * @return array<string,mixed>|null null لو حقول أساسية ناقصة (إنشاء)
     *   أو لو مفيش أي حاجة صالحة اتبعتت خالص (تعديل).
     */
    private function validatedPayload(Request $request, bool $requireCore = true): ?array
    {
        $body = $request->all();
        $out = [];

        foreach (['intent_key', 'category', 'question_ar', 'question_en', 'answer_ar', 'answer_en'] as $field) {
            if (array_key_exists($field, $body)) {
                $out[$field] = trim((string) $body[$field]);
            }
        }
        foreach (['aliases_ar', 'aliases_en', 'aliases_mixed', 'keywords'] as $field) {
            if (array_key_exists($field, $body) && is_array($body[$field])) {
                $out[$field] = $body[$field];
            }
        }
        if (array_key_exists('role', $body)) {
            $out['role'] = is_array($body['role']) ? $body['role'] : array_filter(explode(',', (string) $body['role']));
        }
        foreach (['priority', 'is_active', 'confidence_threshold'] as $field) {
            if (array_key_exists($field, $body)) {
                $out[$field] = $body[$field];
            }
        }

        if ($requireCore) {
            $hasQuestion = !empty($out['question_ar']) || !empty($out['question_en']);
            $hasAnswer = !empty($out['answer_ar']) || !empty($out['answer_en']);
            if (empty($out['intent_key']) || !$hasQuestion || !$hasAnswer) {
                return null;
            }
        } elseif (!$out) {
            return null;
        }

        return $out;
    }
}
