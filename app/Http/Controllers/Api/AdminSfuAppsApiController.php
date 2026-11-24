<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\SelfHostedSfuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin → Meetings Control → UIPE Realtime Apps (نسخة UIPE من Cloudflare App ID / App Secret).
 * الأدمن بس هو اللي بيصدر الـ App ID + App Secret للناس اللي بتطلبهم. المسارات تحت
 * /api/v1/admin/meetings/control/sfu-apps ومحمية بـ uip.auth + uip.admin.
 *
 *   GET    sfu-apps                       قايمة التطبيقات + الحدود الافتراضية + العنوان العلني
 *   POST   sfu-apps                       إنشاء تطبيق → بيرجّع الـ secret مرة واحدة بس
 *   GET    sfu-apps/{id}                  تفاصيل + الغرف الشغالة
 *   PATCH  sfu-apps/{id}                  تعديل (اسم/مالك/ملاحظات/حدود/انتهاء/status active|suspended)
 *   POST   sfu-apps/{id}/rotate-secret    سر جديد (القديم بيبطل فورًا) → بيرجّع السر مرة واحدة
 *   POST   sfu-apps/{id}/close-rooms      قفل كل الغرف الشغالة للتطبيق
 *   DELETE sfu-apps/{id}                  حذف التطبيق (بيقفل غرفه)
 *
 * الـ secret مابيتخزنش في Laravel ولا في الـ audit log أبدًا؛ سيرفر الـ SFU بيخزّن hash بس.
 * كل إجراء بيتسجّل في audit_logs بـ action = admin.meetings.sfu_app_*.
 */
class AdminSfuAppsApiController extends Controller
{
    public function __construct(
        private SelfHostedSfuService $sfu,
        private AuditLogService $auditLog
    ) {
    }

    private function log(Request $request, string $action, ?string $appId, ?array $old = null, array $new = []): void
    {
        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'admin.meetings.' . $action, 'SfuApp', null, $old, $new + ['app_id' => $appId], $request->ip());
    }

    /** @param array<string,mixed> $a */
    private function shape(array $a): array
    {
        return [
            'id'                => $a['id'],
            'name'              => $a['name'] ?? '',
            'owner_name'        => $a['ownerName'] ?? '',
            'owner_email'       => $a['ownerEmail'] ?? '',
            'notes'             => $a['notes'] ?? '',
            'status'            => $a['status'] ?? 'active',
            'limits'            => [
                'max_peers_per_room'   => $a['limits']['maxPeersPerRoom'] ?? null,
                'max_concurrent_peers' => $a['limits']['maxConcurrentPeers'] ?? null,
                'max_rooms'            => $a['limits']['maxRooms'] ?? null,
                'rate_limit_per_min'   => $a['limits']['rateLimitPerMin'] ?? null,
            ],
            'secret_last4'      => $a['secretLast4'] ?? null,
            'created_at'        => $a['createdAt'] ?? null,
            'updated_at'        => $a['updatedAt'] ?? null,
            'secret_rotated_at' => $a['secretRotatedAt'] ?? null,
            'expires_at'        => $a['expiresAt'] ?? null,
            'usage'             => [
                'sessions'     => $a['usage']['sessions'] ?? 0,
                'requests'     => $a['usage']['requests'] ?? 0,
                'last_used_at' => $a['usage']['lastUsedAt'] ?? null,
            ],
            'live'              => ['rooms' => $a['live']['rooms'] ?? 0, 'peers' => $a['live']['peers'] ?? 0],
        ];
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name'                         => [$req, 'string', 'min:2', 'max:80'],
            'owner_name'                   => ['sometimes', 'nullable', 'string', 'max:120'],
            'owner_email'                  => ['sometimes', 'nullable', 'email', 'max:160'],
            'notes'                        => ['sometimes', 'nullable', 'string', 'max:500'],
            'expires_at'                   => ['sometimes', 'nullable', 'date', 'after:now'],
            'status'                       => ['sometimes', 'in:active,suspended'],
            'limits'                       => ['sometimes', 'array'],
            'limits.max_peers_per_room'    => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'limits.max_concurrent_peers'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            'limits.max_rooms'             => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'limits.rate_limit_per_min'    => ['sometimes', 'nullable', 'integer', 'min:10', 'max:20000'],
        ];
    }

    /** snake_case (الفرونت) → camelCase (سيرفر الـ SFU). بيبعت بس اللي اتبعت. */
    private function toSfu(array $in): array
    {
        $out = [];
        foreach (['name' => 'name', 'owner_name' => 'ownerName', 'owner_email' => 'ownerEmail', 'notes' => 'notes', 'expires_at' => 'expiresAt', 'status' => 'status'] as $from => $to) {
            if (array_key_exists($from, $in)) {
                $out[$to] = $in[$from];
            }
        }
        if (isset($in['limits']) && is_array($in['limits'])) {
            $map = ['max_peers_per_room' => 'maxPeersPerRoom', 'max_concurrent_peers' => 'maxConcurrentPeers', 'max_rooms' => 'maxRooms', 'rate_limit_per_min' => 'rateLimitPerMin'];
            foreach ($map as $from => $to) {
                if (isset($in['limits'][$from]) && $in['limits'][$from] !== '') {
                    $out['limits'][$to] = (int) $in['limits'][$from];
                }
            }
        }

        return $out;
    }

    /** بيحوّل فشل سيرفر الـ SFU لرد مفهوم للأدمن. */
    private function sfuError(array $r): JsonResponse
    {
        $err = (string) ($r['data']['error'] ?? 'sfu_error');
        $msg = match (true) {
            $err === 'sfu_not_configured' => 'Video server URL / secret are not set (Video servers tab).',
            $r['status'] === 504          => 'Could not reach the video server (check URL and firewall).',
            in_array($r['status'], [401, 403], true) => 'The video server rejected the internal secret.',
            $r['status'] === 404 && $err === 'app_not_found' => 'App not found on the video server.',
            $r['status'] === 404          => 'The video server is running an old version without Realtime Apps. Update uipe-sfu first.',
            $r['status'] === 422          => $err,
            default                       => 'Video server error.',
        };
        $status = in_array($r['status'], [404, 422], true) ? $r['status'] : 502;

        return $this->apiError($msg, ['code' => $err], $status);
    }

    private function publicBase(): string
    {
        $pub = (string) config('webrtc.platform.public_url', '');

        return $pub !== '' ? $pub : rtrim((string) (\App\Services\MeetingAdminSettings::sfu()['selfhosted_url'] ?? config('webrtc.selfhosted.url', '')), '/');
    }

    public function index()
    {
        $r = $this->sfu->adminRequest('GET', 'admin/apps');
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $d = $r['data'];

        return $this->apiSuccess([
            'apps'            => array_map(fn ($a) => $this->shape($a), $d['apps'] ?? []),
            'defaults'        => [
                'max_peers_per_room'   => $d['defaults']['maxPeersPerRoom'] ?? 50,
                'max_concurrent_peers' => $d['defaults']['maxConcurrentPeers'] ?? 200,
                'max_rooms'            => $d['defaults']['maxRooms'] ?? 50,
                'rate_limit_per_min'   => $d['defaults']['rateLimitPerMin'] ?? 600,
            ],
            'public_base_url' => $this->publicBase(),
            'public_url_is_set' => (string) config('webrtc.platform.public_url', '') !== '',
        ]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        $r = $this->sfu->adminRequest('POST', 'admin/apps', $this->toSfu($v->validated()) + ['createdBy' => (int) $request->attributes->get('uip_user_id')]);
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $app = $this->shape($r['data']['app']);
        $this->log($request, 'sfu_app_created', $app['id'], null, ['name' => $app['name'], 'owner_email' => $app['owner_email'], 'limits' => $app['limits']]);

        // الـ secret بيرجع هنا مرة واحدة بس (مابيتسجّلش في أي مكان).
        return $this->apiSuccess(['app' => $app, 'secret' => $r['data']['secret'], 'public_base_url' => $this->publicBase()], 'App created. Copy the secret now — it will not be shown again.', 201);
    }

    public function show(string $id)
    {
        $r = $this->sfu->adminRequest('GET', 'admin/apps/' . $id);
        if (!$r['ok']) {
            return $this->sfuError($r);
        }

        return $this->apiSuccess(['app' => $this->shape($r['data']['app']), 'rooms' => $r['data']['rooms'] ?? []]);
    }

    public function update(Request $request, string $id)
    {
        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }
        $body = $this->toSfu($v->validated());
        if (!$body) {
            return $this->apiError('Nothing to update.', null, 422);
        }
        $r = $this->sfu->adminRequest('PATCH', 'admin/apps/' . $id, $body);
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $this->log($request, 'sfu_app_updated', $id, null, $v->validated());

        return $this->apiSuccess(['app' => $this->shape($r['data']['app'])], 'App updated.');
    }

    public function rotateSecret(Request $request, string $id)
    {
        $r = $this->sfu->adminRequest('POST', 'admin/apps/' . $id . '/rotate-secret');
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $this->log($request, 'sfu_app_secret_rotated', $id, null, []);

        return $this->apiSuccess(['app' => $this->shape($r['data']['app']), 'secret' => $r['data']['secret']], 'New secret generated. The old one stopped working.');
    }

    public function closeRooms(Request $request, string $id)
    {
        $r = $this->sfu->adminRequest('POST', 'admin/apps/' . $id . '/close-rooms');
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $this->log($request, 'sfu_app_rooms_closed', $id, null, ['closed' => $r['data']['closed'] ?? 0]);

        return $this->apiSuccess(['closed' => $r['data']['closed'] ?? 0], 'Rooms closed.');
    }

    public function destroy(Request $request, string $id)
    {
        $r = $this->sfu->adminRequest('DELETE', 'admin/apps/' . $id);
        if (!$r['ok']) {
            return $this->sfuError($r);
        }
        $this->log($request, 'sfu_app_deleted', $id, null, []);

        return $this->apiSuccess(['deleted' => true], 'App deleted.');
    }
}
