<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EscalationService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * بند 7 — قنوات التصعيد: /api/v1/security/escalation/*
 *  channels   : قنوات الإرسال (email / Slack / Telegram / Webhook) + رسالة تجريبية
 *  policies   : قواعد التصعيد (مصدر + خطورة + أنواع + قنوات + مناوب + مستوى تاني بعد مهلة)
 *  oncall     : جدول المناوبة
 *  digests    : ملخصات أمنية مجدولة بتتبعت تلقائي
 *  deliveries : سجل الإرسال
 *
 * الصلاحيات من الراوت: security.escalation.view (قراءة) / security.escalation.manage (أي كتابة). وكمان فحص الدور هنا.
 */
class SecurityEscalationApiController extends Controller
{
    public function __construct(private EscalationService $esc, private PermissionService $permissions)
    {
    }

    private function staff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function uid(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id ? (int) $id : null;
    }

    private function deny(): JsonResponse
    {
        return $this->apiError('Only Security Portal accounts can use escalation channels.', null, 403);
    }

    /** يشغّل $fn بعد فحص الدور، ويحوّل أخطاء التحقق لـ 422. */
    private function guard(Request $request, callable $fn): JsonResponse
    {
        if (!$this->staff($request)) {
            return $this->deny();
        }
        try {
            return $fn();
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    public function overview(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->overview(), 'Escalation overview retrieved successfully.', 200, [
            'can_manage' => $this->permissions->currentUserCan($this->uid($request), (string) $request->attributes->get('uip_role'), 'security.escalation.manage'),
        ]));
    }

    // ─────────────── channels ───────────────

    public function channels(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->channels(), 'Channels retrieved successfully.'));
    }

    public function channelStore(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->saveChannel(null, $request->all(), $this->uid($request), $request->ip()), 'Channel created.', 201));
    }

    public function channelUpdate(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->saveChannel($id, $request->all(), $this->uid($request), $request->ip()), 'Channel saved.'));
    }

    public function channelDestroy(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->esc->deleteChannel($id, $this->uid($request), $request->ip())
            ? $this->apiSuccess(null, 'Channel deleted.') : $this->apiError('Channel not found.', null, 404));
    }

    public function channelTest(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, function () use ($request, $id) {
            $r = $this->esc->testChannel($id, $this->uid($request), $request->ip());

            return $r['ok'] ? $this->apiSuccess($r, 'Test message delivered.') : $this->apiError('Test message failed: ' . ($r['error'] ?? 'unknown error'), $r, 422);
        });
    }

    // ─────────────── policies ───────────────

    public function policies(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess(['policies' => $this->esc->policies(), 'channels' => $this->esc->channels()], 'Policies retrieved successfully.'));
    }

    public function policyStore(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->savePolicy(null, $request->all(), $this->uid($request), $request->ip()), 'Policy created.', 201));
    }

    public function policyUpdate(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->savePolicy($id, $request->all(), $this->uid($request), $request->ip()), 'Policy saved.'));
    }

    public function policyDestroy(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->esc->deletePolicy($id, $this->uid($request), $request->ip())
            ? $this->apiSuccess(null, 'Policy deleted.') : $this->apiError('Policy not found.', null, 404));
    }

    // ─────────────── on-call ───────────────

    public function oncall(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->oncall(), 'On-call rota retrieved successfully.'));
    }

    public function shiftStore(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request) {
            $this->esc->addShift($request->all(), $this->uid($request), $request->ip());

            return $this->apiSuccess($this->esc->oncall(), 'Shift added.', 201);
        });
    }

    public function shiftDestroy(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->esc->deleteShift($id, $this->uid($request), $request->ip())
            ? $this->apiSuccess($this->esc->oncall(), 'Shift removed.') : $this->apiError('Shift not found.', null, 404));
    }

    // ─────────────── digests ───────────────

    public function digests(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess(['digests' => $this->esc->digests(), 'channels' => $this->esc->channels(), 'sections' => EscalationService::SECTIONS], 'Digests retrieved successfully.'));
    }

    public function digestStore(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->saveDigest(null, $request->all(), $this->uid($request), $request->ip()), 'Digest created.', 201));
    }

    public function digestUpdate(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess($this->esc->saveDigest($id, $request->all(), $this->uid($request), $request->ip()), 'Digest saved.'));
    }

    public function digestDestroy(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, fn () => $this->esc->deleteDigest($id, $this->uid($request), $request->ip())
            ? $this->apiSuccess(null, 'Digest deleted.') : $this->apiError('Digest not found.', null, 404));
    }

    public function digestRun(Request $request, int $id): JsonResponse
    {
        return $this->guard($request, function () use ($id) {
            $r = $this->esc->runDigest($id);

            return $r['sent'] > 0 ? $this->apiSuccess($r, 'Digest sent.') : $this->apiError('Digest could not be delivered to any channel. Check the delivery log.', $r, 422);
        });
    }

    // ─────────────── deliveries ───────────────

    public function deliveries(Request $request): JsonResponse
    {
        return $this->guard($request, fn () => $this->apiSuccess(
            $this->esc->deliveries((int) $request->query('limit', 100), $request->query('status'), $request->query('kind')),
            'Delivery log retrieved successfully.'
        ));
    }
}
