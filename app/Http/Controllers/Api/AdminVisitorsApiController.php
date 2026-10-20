<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteVisitService;
use Illuminate\Http\Request;

/** GET /api/v1/admin/visitors — الزوار الفريدين الحقيقيين (مش كل page load). */
class AdminVisitorsApiController extends Controller
{
    public function __construct(private SiteVisitService $siteVisits)
    {
    }

    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view visitor statistics.', null, 403);
        }

        return $this->apiSuccess(
            $this->siteVisits->adminVisitorStats((int) $request->query('days', 30)),
            'Visitor statistics retrieved successfully.'
        );
    }
}
