<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\PortalGuideAdminService;
use App\Services\PortalGuideService;
use App\Support\PortalGuideException;
use Illuminate\Http\Request;

/**
 * تعديل صفحات الدليل — /api/v1/admin/guides/* (تحت uip.auth + uip.can:guides.manage؛ الأدمن بيعدّي دايمًا).
 * كل تعديل بيزوّد version الدليل، بيتسجّل في portal_guide_revisions، وبيتسجّل audit.
 * القراءة لوضع التعديل: GET /api/v1/guides/{portal}?include_hidden=1.
 */
class AdminPortalGuideApiController extends Controller
{
    public function __construct(
        private PortalGuideAdminService $admin,
        private PortalGuideService $reader,
        private AuditLogService $audit
    ) {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function run(Request $r, string $auditAction, string $subject, mixed $subjectId, callable $fn, string $message, int $status = 200)
    {
        try {
            $result = $fn();
            $this->audit->record($this->uid($r), $auditAction, $subject, $subjectId, null, null, $r->ip());
            return $this->apiSuccess($result, $message, $status);
        } catch (PortalGuideException $e) {
            return $this->apiError($e->getMessage(), $e->errors, $e->status);
        }
    }

    /** PUT /admin/guides/portal/{portal} */
    public function updateMeta(Request $r, string $portal)
    {
        return $this->run($r, 'portal_guide.updated', 'portal_guide', $portal,
            fn () => $this->reader->meta($this->admin->updateMeta($portal, $r->all(), $this->uid($r))),
            'Guide updated successfully.');
    }

    /** POST /admin/guides/portal/{portal}/sections */
    public function createSection(Request $r, string $portal)
    {
        return $this->run($r, 'portal_guide.section_created', 'portal_guide', $portal,
            fn () => $this->reader->sectionArray($this->admin->createSection($portal, $r->all(), $this->uid($r))),
            'Section created successfully.', 201);
    }

    /** POST /admin/guides/portal/{portal}/sections/reorder — body: ids[] */
    public function reorderSections(Request $r, string $portal)
    {
        return $this->run($r, 'portal_guide.sections_reordered', 'portal_guide', $portal,
            function () use ($r, $portal) {
                $this->admin->reorderSections($portal, (array) $r->input('ids', []), $this->uid($r));
                return null;
            }, 'Sections reordered successfully.');
    }

    /** PUT /admin/guides/sections/{id} */
    public function updateSection(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.section_updated', 'portal_guide_section', (int) $id,
            fn () => $this->reader->sectionArray($this->admin->updateSection((int) $id, $r->all(), $this->uid($r))),
            'Section updated successfully.');
    }

    /** DELETE /admin/guides/sections/{id} */
    public function deleteSection(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.section_deleted', 'portal_guide_section', (int) $id,
            function () use ($r, $id) {
                $this->admin->deleteSection((int) $id, $this->uid($r));
                return null;
            }, 'Section deleted successfully.');
    }

    /** POST /admin/guides/sections/{id}/items */
    public function createItem(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.item_created', 'portal_guide_section', (int) $id,
            fn () => $this->reader->itemArray($this->admin->createItem((int) $id, $r->all(), $this->uid($r))),
            'Item created successfully.', 201);
    }

    /** POST /admin/guides/sections/{id}/items/reorder — body: ids[] */
    public function reorderItems(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.items_reordered', 'portal_guide_section', (int) $id,
            function () use ($r, $id) {
                $this->admin->reorderItems((int) $id, (array) $r->input('ids', []), $this->uid($r));
                return null;
            }, 'Items reordered successfully.');
    }

    /** PUT /admin/guides/items/{id} — ممكن تبعت section_id عشان تنقل العنصر لقسم تاني في نفس الدليل */
    public function updateItem(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.item_updated', 'portal_guide_item', (int) $id,
            fn () => $this->reader->itemArray($this->admin->updateItem((int) $id, $r->all(), $this->uid($r))),
            'Item updated successfully.');
    }

    /** DELETE /admin/guides/items/{id} */
    public function deleteItem(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.item_deleted', 'portal_guide_item', (int) $id,
            function () use ($r, $id) {
                $this->admin->deleteItem((int) $id, $this->uid($r));
                return null;
            }, 'Item deleted successfully.');
    }

    /** GET /admin/guides/portal/{portal}/revisions?limit= */
    public function revisions(Request $r, string $portal)
    {
        try {
            return $this->apiSuccess($this->admin->revisions($portal, (int) $r->query('limit', 50)), 'Revisions retrieved successfully.');
        } catch (PortalGuideException $e) {
            return $this->apiError($e->getMessage(), $e->errors, $e->status);
        }
    }

    /** POST /admin/guides/revisions/{id}/restore */
    public function restore(Request $r, $id)
    {
        return $this->run($r, 'portal_guide.restored', 'portal_guide_revision', (int) $id,
            fn () => $this->admin->restore((int) $id, $this->uid($r)),
            'Revision restored successfully.');
    }

    /** POST /admin/guides/portal/{portal}/reset — يرجّع المحتوى الافتراضي (مبيمسحش اللي الأدمن أضافه) */
    public function reset(Request $r, string $portal)
    {
        return $this->run($r, 'portal_guide.reset', 'portal_guide', $portal,
            fn () => $this->admin->reset($portal, $this->uid($r)),
            'Guide reset to the default content.');
    }
}
