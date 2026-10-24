<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortalGuideService;
use App\Support\PortalGuideException;
use Illuminate\Http\Request;

/**
 * صفحات الدليل — قراءة (كل المستخدمين المسجّلين) — /api/v1/guides/*.
 *
 *  GET  /guides                  قائمة الأدلة (المستخدم العادي: دليل بوابته بس، المدير: كل البوابات)
 *  GET  /guides/me               دليل بوابة المستخدم الحالي (حسب دوره)
 *  GET  /guides/me/search?q=     بحث داخل دليل بوابتي
 *  POST /guides/me/seen          علّم النسخة الحالية "اتشافت" (يشيل علامة "جديد")
 *  GET  /guides/{portal}         دليل بوابة معيّنة — بوابتي بس، أو أي بوابة لو معايا guides.manage
 *                                (?include_hidden=1 للمدير: يشوف المخفي — وضع التعديل)
 *  GET  /guides/{portal}/search?q=
 *  POST /guides/{portal}/seen
 */
class PortalGuideApiController extends Controller
{
    public function __construct(private PortalGuideService $guides)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function role(Request $r): string
    {
        return (string) $r->attributes->get('uip_role');
    }

    private function fail(PortalGuideException $e)
    {
        return $this->apiError($e->getMessage(), $e->errors, $e->status);
    }

    public function index(Request $r)
    {
        return $this->apiSuccess($this->guides->listFor($this->uid($r), $this->role($r)), 'Guides retrieved successfully.');
    }

    public function me(Request $r)
    {
        $portal = $this->guides->portalForRole($this->role($r));
        if (!$portal) {
            return $this->apiError('No guide is available for your role.', null, 404);
        }
        return $this->show($r, $portal);
    }

    public function show(Request $r, string $portal)
    {
        try {
            $include = filter_var($r->query('include_hidden', false), FILTER_VALIDATE_BOOLEAN);
            return $this->apiSuccess(
                $this->guides->show($portal, $this->uid($r), $this->role($r), $include),
                'Guide retrieved successfully.'
            );
        } catch (PortalGuideException $e) {
            return $this->fail($e);
        }
    }

    public function searchMe(Request $r)
    {
        $portal = $this->guides->portalForRole($this->role($r));
        if (!$portal) {
            return $this->apiError('No guide is available for your role.', null, 404);
        }
        return $this->search($r, $portal);
    }

    public function search(Request $r, string $portal)
    {
        try {
            $rows = $this->guides->search($portal, (string) $r->query('q', ''), $this->uid($r), $this->role($r));
            return $this->apiSuccess($rows, 'Search completed.', 200, ['count' => count($rows)]);
        } catch (PortalGuideException $e) {
            return $this->fail($e);
        }
    }

    public function seenMe(Request $r)
    {
        $portal = $this->guides->portalForRole($this->role($r));
        if (!$portal) {
            return $this->apiError('No guide is available for your role.', null, 404);
        }
        return $this->seen($r, $portal);
    }

    public function seen(Request $r, string $portal)
    {
        try {
            return $this->apiSuccess($this->guides->markSeen($portal, $this->uid($r), $this->role($r)), 'Guide marked as seen.');
        } catch (PortalGuideException $e) {
            return $this->fail($e);
        }
    }
}
