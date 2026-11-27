<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityThreatMonitorService;
use Illuminate\Http\Request;

/**
 * Security Portal — بند 9: لوحة التهديدات الحية. قراءة بس (مفيش كتابة هنا) — الحظر بيتم من
 * POST /security/network/blocks الموجودة (security_admin/admin بس).
 *
 *   GET /security/threats/summary?window=1h|6h|24h|7d  — أرقام + اتجاه زمني + أعلى IPs/حسابات + توزيع الدول
 *   GET /security/threats/feed?since=<epoch>&limit=&kinds=&min_severity=&ip=  — تدفّق الأحداث (polling)
 *   GET /security/threats/geo-resolve                   — حلّ موقع (مدينة + إحداثيات) كام IP ناقصين (ميزانية صغيرة + cooldown)
 *   GET /security/threats/ip?ip=1.2.3.4                 — تفاصيل عنوان واحد: موقع مدمج من عدة مزوّدين + ISP + نوع الاتصال + الجهاز
 */
class SecurityThreatMonitorApiController extends Controller
{
    public function __construct(private SecurityThreatMonitorService $monitor)
    {
    }

    public function summary(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view the threat monitor.', null, 403);
        }
        if (!$this->monitor->available()) {
            return $this->apiError('Threat monitor sources are missing. Run the database migrations.', null, 503);
        }

        $window = (string) $request->query('window', '1h');

        return $this->apiSuccess($this->monitor->summary($window), 'Threat summary retrieved successfully.', 200, [
            'can_block' => $this->canBlock($request),
        ]);
    }

    public function feed(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view the threat monitor.', null, 403);
        }
        if (!$this->monitor->available()) {
            return $this->apiError('Threat monitor sources are missing. Run the database migrations.', null, 503);
        }

        $since = $request->query('since');
        $kinds = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('kinds', '')))));

        $data = $this->monitor->feed(
            is_numeric($since) ? (int) $since : null,
            (int) $request->query('limit', 60),
            [
                'kinds'        => $kinds,
                'min_severity' => (string) $request->query('min_severity', ''),
                'ip'           => (string) $request->query('ip', ''),
            ],
            (int) $request->query('minutes', 60)
        );

        return $this->apiSuccess($data, 'Threat feed retrieved successfully.');
    }

    public function geoResolve(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view the threat monitor.', null, 403);
        }

        return $this->apiSuccess($this->monitor->resolvePending(3), 'Geo lookup finished.');
    }

    public function ip(Request $request)
    {
        if (!$this->isStaff($request)) {
            return $this->apiError('Only Security Portal staff can view the threat monitor.', null, 403);
        }

        try {
            return $this->apiSuccess($this->monitor->ipDetail((string) $request->query('ip', '')), 'IP details retrieved successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    private function isStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function canBlock(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true);
    }
}
