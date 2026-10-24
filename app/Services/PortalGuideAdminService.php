<?php

namespace App\Services;

use App\Models\PortalGuide;
use App\Models\PortalGuideItem;
use App\Models\PortalGuideRevision;
use App\Models\PortalGuideSection;
use App\Support\PortalGuideContent as C;
use App\Support\PortalGuideException;
use Illuminate\Support\Facades\DB;

/**
 * تعديل صفحات الدليل (للأدمن / اللي معاه guides.manage) — /api/v1/admin/guides/*.
 *
 * كل تعديل: بيزوّد guide.version (فيظهر "جديد" للمستخدمين) وبيسجّل revision (قبل/بعد) تقدر ترجع منه.
 * مسح عنصر/قسم افتراضي بيتسجل في guide.deleted_keys عشان الـ sync ميرجّعوش لوحده (الـ reset بيرجّعه صراحةً).
 */
class PortalGuideAdminService
{
    private const KEEP_REVISIONS = 500;

    private const GUIDE_FIELDS   = ['title_ar', 'title_en', 'subtitle_ar', 'subtitle_en', 'intro_ar', 'intro_en', 'icon', 'is_published'];
    private const SECTION_FIELDS = ['kind', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'icon', 'audience_roles', 'sort_order', 'is_hidden'];
    private const ITEM_FIELDS    = ['section_id', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'body_ar', 'body_en', 'route', 'permission', 'icon', 'steps', 'tips', 'audience_roles', 'sort_order', 'is_hidden'];

    public function __construct(
        private PortalGuideSeedService $seeds,
        private PortalGuideService $reader
    ) {
    }

    // -- guide meta -------------------------------------------------------------

    /** @param array<string,mixed> $input */
    public function updateMeta(string $portal, array $input, int $userId): PortalGuide
    {
        $guide = $this->guide($portal);
        [$data, $errors] = C::guideMeta($input, true);
        $this->failIfErrors($errors);
        if ($data === []) {
            throw new PortalGuideException('Nothing to update.', 422);
        }
        return DB::transaction(function () use ($guide, $data, $userId) {
            $before = $this->pick($guide->attributesToArray(), self::GUIDE_FIELDS);
            $guide->fill($data)->save();
            $after = $this->pick($guide->attributesToArray(), self::GUIDE_FIELDS);
            $this->commit($guide, 'guide', $guide->id, 'update', 'Guide details', $before, $after, $userId);
            return $guide->refresh();
        });
    }

    // -- sections ---------------------------------------------------------------

    /** @param array<string,mixed> $input */
    public function createSection(string $portal, array $input, int $userId): PortalGuideSection
    {
        $guide = $this->guide($portal);
        [$data, $errors] = C::section($input, false);
        $this->failIfErrors($errors);
        if (PortalGuideSection::where('guide_id', $guide->id)->where('key', $data['key'])->exists()) {
            throw new PortalGuideException('A section with this key already exists.', 409, ['key' => 'already exists']);
        }
        return DB::transaction(function () use ($guide, $data, $userId) {
            $data['sort_order'] ??= ((int) PortalGuideSection::where('guide_id', $guide->id)->max('sort_order')) + 10;
            $section = PortalGuideSection::create($data + ['guide_id' => $guide->id, 'is_custom' => true, 'updated_by' => $userId]);
            $this->commit($guide, 'section', $section->id, 'create', $section->title_en, null, $section->attributesToArray(), $userId);
            return $section;
        });
    }

    /** @param array<string,mixed> $input */
    public function updateSection(int $id, array $input, int $userId): PortalGuideSection
    {
        $section = $this->section($id);
        [$data, $errors] = C::section($input, true);
        unset($data['key']); // مفتاح القسم ثابت
        $this->failIfErrors($errors);
        if ($data === []) {
            throw new PortalGuideException('Nothing to update.', 422);
        }
        return DB::transaction(function () use ($section, $data, $userId) {
            $before = $this->pick($section->attributesToArray(), self::SECTION_FIELDS);
            $section->fill($data + ['updated_by' => $userId])->save();
            $after = $this->pick($section->attributesToArray(), self::SECTION_FIELDS);
            $this->commit($this->guideById($section->guide_id), 'section', $section->id, 'update', $section->title_en, $before, $after, $userId);
            return $section->refresh();
        });
    }

    public function deleteSection(int $id, int $userId): void
    {
        $section = $this->section($id);
        DB::transaction(function () use ($section, $userId) {
            $guide = $this->guideById($section->guide_id);
            $items = PortalGuideItem::where('section_id', $section->id)->get();
            $before = $section->attributesToArray() + ['items' => $items->map(fn ($i) => $i->attributesToArray())->all()];

            if (!$section->is_custom) {
                $keys = ['section:' . $section->key];
                foreach ($items as $i) {
                    if (!$i->is_custom) {
                        $keys[] = 'item:' . $i->key;
                    }
                }
                $this->addDeleted($guide, $keys);
            }
            $section->delete(); // العناصر بتتمسح بالـ cascade
            $this->commit($guide, 'section', $before['id'], 'delete', $before['title_en'], $before, null, $userId);
        });
    }

    /** @param int[] $ids */
    public function reorderSections(string $portal, array $ids, int $userId): void
    {
        $guide = $this->guide($portal);
        $ids = $this->cleanIds($ids);
        $owned = PortalGuideSection::where('guide_id', $guide->id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($owned) !== count($ids) || $ids === []) {
            throw new PortalGuideException('Invalid section list.', 422);
        }
        DB::transaction(function () use ($guide, $ids, $userId) {
            foreach ($ids as $i => $sid) {
                PortalGuideSection::where('id', $sid)->update(['sort_order' => ($i + 1) * 10]);
            }
            $this->commit($guide, 'guide', $guide->id, 'update', 'Reordered sections', null, ['section_order' => $ids], $userId);
        });
    }

    // -- items ------------------------------------------------------------------

    /** @param array<string,mixed> $input */
    public function createItem(int $sectionId, array $input, int $userId): PortalGuideItem
    {
        $section = $this->section($sectionId);
        $guide = $this->guideById($section->guide_id);
        [$data, $errors] = C::item($input, false);
        $this->failIfErrors($errors);
        unset($data['section_id']);
        if (PortalGuideItem::where('guide_id', $guide->id)->where('key', $data['key'])->exists()) {
            throw new PortalGuideException('An item with this key already exists in this guide.', 409, ['key' => 'already exists']);
        }
        return DB::transaction(function () use ($guide, $section, $data, $userId) {
            $data['sort_order'] ??= ((int) PortalGuideItem::where('section_id', $section->id)->max('sort_order')) + 10;
            $item = PortalGuideItem::create($data + [
                'guide_id' => $guide->id, 'section_id' => $section->id, 'is_custom' => true, 'updated_by' => $userId,
            ]);
            $this->commit($guide, 'item', $item->id, 'create', $item->title_en, null, $item->attributesToArray(), $userId);
            return $item;
        });
    }

    /** @param array<string,mixed> $input */
    public function updateItem(int $id, array $input, int $userId): PortalGuideItem
    {
        $item = $this->item($id);
        $guide = $this->guideById($item->guide_id);
        [$data, $errors] = C::item($input, true);
        unset($data['key']); // مفتاح العنصر ثابت
        if (array_key_exists('section_id', $input)) {
            $target = PortalGuideSection::where('id', (int) $input['section_id'])->where('guide_id', $guide->id)->first();
            if (!$target) {
                $errors['section_id'] = 'Section not found in this guide.';
            } else {
                $data['section_id'] = $target->id;
            }
        }
        $this->failIfErrors($errors);
        if ($data === []) {
            throw new PortalGuideException('Nothing to update.', 422);
        }
        return DB::transaction(function () use ($guide, $item, $data, $userId) {
            $before = $this->pick($item->attributesToArray(), self::ITEM_FIELDS);
            $item->fill($data + ['updated_by' => $userId])->save();
            $after = $this->pick($item->attributesToArray(), self::ITEM_FIELDS);
            $this->commit($guide, 'item', $item->id, 'update', $item->title_en, $before, $after, $userId);
            return $item->refresh();
        });
    }

    public function deleteItem(int $id, int $userId): void
    {
        $item = $this->item($id);
        DB::transaction(function () use ($item, $userId) {
            $guide = $this->guideById($item->guide_id);
            $before = $item->attributesToArray();
            if (!$item->is_custom) {
                $this->addDeleted($guide, ['item:' . $item->key]);
            }
            $item->delete();
            $this->commit($guide, 'item', $before['id'], 'delete', $before['title_en'], $before, null, $userId);
        });
    }

    /** @param int[] $ids */
    public function reorderItems(int $sectionId, array $ids, int $userId): void
    {
        $section = $this->section($sectionId);
        $guide = $this->guideById($section->guide_id);
        $ids = $this->cleanIds($ids);
        $owned = PortalGuideItem::where('section_id', $section->id)->whereIn('id', $ids)->pluck('id')->all();
        if ($ids === [] || count($owned) !== count($ids)) {
            throw new PortalGuideException('Invalid item list.', 422);
        }
        DB::transaction(function () use ($guide, $section, $ids, $userId) {
            foreach ($ids as $i => $iid) {
                PortalGuideItem::where('id', $iid)->update(['sort_order' => ($i + 1) * 10]);
            }
            $this->commit($guide, 'section', $section->id, 'update', 'Reordered items: ' . $section->title_en, null, ['item_order' => $ids], $userId);
        });
    }

    // -- revisions / restore / reset ---------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function revisions(string $portal, int $limit = 50): array
    {
        $guide = $this->guide($portal);
        $limit = max(1, min((int) config('guides.limits.revisions', 100), $limit));
        $rows = PortalGuideRevision::where('guide_id', $guide->id)->orderByDesc('id')->limit($limit)->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id' => $r->id, 'entity' => $r->entity, 'entity_id' => $r->entity_id, 'action' => $r->action,
            'label' => $r->label, 'before' => $r->before, 'after' => $r->after,
            'user_id' => $r->user_id, 'user_name' => $r->user_id ? ($names[$r->user_id] ?? null) : null,
            'restored_from' => $r->restored_from,
            'restorable' => in_array($r->action, ['create', 'update', 'delete'], true) && ($r->before !== null || $r->action === 'create') && !isset($r->after['section_order']) && !isset($r->after['item_order']),
            'created_at' => $r->created_at ? (string) $r->created_at : null,
        ])->all();
    }

    /** @return array<string,mixed> */
    public function restore(int $revisionId, int $userId): array
    {
        $rev = PortalGuideRevision::find($revisionId);
        if (!$rev) {
            throw new PortalGuideException('Revision not found.', 404);
        }
        if (!in_array($rev->action, ['create', 'update', 'delete'], true) || isset($rev->after['section_order']) || isset($rev->after['item_order'])) {
            throw new PortalGuideException('This revision cannot be restored.', 422);
        }
        $guide = $this->guideById($rev->guide_id);
        $before = (array) ($rev->before ?? []);

        return DB::transaction(function () use ($rev, $guide, $before, $userId) {
            $label = 'Restored: ' . ($rev->label ?? $rev->entity);

            if ($rev->entity === 'guide') {
                $guide->fill($this->pick($before, self::GUIDE_FIELDS))->save();
            } elseif ($rev->entity === 'section') {
                $this->restoreSection($rev, $guide, $before);
            } else {
                $this->restoreItem($rev, $guide, $before);
            }
            $this->commit($guide, $rev->entity, $rev->entity_id, 'restore', $label, null, ['revision' => $rev->id], $userId, $rev->id);
            return ['revision_id' => $rev->id, 'version' => $guide->refresh()->version];
        });
    }

    /** @return array{created:bool,added_sections:int,added_items:int,updated:int} */
    public function reset(string $portal, int $userId): array
    {
        $this->guide($portal);
        return $this->seeds->seedPortal($portal, true, $userId);
    }

    // -- internals --------------------------------------------------------------

    private function restoreSection(PortalGuideRevision $rev, PortalGuide $guide, array $before): void
    {
        $row = PortalGuideSection::find($rev->entity_id);
        if ($rev->action === 'create') {
            if ($row) {
                $row->delete();
            }
            return;
        }
        if ($rev->action === 'update') {
            if (!$row) {
                throw new PortalGuideException('The section no longer exists.', 409);
            }
            $row->fill($this->pick($before, self::SECTION_FIELDS))->save();
            return;
        }
        // delete => رجّع القسم وعناصره
        if ($row) {
            throw new PortalGuideException('The section already exists.', 409);
        }
        if (PortalGuideSection::where('guide_id', $guide->id)->where('key', $before['key'] ?? '')->exists()) {
            throw new PortalGuideException('A section with the same key already exists.', 409);
        }
        $items = (array) ($before['items'] ?? []);
        $fields = $this->pick($before, array_merge(self::SECTION_FIELDS, ['key', 'is_custom']));
        $section = new PortalGuideSection($fields + ['guide_id' => $guide->id]);
        $section->id = (int) $rev->entity_id;
        $section->save();
        $keys = ['section:' . $section->key];
        foreach ($items as $it) {
            if (PortalGuideItem::where('guide_id', $guide->id)->where('key', $it['key'] ?? '')->exists()) {
                continue;
            }
            $copy = new PortalGuideItem($this->pick($it, array_merge(self::ITEM_FIELDS, ['key', 'is_custom'])) + ['guide_id' => $guide->id]);
            $copy->section_id = $section->id;
            if (isset($it['id']) && !PortalGuideItem::whereKey((int) $it['id'])->exists()) {
                $copy->id = (int) $it['id'];
            }
            $copy->save();
            $keys[] = 'item:' . $copy->key;
        }
        $this->removeDeleted($guide, $keys);
    }

    private function restoreItem(PortalGuideRevision $rev, PortalGuide $guide, array $before): void
    {
        $row = PortalGuideItem::find($rev->entity_id);
        if ($rev->action === 'create') {
            if ($row) {
                $row->delete();
            }
            return;
        }
        if ($rev->action === 'update') {
            if (!$row) {
                throw new PortalGuideException('The item no longer exists.', 409);
            }
            $fields = $this->pick($before, self::ITEM_FIELDS);
            if (isset($fields['section_id']) && !PortalGuideSection::where('id', $fields['section_id'])->where('guide_id', $guide->id)->exists()) {
                unset($fields['section_id']);
            }
            $row->fill($fields)->save();
            return;
        }
        if ($row) {
            throw new PortalGuideException('The item already exists.', 409);
        }
        if (PortalGuideItem::where('guide_id', $guide->id)->where('key', $before['key'] ?? '')->exists()) {
            throw new PortalGuideException('An item with the same key already exists.', 409);
        }
        $sectionId = (int) ($before['section_id'] ?? 0);
        if (!PortalGuideSection::where('id', $sectionId)->where('guide_id', $guide->id)->exists()) {
            throw new PortalGuideException('The section of this item no longer exists. Restore the section first.', 409);
        }
        $item = new PortalGuideItem($this->pick($before, array_merge(self::ITEM_FIELDS, ['key', 'is_custom'])) + ['guide_id' => $guide->id]);
        $item->section_id = $sectionId;
        $item->id = (int) $rev->entity_id;
        $item->save();
        $this->removeDeleted($guide, ['item:' . $item->key]);
    }

    private function guide(string $portal): PortalGuide
    {
        if (!in_array($portal, $this->seeds->portals(), true)) {
            throw new PortalGuideException('Guide not found.', 404);
        }
        return $this->seeds->ensureSeeded($portal);
    }

    private function guideById(int $id): PortalGuide
    {
        return PortalGuide::find($id) ?? throw new PortalGuideException('Guide not found.', 404);
    }

    private function section(int $id): PortalGuideSection
    {
        return PortalGuideSection::find($id) ?? throw new PortalGuideException('Section not found.', 404);
    }

    private function item(int $id): PortalGuideItem
    {
        return PortalGuideItem::find($id) ?? throw new PortalGuideException('Item not found.', 404);
    }

    /** @param array<string,string> $errors */
    private function failIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new PortalGuideException('Validation failed.', 422, $errors);
        }
    }

    /** @param mixed[] $ids @return int[] */
    private function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /** @param array<string,mixed> $src @param string[] $fields @return array<string,mixed> */
    private function pick(array $src, array $fields): array
    {
        return array_intersect_key($src, array_flip($fields));
    }

    /** @param string[] $keys */
    private function addDeleted(PortalGuide $guide, array $keys): void
    {
        $guide->deleted_keys = array_values(array_unique(array_merge((array) ($guide->deleted_keys ?? []), $keys)));
        $guide->save();
    }

    /** @param string[] $keys */
    private function removeDeleted(PortalGuide $guide, array $keys): void
    {
        $guide->deleted_keys = array_values(array_diff((array) ($guide->deleted_keys ?? []), $keys));
        $guide->save();
    }

    /** يزوّد version + يسجّل revision + يقلّم الأقدم. */
    private function commit(PortalGuide $guide, string $entity, int $entityId, string $action, ?string $label, ?array $before, ?array $after, int $userId, ?int $restoredFrom = null): void
    {
        $guide->refresh();
        $guide->version = $guide->version + 1;
        $guide->updated_by = $userId;
        $guide->save();

        PortalGuideRevision::create([
            'guide_id' => $guide->id, 'entity' => $entity, 'entity_id' => $entityId, 'action' => $action,
            'label' => $label !== null ? mb_substr($label, 0, 200) : null,
            'before' => $before, 'after' => $after, 'user_id' => $userId, 'restored_from' => $restoredFrom,
        ]);

        $cut = PortalGuideRevision::where('guide_id', $guide->id)->orderByDesc('id')->skip(self::KEEP_REVISIONS)->value('id');
        if ($cut) {
            PortalGuideRevision::where('guide_id', $guide->id)->where('id', '<=', $cut)->delete();
        }
    }
}
