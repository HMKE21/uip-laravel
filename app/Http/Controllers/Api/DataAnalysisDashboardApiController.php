<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataAnalysisDashboardExtrasService;
use App\Services\DataAnalysisDashboardService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisDashboardApiController.php
 * القديمة — سطح /api/v1/data-analysis/dashboard واحد، بند 24 (Data
 * Analysis Portal)، أول endpoint فيه (مدخل البورتال). بيعيد استخدام
 * DataAnalysisDashboardService::overview() بالظبط (نفسها مبنية على
 * AnalyticsService — بند 23، مصدر وحيد للحقيقة) + savedDashboardsFor()/
 * recentExportsFor() لنفس المستخدم. مفيش منطق جديد هنا، بس طبقة JSON.
 *
 * RBAC: data_analyst أو admin بس (الأدمن عنده وصول كامل لكل بورتال —
 * نفس قاعدة كل كنترولرز DataAnalysis* التانية).
 *
 * فرق شكلي فقط عن القديمة: Session::userRole()/userId() ->
 * $request->attributes->get('uip_role')/'uip_user_id').
 */
class DataAnalysisDashboardApiController extends Controller
{
    public function __construct(private DataAnalysisDashboardService $dashboard, private DataAnalysisDashboardExtrasService $extras)
    {
    }

    /** GET /api/v1/data-analysis/dashboard */
    public function index(Request $request)
    {
        if (!in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true)) {
            return $this->apiError('Only Data Analysis Portal accounts can view this dashboard.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        return $this->apiSuccess(array_merge(
            $this->dashboard->overview(),
            [
                'saved_dashboards' => $this->dashboard->savedDashboardsFor($userId),
                'recent_exports'   => array_slice($this->dashboard->recentExportsFor($userId), 0, 5),
            ]
        ), 'Dashboard data retrieved successfully.');
    }

    /**
     * GET /api/v1/data-analysis/dashboard/extras — بند 1 (الناقص للمحلل):
     * المقررات، الحضور، الرسوم، التخرج، النشاط، + kpis المشاريع والامتحانات
     * العميقة. endpoint منفصل عن index() عشان الداشبورد الرئيسي يفتح بسرعة
     * والفرونت يحمّل الأقسام دي بعده. كل قسم null لو فشل (جدول ناقص).
     * نفس قاعدة RBAC بتاعة index().
     */
    public function extras(Request $request)
    {
        if (!in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true)) {
            return $this->apiError('Only Data Analysis Portal accounts can view this dashboard.', null, 403);
        }

        return $this->apiSuccess($this->extras->overview(), 'Dashboard extras retrieved successfully.');
    }
}
