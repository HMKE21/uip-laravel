<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ApiTokenRepository;
use App\Services\AuditLogService;
use App\Services\BlockedDeviceService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminMobileApiController.php القديمة —
 * بند 25 batch 4. سطح /api/v1/admin/mobile-tokens JSON واحد، بيعيد
 * استخدام ApiTokenRepository::allWithCreator()/create()/revoke()/
 * activeCount() بالظبط زي ما Admin\AdminMobileController القديمة كانت
 * بتعمل. "Mobile App Management" هو فعليًا إدارة الـ API bearer tokens
 * طويلة العمر الصادرة للعميل الموبايل المستقبلي. نفس نمط "one-time
 * reveal" بتاع القديمة: الـ raw token بيترجع في response إنشاء الـ
 * token فقط، ومش بيتخزن غير الـ SHA-256 hash بتاعه. مفيش حاجة مختلَقة هنا.
 */
class AdminMobileApiController extends Controller
{
    public function __construct(private ApiTokenRepository $tokens, private AuditLogService $auditLog, private BlockedDeviceService $blockedDevices)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage mobile app tokens.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/mobile-tokens */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->tokens->allWithCreator(), 'Mobile app tokens retrieved successfully.', 200, [
            'activeCount' => $this->tokens->activeCount(),
        ]);
    }

    /** POST /api/v1/admin/mobile-tokens — {name}. Returns the raw token once, in the response body. */
    public function store(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiError('Give this token a name (e.g. which app build it is for).', null, 422);
        }

        $raw = bin2hex(random_bytes(32));
        $adminId = $request->attributes->get('uip_user_id');

        $token = $this->tokens->create([
            'name'       => $name,
            'token_hash' => hash('sha256', $raw),
            'created_by' => $adminId,
        ]);

        $this->auditLog->record($adminId, 'api_token.create', 'ApiToken', $token->id, null, ['name' => $name]);

        return $this->apiSuccess([
            'id'           => $token->id,
            'name'         => $name,
            'issued_token' => $raw, // shown once — never recoverable after this response
        ], 'Token created. Copy it now — it will not be shown again.', 201);
    }

    /** POST /api/v1/admin/mobile-tokens/{id}/revoke */
    public function revoke(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $ok = $this->tokens->revoke((int) $id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'api_token.revoke', 'ApiToken', (int) $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'Token revoked.')
            : $this->apiError('Token not found or already revoked.', null, 404);
    }

    /** DELETE /api/v1/admin/mobile-tokens/{id} — permanently removes a token that was already revoked. */
    public function destroy(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $ok = $this->tokens->deleteRevoked((int) $id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'api_token.delete', 'ApiToken', (int) $id);
            return $this->apiSuccess(null, 'Token deleted.');
        }

        return $this->apiError('Token not found, or it is still active — revoke it first.', null, 404);
    }

    /** GET /api/v1/admin/mobile-tokens/{id} — token info + full usage summary (users / IPs / devices / endpoints / daily trend). */
    public function show(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $token = $this->tokens->findWithCreator((int) $id);
        if (!$token) {
            return $this->apiError('Token not found.', null, 404);
        }

        $summary = $this->tokens->usageSummary((int) $id);
        $blocked = $this->blockedDevices->idsFor(array_column($summary['devices'], 'dev'));
        $summary['devices'] = array_map(function ($d) use ($blocked) {
            $bid = !empty($d['dev']) ? ($blocked[$d['dev']] ?? null) : null;
            return $d + ['blocked' => $bid !== null, 'block_id' => $bid];
        }, $summary['devices']);

        return $this->apiSuccess([
            'token'   => $token,
            'summary' => $summary,
        ], 'Token details retrieved successfully.');
    }

    /** GET /api/v1/admin/mobile-tokens/{id}/requests?page=&per_page=&status=ok|error|rejected|client&kind=&status_code=&endpoint=&method=&device=&user_id=&ip=&q= */
    public function requests(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        if (!$this->tokens->findWithCreator((int) $id)) {
            return $this->apiError('Token not found.', null, 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(10, (int) $request->query('per_page', 50)));
        $res = $this->tokens->usageLog((int) $id, $page, $per, [
            'status'  => (string) $request->query('status', ''),
            'status_code' => (int) $request->query('status_code', 0),
            'endpoint'    => (string) $request->query('endpoint', ''),
            'method'      => (string) $request->query('method', ''),
            'device'      => (string) $request->query('device', ''),
            'user_id' => $request->query('user_id'),
            'ip'      => (string) $request->query('ip', ''),
            'q'       => (string) $request->query('q', ''),
            'kind'    => preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->query('kind', ''))),
        ]);

        return $this->apiSuccess($res['rows'], 'Token requests retrieved successfully.', 200, [
            'page' => $page, 'per_page' => $per, 'total' => $res['total'], 'pages' => (int) max(1, ceil($res['total'] / $per)),
        ]);
    }

    /** GET /api/v1/admin/mobile-tokens/{id}/daily?days=7|14|30|60&status=ok|error|rejected&user_id=&device=&endpoint=&ip= — data for the "Requests per day" chart. */
    public function daily(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        if (!$this->tokens->findWithCreator((int) $id)) {
            return $this->apiError('Token not found.', null, 404);
        }

        $retention = max(1, (int) config('site.app_token_log_days', 60));
        $days = min($retention, max(1, (int) $request->query('days', 30)));
        $by = $this->tokens->dailyCounts((int) $id, $days, [
            'status'   => (string) $request->query('status', ''),
            'user_id'  => $request->query('user_id'),
            'device'   => (string) $request->query('device', ''),
            'endpoint' => (string) $request->query('endpoint', ''),
            'ip'       => (string) $request->query('ip', ''),
        ]);

        return $this->apiSuccess(['days' => $days, 'by_day' => $by], 'Daily request counts retrieved successfully.');
    }

    /** GET /api/v1/admin/mobile-tokens/alert-settings — إعدادات تنبيهات الأعطال (push للأدمنز). */
    public function alertSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        return $this->apiSuccess(app(\App\Services\AppFailureAlertService::class)->settings(), 'Alert settings retrieved successfully.');
    }

    /** PUT /api/v1/admin/mobile-tokens/alert-settings — {enabled?, server_errors?, upload_failures?, app_failures?, cooldown_minutes?} */
    public function saveAlertSettings(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $saved = app(\App\Services\AppFailureAlertService::class)->save($request->only(['enabled', 'server_errors', 'upload_failures', 'app_failures', 'cooldown_minutes']));
        return $this->apiSuccess($saved, 'Alert settings saved.');
    }

    /** POST /api/v1/admin/mobile-tokens/{id}/alert-test — يبعت إشعار تجريبي (push) للأدمن اللي ضغط. */
    public function alertTest(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        app(\App\Services\AppFailureAlertService::class)->sendTest((int) $request->attributes->get('uip_user_id'), (int) $id);
        return $this->apiSuccess(null, 'Test alert sent.');
    }

    /** GET /api/v1/admin/mobile-tokens/blocked-devices — every manually blocked device. */
    public function blockedDevices(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        return $this->apiSuccess($this->blockedDevices->all(), 'Blocked devices retrieved successfully.');
    }

    /** POST /api/v1/admin/mobile-tokens/blocked-devices — {device_id, label?, reason?}. Every API call carrying that X-Device-Id gets 403 device_blocked. */
    public function blockDevice(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $deviceId = BlockedDeviceService::normalize((string) $request->input('device_id', ''));
        if ($deviceId === '') {
            return $this->apiError('This device has no device ID, so it cannot be blocked individually. Update the app so it sends one.', null, 422);
        }
        $id = $this->blockedDevices->block($deviceId, (string) $request->input('label', ''), (string) $request->input('reason', ''), $request->attributes->get('uip_user_id'));
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'device.block', 'BlockedDevice', $id, null, ['device_id' => $deviceId, 'reason' => (string) $request->input('reason', '')]);

        return $this->apiSuccess(['id' => $id, 'device_id' => $deviceId], 'Device blocked.');
    }

    /** DELETE /api/v1/admin/mobile-tokens/blocked-devices/{id} — lifts the block. */
    public function unblockDevice(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $deviceId = $this->blockedDevices->unblock((int) $id);
        if ($deviceId === null) {
            return $this->apiError('Blocked device not found.', null, 404);
        }
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'device.unblock', 'BlockedDevice', (int) $id, ['device_id' => $deviceId]);

        return $this->apiSuccess(null, 'Device unblocked.');
    }
}
