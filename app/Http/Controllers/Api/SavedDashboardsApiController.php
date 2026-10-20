<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\SavedDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/SavedDashboardsApiController.php القديمة
 * — بند 24 batch 1 (Saved Dashboards management، enhancement spec section
 * 9). سطح JSON REST واحد /api/v1/data-analysis/dashboards/*: لوحات
 * drag-and-drop مخصصة للمحلل (جدول `saved_dashboards` الحقيقي، migration
 * 042 + is_shared/is_archived عبر migration 080). كل بيانات widget جاية
 * حية عبر SavedDashboardService::hydrate() (مبنية على AnalyticsService)
 * — مفيش أرقام مختلقة، نفس مصدر الحقيقة الوحيد بتاع Data Analysis
 * Dashboard الرئيسي.
 *
 *   - index()       — لوحات المستخدم + أرشيفه + لوحات شاركها غيره
 *   - catalog()      — كتالوج أنواع الـ widgets/القوالب الجاهزة اللي
 *                      React builder بيعرضه (SavedDashboardService::WIDGETS/
 *                      TEMPLATES)، عشان الفرونت ميهاردكودهاش
 *   - show()         — لوحة واحدة، محقونة ببيانات widgets حية
 *   - store()         — إنشاء
 *   - update()        — تعديل الاسم/التخطيط/is_default
 *   - destroy()        — حذف
 *   - duplicate()      — نسخ تحت اسم جديد
 *   - toggleShare()    — مشاركة/إلغاء مشاركة مع محللين تانيين
 *   - archive()/unarchive()
 *   - export()         — نفس تصدير الـ JSON config اللي النسخة القديمة
 *                        كانت بترجعه، كـ API response بدل تحميل مباشر
 *
 * RBAC: uip.auth middleware بيغطي الجروب؛ كل ميثود كمان بتتأكد إن
 * الكولر data_analyst أو admin — نفس قاعدة DataAnalysisDashboardApiController
 * بالظبط. الملكية/الرؤية (لوحاته/لوحات شاركها غيره/المؤرشف) دايمًا بتتحل
 * من $request->attributes->get('uip_user_id') — أبدًا مش من قيمة جاية من
 * العميل.
 */
class SavedDashboardsApiController extends Controller
{
    public function __construct(
        private SavedDashboardService $dashboards,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/dashboards */
    public function index(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'dashboards' => $this->dashboards->listFor($userId),
            'archived'   => $this->dashboards->archivedFor($userId),
            'shared'     => $this->dashboards->sharedByOthers($userId),
        ], 'Saved dashboards retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/dashboards/catalog — كتالوج أنواع الـ widgets + القوالب الجاهزة للـ builder. */
    public function catalog(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view the widget catalog.', null, 403);
        }

        return $this->apiSuccess([
            'widgets'   => SavedDashboardService::WIDGETS,
            'templates' => SavedDashboardService::TEMPLATES,
        ], 'Widget catalog retrieved successfully.');
    }

    /** GET /api/v1/data-analysis/dashboards/{id} — محقونة ببيانات widgets حية. */
    public function show(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view saved dashboards.', null, 403);
        }

        $dashboard = $this->dashboards->find((int) $id);
        $userId = $request->attributes->get('uip_user_id');

        if (!$dashboard || !$this->dashboards->canView($dashboard, $userId)) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        return $this->apiSuccess([
            'dashboard' => $dashboard,
            'widgets'   => $this->dashboards->hydrate($dashboard['layout']),
            'is_owner'  => (string) $dashboard['user_id'] === (string) $userId,
        ], 'Dashboard retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/dashboards */
    public function store(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can create saved dashboards.', null, 403);
        }

        $validator = Validator::make($request->all(), ['name' => 'required']);
        if ($validator->fails()) {
            return $this->apiError('The given data was invalid.', $validator->errors()->toArray(), 422);
        }

        $layout = $this->decodeLayout($request->input('layout', []));
        if (empty($layout)) {
            return $this->apiError('Add at least one widget before saving.', ['layout' => ['At least one valid widget is required.']], 422);
        }

        $userId = $request->attributes->get('uip_user_id');

        $id = $this->dashboards->create(
            $userId,
            (string) $request->input('name'),
            $layout,
            (bool) $request->input('is_default', false),
            (bool) $request->input('is_shared', false)
        );

        $this->auditLog->record($userId, 'saved_dashboard.create', 'saved_dashboard', $id, null, ['name' => $request->input('name'), 'widgets' => count($layout)]);

        return $this->apiSuccess($this->dashboards->find($id), 'Dashboard saved successfully.', 201);
    }

    /** PUT/PATCH /api/v1/data-analysis/dashboards/{id} */
    public function update(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can update saved dashboards.', null, 403);
        }

        $validator = Validator::make($request->all(), ['name' => 'required']);
        if ($validator->fails()) {
            return $this->apiError('The given data was invalid.', $validator->errors()->toArray(), 422);
        }

        $layout = $this->decodeLayout($request->input('layout', []));
        if (empty($layout)) {
            return $this->apiError('Add at least one widget before saving.', ['layout' => ['At least one valid widget is required.']], 422);
        }

        $userId = $request->attributes->get('uip_user_id');
        $ok = $this->dashboards->update((int) $id, $userId, (string) $request->input('name'), $layout, (bool) $request->input('is_default', false));

        if (!$ok) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.update', 'saved_dashboard', (int) $id, null, ['name' => $request->input('name'), 'widgets' => count($layout)]);

        return $this->apiSuccess($this->dashboards->find((int) $id), 'Dashboard updated successfully.');
    }

    /** DELETE /api/v1/data-analysis/dashboards/{id} */
    public function destroy(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        if (!$this->dashboards->delete((int) $id, $userId)) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.delete', 'saved_dashboard', (int) $id, null, null);

        return $this->apiSuccess(null, 'Dashboard deleted successfully.');
    }

    /** POST /api/v1/data-analysis/dashboards/{id}/duplicate */
    public function duplicate(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can duplicate saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $source = $this->dashboards->find((int) $id);
        $name = trim((string) $request->input('name', ''));
        $newName = $name !== '' ? $name : (($source['name'] ?? 'Dashboard') . ' (copy)');

        $newId = $this->dashboards->duplicate((int) $id, $userId, $newName);

        if (!$newId) {
            return $this->apiError('Could not duplicate that dashboard.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.duplicate', 'saved_dashboard', $newId, null, ['from' => (int) $id]);

        return $this->apiSuccess($this->dashboards->find($newId), 'Dashboard duplicated successfully.', 201);
    }

    /** POST /api/v1/data-analysis/dashboards/{id}/share — بتقلب is_shared. */
    public function toggleShare(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can share saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $result = $this->dashboards->toggleShare((int) $id, $userId);

        if ($result === null) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.share_toggle', 'saved_dashboard', (int) $id, null, ['is_shared' => $result]);

        return $this->apiSuccess(['is_shared' => $result], $result ? 'Dashboard shared with other analysts.' : 'Dashboard is now private.');
    }

    /** POST /api/v1/data-analysis/dashboards/{id}/archive */
    public function archive(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can archive saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        if (!$this->dashboards->archive((int) $id, $userId)) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.archive', 'saved_dashboard', (int) $id, null, ['is_archived' => true]);

        return $this->apiSuccess(null, 'Dashboard archived successfully.');
    }

    /** POST /api/v1/data-analysis/dashboards/{id}/unarchive */
    public function unarchive(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can restore saved dashboards.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        if (!$this->dashboards->unarchive((int) $id, $userId)) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.unarchive', 'saved_dashboard', (int) $id, null, ['is_archived' => false]);

        return $this->apiSuccess(null, 'Dashboard restored successfully.');
    }

    /** GET /api/v1/data-analysis/dashboards/{id}/export — تصدير config الـ widgets/layout الحقيقي، كـ JSON بدل تحميل مباشر. */
    public function export(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can export saved dashboards.', null, 403);
        }

        $dashboard = $this->dashboards->find((int) $id);
        $userId = $request->attributes->get('uip_user_id');

        if (!$dashboard || !$this->dashboards->canView($dashboard, $userId)) {
            return $this->apiError('Dashboard not found.', null, 404);
        }

        $this->auditLog->record($userId, 'saved_dashboard.export', 'saved_dashboard', (int) $id, null, null);

        return $this->apiSuccess([
            'name'        => $dashboard['name'],
            'exported_at' => date('c'),
            'widgets'     => $dashboard['layout'],
        ], 'Dashboard exported successfully.');
    }

    /** بيقبل إما مصفوفة متفكوكة (JSON body) أو نص JSON (form body) — نفس مرونة الكنترولر القديم. */
    private function decodeLayout($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
