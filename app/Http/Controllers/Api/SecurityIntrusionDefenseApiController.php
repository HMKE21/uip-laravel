<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityDetectionService;
use App\Services\SecurityIntrusionDefenseService;
use Illuminate\Http\Request;

/**
 * GET /api/v1/security/intrusion-defense/summary?window=1h|6h|24h|7d — صفحة Intrusion Defense (بند 3). قراءة بس لأي موظف أمن.
 * الإجراءات (فك حظر IP) بتروح للـ endpoints الموجودة (/security/detection/blocks/{id}/unblock) وبصلاحياتها.
 */
class SecurityIntrusionDefenseApiController extends Controller
{
    public function __construct(private SecurityIntrusionDefenseService $service, private SecurityDetectionService $detection)
    {
    }

    public function summary(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        if (!in_array($role, ['security_admin', 'security_officer', 'admin'], true)) {
            return $this->apiError('Only Security Portal staff can view Intrusion Defense.', null, 403);
        }
        if (!$this->detection->available()) {
            return $this->apiError('Detection tables are missing. Run the database migrations.', null, 503);
        }

        $window = (string) $request->query('window', '1h');
        $data = $this->service->summary($window);

        return $this->apiSuccess($data, 'Intrusion defense summary retrieved successfully.', 200, [
            'window'   => $data['window'],
            'can_edit' => in_array($role, ['security_admin', 'admin'], true),
        ]);
    }
}
