<?php

namespace App\Services;

use App\Models\PortalGuide;
use App\Models\PortalGuideItem;
use App\Models\PortalGuideRevision;
use App\Models\PortalGuideSection;
use App\Support\PortalGuideContent as C;
use App\Support\PortalGuideException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * المحتوى الافتراضي لصفحات الدليل: ملفات PHP في database/data/guides/<portal>.php (الشكل في GUIDE_FORMAT.md).
 *
 *  - ensureSeeded($portal): أول قراءة لدليل بوابة مفيهوش صف => بيتزرع تلقائيًا (من غير أي أمر deploy).
 *  - seedPortal($portal): "sync" — بيضيف اللي ناقص بس (قسم/عنصر جديد في المحتوى الافتراضي)؛ مبيلمسش
 *    تعديلات الأدمن ولا اللي الأدمن مسحه (deleted_keys).
 *  - seedPortal($portal, force: true): "reset" — بيرجّع نص المحتوى الافتراضي كله ويرجّع المحذوف والمخفي،
 *    لكن مبيمسحش اللي الأدمن أضافه بنفسه (is_custom).
 */
class PortalGuideSeedService
{
    /** @var array<string,array<string,mixed>|null> */
    private static array $cache = [];

    /** @return string[] */
    public function portals(): array
    {
        return array_values((array) config('guides.portals', []));
    }

    /** @return array<string,mixed>|null */
    public function defaults(string $portal): ?array
    {
        if (!in_array($portal, $this->portals(), true)) {
            return null;
        }
        if (!array_key_exists($portal, self::$cache)) {
            $path = database_path("data/guides/{$portal}.php");
            self::$cache[$portal] = is_file($path) ? (array) require $path : null;
        }
        return self::$cache[$portal];
    }

    public function ensureSeeded(string $portal): PortalGuide
    {
        $guide = PortalGuide::where('portal', $portal)->first();
        if ($guide) {
            return $guide;
        }
        try {
            $this->seedPortal($portal);
        } catch (QueryException $e) {
            // ريكوستين فتحوا الدليل لأول مرة في نفس اللحظة — التاني هيلاقيه جاهز.
        }
        return PortalGuide::where('portal', $portal)->firstOrFail();
    }

    /** @return array{created:bool,added_sections:int,added_items:int,updated:int} */
    public function seedPortal(string $portal, bool $force = false, ?int $userId = null): array
    {
        $def = $this->defaults($portal);
        if ($def === null) {
            throw new PortalGuideException('No default guide content for this portal.', 404);
        }

        return DB::transaction(function () use ($portal, $def, $force, $userId) {
            $stats = ['created' => false, 'added_sections' => 0, 'added_items' => 0, 'updated' => 0];
            $guide = PortalGuide::where('portal', $portal)->lockForUpdate()->first();

            $meta = [
                'title_ar'    => C::cleanText($def['title_ar'] ?? $portal),
                'title_en'    => C::cleanText($def['title_en'] ?? $portal),
                'subtitle_ar' => $this->nullable($def['subtitle_ar'] ?? null),
                'subtitle_en' => $this->nullable($def['subtitle_en'] ?? null),
                'intro_ar'    => $this->nullable($def['intro_ar'] ?? null),
                'intro_en'    => $this->nullable($def['intro_en'] ?? null),
                'icon'        => (string) ($def['icon'] ?? 'book'),
            ];

            if (!$guide) {
                $guide = PortalGuide::create($meta + ['portal' => $portal, 'is_published' => true, 'version' => 1, 'deleted_keys' => []]);
                $stats['created'] = true;
            } elseif ($force) {
                $guide->fill($meta);
                $guide->deleted_keys = [];
                $guide->save();
                $stats['updated']++;
            }

            $deleted = array_flip((array) ($guide->deleted_keys ?? []));
            $existingSections = PortalGuideSection::where('guide_id', $guide->id)->get()->keyBy('key');
            $existingItemKeys = PortalGuideItem::where('guide_id', $guide->id)->pluck('id', 'key')->all();

            foreach (array_values((array) ($def['sections'] ?? [])) as $si => $sd) {
                $skey = (string) ($sd['key'] ?? '');
                if ($skey === '') {
                    continue;
                }
                $section = $existingSections->get($skey);
                $sdata = [
                    'kind'           => (string) ($sd['kind'] ?? 'group'),
                    'title_ar'       => C::cleanText($sd['title_ar'] ?? $skey),
                    'title_en'       => C::cleanText($sd['title_en'] ?? $skey),
                    'summary_ar'     => $this->nullable($sd['summary_ar'] ?? null),
                    'summary_en'     => $this->nullable($sd['summary_en'] ?? null),
                    'icon'           => $sd['icon'] ?? null,
                    'audience_roles' => $sd['audience_roles'] ?? null,
                ];
                if (!$section) {
                    if (isset($deleted["section:$skey"])) {
                        continue;
                    }
                    $section = PortalGuideSection::create($sdata + [
                        'guide_id' => $guide->id, 'key' => $skey, 'sort_order' => ($si + 1) * 10, 'is_custom' => false,
                    ]);
                    $existingSections->put($skey, $section);
                    $stats['added_sections']++;
                } elseif ($force && !$section->is_custom) {
                    $section->fill($sdata + ['is_hidden' => false, 'sort_order' => ($si + 1) * 10])->save();
                    $stats['updated']++;
                }

                foreach (array_values((array) ($sd['items'] ?? [])) as $ii => $id) {
                    $ikey = (string) ($id['key'] ?? '');
                    if ($ikey === '') {
                        continue;
                    }
                    $idata = [
                        'title_ar'       => C::cleanText($id['title_ar'] ?? $ikey),
                        'title_en'       => C::cleanText($id['title_en'] ?? $ikey),
                        'summary_ar'     => $this->nullable($id['summary_ar'] ?? null),
                        'summary_en'     => $this->nullable($id['summary_en'] ?? null),
                        'body_ar'        => $this->nullable($id['body_ar'] ?? null),
                        'body_en'        => $this->nullable($id['body_en'] ?? null),
                        'route'          => $id['route'] ?? null,
                        'permission'     => $id['permission'] ?? null,
                        'icon'           => $id['icon'] ?? null,
                        'steps'          => $this->pairs($id['steps'] ?? null),
                        'tips'           => $this->pairs($id['tips'] ?? null),
                        'audience_roles' => $id['audience_roles'] ?? null,
                    ];
                    if (!isset($existingItemKeys[$ikey])) {
                        if (isset($deleted["item:$ikey"])) {
                            continue;
                        }
                        $row = PortalGuideItem::create($idata + [
                            'guide_id' => $guide->id, 'section_id' => $section->id, 'key' => $ikey,
                            'sort_order' => ($ii + 1) * 10, 'is_custom' => false,
                        ]);
                        $existingItemKeys[$ikey] = $row->id;
                        $stats['added_items']++;
                    } elseif ($force) {
                        $row = PortalGuideItem::find($existingItemKeys[$ikey]);
                        if ($row && !$row->is_custom) {
                            $row->fill($idata + ['section_id' => $section->id, 'is_hidden' => false, 'sort_order' => ($ii + 1) * 10])->save();
                            $stats['updated']++;
                        }
                    }
                }
            }

            $changed = $stats['added_sections'] + $stats['added_items'] + $stats['updated'];
            if (!$stats['created'] && $changed > 0) {
                $guide->version = $guide->version + 1;
                if ($userId) {
                    $guide->updated_by = $userId;
                }
                $guide->save();
                PortalGuideRevision::create([
                    'guide_id'  => $guide->id,
                    'entity'    => 'guide',
                    'entity_id' => $guide->id,
                    'action'    => $force ? 'reset' : 'sync',
                    'label'     => $force ? 'Reset to default content' : 'Synced new default content',
                    'after'     => $stats,
                    'user_id'   => $userId,
                ]);
            }

            return $stats;
        });
    }

    private function nullable(mixed $v): ?string
    {
        $s = C::cleanText($v);
        return $s === '' ? null : $s;
    }

    /** @return array<int,array{ar:string,en:string}>|null */
    private function pairs(mixed $v): ?array
    {
        $out = C::pairs($v, 50, 1000);
        return $out ? $out : null;
    }
}
