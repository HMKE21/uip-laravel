<?php

namespace App\Services;

use App\Models\PortalGuide;
use App\Models\PortalGuideItem;
use App\Models\PortalGuideSection;
use App\Models\PortalGuideView;
use App\Support\PortalGuideException;

/**
 * قراءة صفحات الدليل (للكل) — GET /api/v1/guides/*.
 *
 *  - كل مستخدم بيشوف دليل بوابته بس (حسب دوره). اللي معاه guides.manage (الأدمن دايمًا) بيشوف أي بوابة،
 *    وممكن يطلب include_hidden=1 عشان يشوف المخفي (وضع التعديل).
 *  - الأقسام/العناصر المخفية، والموجّهة لدور تاني (audience_roles)، وبوابة غير منشورة: مبتظهرش للمستخدم العادي.
 *  - عنصر مربوط بصلاحية (permission) المستخدم مش معاه => بيظهر بعلامة locked=true.
 */
class PortalGuideService
{
    public function __construct(
        private PortalGuideSeedService $seeds,
        private PermissionService $permissions
    ) {
    }

    public function portalForRole(?string $role): ?string
    {
        return $role ? (config("guides.role_portal.$role") ?: null) : null;
    }

    public function canManage(?int $userId, ?string $role): bool
    {
        return $this->permissions->currentUserCan($userId, $role, (string) config('guides.manage_permission'));
    }

    /** قائمة الأدلة المتاحة للمستخدم (العادي: دليل بوابته، المدير: كل البوابات). */
    public function listFor(int $userId, string $role): array
    {
        $manage = $this->canManage($userId, $role);
        $own = $this->portalForRole($role);
        $portals = $manage ? $this->seeds->portals() : ($own ? [$own] : []);

        $out = [];
        foreach ($portals as $portal) {
            if ($this->seeds->defaults($portal) === null && !PortalGuide::where('portal', $portal)->exists()) {
                continue;
            }
            $guide = $this->seeds->ensureSeeded($portal);
            if (!$manage && !$guide->is_published) {
                continue;
            }
            $seen = PortalGuideView::where('user_id', $userId)->where('guide_id', $guide->id)->value('seen_version');
            $out[] = $this->meta($guide) + [
                'has_new'    => $seen === null ? false : ((int) $seen < $guide->version),
                'is_mine'    => $portal === $own,
                'item_count' => PortalGuideItem::where('guide_id', $guide->id)->where('is_hidden', false)->count(),
            ];
        }
        return $out;
    }

    public function show(string $portal, int $userId, string $role, bool $includeHidden = false): array
    {
        $manage = $this->canManage($userId, $role);
        $own = $this->portalForRole($role);
        $this->authorizePortal($portal, $own, $manage);

        $guide = $this->seeds->ensureSeeded($portal);
        if (!$guide->is_published && !$manage) {
            throw new PortalGuideException('This guide is not published yet.', 404);
        }
        $edit = $manage && $includeHidden;

        $sections = PortalGuideSection::where('guide_id', $guide->id)->orderBy('sort_order')->orderBy('id')->get();
        $items = PortalGuideItem::where('guide_id', $guide->id)->orderBy('sort_order')->orderBy('id')->get()->groupBy('section_id');
        $granted = $manage ? null : array_flip($this->permissions->permissionsForUser($userId));
        $filterRole = (!$edit && $portal === $own) ? $role : null;

        $outSections = [];
        foreach ($sections as $s) {
            if (!$edit && $s->is_hidden) {
                continue;
            }
            if ($filterRole && !$this->visibleTo($s->audience_roles, $filterRole)) {
                continue;
            }
            $outItems = [];
            foreach ($items->get($s->id, collect()) as $it) {
                if (!$edit && $it->is_hidden) {
                    continue;
                }
                if ($filterRole && !$this->visibleTo($it->audience_roles, $filterRole)) {
                    continue;
                }
                $outItems[] = $this->itemArray($it, $granted);
            }
            if (!$edit && $outItems === [] && $s->kind !== 'overview') {
                continue;
            }
            $outSections[] = $this->sectionArray($s) + ['items' => $outItems];
        }

        $seen = PortalGuideView::where('user_id', $userId)->where('guide_id', $guide->id)->value('seen_version');

        return $this->meta($guide) + [
            'can_manage' => $manage,
            'has_new'    => $seen === null ? false : ((int) $seen < $guide->version),
            'seen_version' => $seen === null ? null : (int) $seen,
            'sections'   => $outSections,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $portal, string $q, int $userId, string $role): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $manage = $this->canManage($userId, $role);
        $own = $this->portalForRole($role);
        $this->authorizePortal($portal, $own, $manage);

        $guide = $this->seeds->ensureSeeded($portal);
        if (!$guide->is_published && !$manage) {
            return [];
        }
        $like = '%' . addcslashes(mb_substr($q, 0, 80), '\\%_') . '%';
        $rows = PortalGuideItem::where('guide_id', $guide->id)
            ->where('is_hidden', false)
            ->where(function ($w) use ($like) {
                foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'body_ar', 'body_en'] as $col) {
                    $w->orWhere($col, 'like', $like);
                }
            })
            ->orderBy('sort_order')->limit((int) config('guides.limits.search', 30))->get();

        $sections = PortalGuideSection::where('guide_id', $guide->id)->get()->keyBy('id');
        $granted = $manage ? null : array_flip($this->permissions->permissionsForUser($userId));
        $filterRole = $portal === $own ? $role : null;

        $out = [];
        foreach ($rows as $it) {
            $s = $sections->get($it->section_id);
            if (!$s || $s->is_hidden) {
                continue;
            }
            if ($filterRole && (!$this->visibleTo($s->audience_roles, $filterRole) || !$this->visibleTo($it->audience_roles, $filterRole))) {
                continue;
            }
            $out[] = $this->itemArray($it, $granted) + [
                'section_key'      => $s->key,
                'section_title_ar' => $s->title_ar,
                'section_title_en' => $s->title_en,
            ];
        }
        return $out;
    }

    public function markSeen(string $portal, int $userId, string $role): array
    {
        $own = $this->portalForRole($role);
        $manage = $this->canManage($userId, $role);
        $this->authorizePortal($portal, $own, $manage);

        $guide = $this->seeds->ensureSeeded($portal);
        PortalGuideView::updateOrCreate(
            ['user_id' => $userId, 'guide_id' => $guide->id],
            ['seen_version' => $guide->version, 'seen_at' => now()]
        );
        return ['portal' => $portal, 'seen_version' => $guide->version];
    }

    // -- helpers ----------------------------------------------------------------

    private function authorizePortal(string $portal, ?string $own, bool $manage): void
    {
        if (!in_array($portal, $this->seeds->portals(), true)) {
            throw new PortalGuideException('Guide not found.', 404);
        }
        if (!$manage && $portal !== $own) {
            throw new PortalGuideException('You can only view the guide of your own portal.', 403);
        }
    }

    /** @param string[]|null $audience */
    private function visibleTo(?array $audience, string $role): bool
    {
        return empty($audience) || in_array($role, $audience, true);
    }

    /** @return array<string,mixed> */
    public function meta(PortalGuide $g): array
    {
        return [
            'portal'       => $g->portal,
            'title_ar'     => $g->title_ar,
            'title_en'     => $g->title_en,
            'subtitle_ar'  => $g->subtitle_ar,
            'subtitle_en'  => $g->subtitle_en,
            'intro_ar'     => $g->intro_ar,
            'intro_en'     => $g->intro_en,
            'icon'         => $g->icon,
            'is_published' => $g->is_published,
            'version'      => $g->version,
            'updated_at'   => optional($g->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function sectionArray(PortalGuideSection $s): array
    {
        return [
            'id' => $s->id, 'key' => $s->key, 'kind' => $s->kind,
            'title_ar' => $s->title_ar, 'title_en' => $s->title_en,
            'summary_ar' => $s->summary_ar, 'summary_en' => $s->summary_en,
            'icon' => $s->icon, 'audience_roles' => $s->audience_roles ?? [],
            'sort_order' => $s->sort_order, 'is_hidden' => $s->is_hidden, 'is_custom' => $s->is_custom,
        ];
    }

    /**
     * @param array<string,int>|null $granted  صلاحيات المستخدم (null = مدير، مفيش locked)
     * @return array<string,mixed>
     */
    public function itemArray(PortalGuideItem $it, ?array $granted = null): array
    {
        return [
            'id' => $it->id, 'section_id' => $it->section_id, 'key' => $it->key,
            'title_ar' => $it->title_ar, 'title_en' => $it->title_en,
            'summary_ar' => $it->summary_ar, 'summary_en' => $it->summary_en,
            'body_ar' => $it->body_ar, 'body_en' => $it->body_en,
            'route' => $it->route, 'permission' => $it->permission, 'icon' => $it->icon,
            'steps' => $it->steps ?? [], 'tips' => $it->tips ?? [],
            'audience_roles' => $it->audience_roles ?? [],
            'sort_order' => $it->sort_order, 'is_hidden' => $it->is_hidden, 'is_custom' => $it->is_custom,
            'locked' => $granted !== null && $it->permission && !isset($granted[$it->permission]),
        ];
    }
}
