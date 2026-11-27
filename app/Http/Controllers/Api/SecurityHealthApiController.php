<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityDetectionService;
use App\Services\SecurityHealthService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/security/health/summary — صفحة Server Health (بند 2). قراءة بس لأي موظف أمن.
 * القياس للسيرفر كله فمتاح على المنصة الأم بس (مش جوه عميل معزول). الحدود نفسها بتتظبط من Detection Rules.
 */
class SecurityHealthApiController extends Controller
{
    private const WINDOWS = ['1h' => 60, '6h' => 360, '24h' => 1440, '7d' => 10080];

    public function __construct(private SecurityHealthService $health)
    {
    }

    public function summary(Request $request)
    {
        if (!in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true)) {
            return $this->apiError('Only Security Portal staff can view server health.', null, 403);
        }
        if (TenantContext::active()) {
            return $this->apiError('Server health is available on the platform workspace only.', null, 404);
        }
        if (!$this->health->available()) {
            return $this->apiError('Server health tables are missing. Run the database migrations.', null, 503);
        }

        $key = (string) $request->query('window', '1h');
        $data = $this->health->summary(self::WINDOWS[$key] ?? 60);

        $ruleTypes = array_keys(array_filter(SecurityDetectionService::TYPES, fn ($d) => isset($d['health'])));
        $data['rules'] = DB::table('security_detection_rules')->whereIn('rule_type', $ruleTypes)->orderBy('id')
            ->get(['id', 'rule_key', 'rule_type', 'name_en', 'name_ar', 'enabled', 'severity', 'threshold', 'window_minutes', 'trigger_count', 'last_triggered_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'rule_key' => $r->rule_key, 'rule_type' => $r->rule_type, 'name_en' => $r->name_en, 'name_ar' => $r->name_ar,
                'enabled' => (bool) $r->enabled, 'severity' => $r->severity, 'threshold' => (int) $r->threshold,
                'window_minutes' => (int) $r->window_minutes, 'trigger_count' => (int) $r->trigger_count, 'last_triggered_at' => $r->last_triggered_at,
            ])->all();

        $data['alerts'] = DB::table('security_alerts')->where('type', 'like', SecurityHealthService::ALERT_PREFIX . '%')
            ->orderByDesc('id')->limit(10)->get(['id', 'type', 'severity', 'title', 'message', 'status', 'created_at'])->all();

        $canEdit = in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true);

        return $this->apiSuccess($data, 'Server health retrieved successfully.', 200, ['window' => isset(self::WINDOWS[$key]) ? $key : '1h', 'can_edit' => $canEdit]);
    }
}
