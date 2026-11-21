<?php

namespace App\Services\Tenancy;

use App\Services\Developer\DevLogService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * موقع العميل: قوالب جاهزة + قوالبه هو + تخصيص (ألوان/خط/ترتيب الأقسام/نصوص) + معاينة قبل النشر + نشر وrollback.
 *
 *  - المسودة (draft) بتتحفظ تلقائي ومبتظهرش للمستخدم النهائي إلا بتوكن المعاينة.
 *  - publish: بيحفظ نسخة من المنشور الحالي، وبينقل المسودة للمنشور، وبيزامن الألوان مع tenant_branding (عشان الإيميلات تطابق الموقع).
 *  - كل القراءة/الكتابة على قاعدة المنصة الأم (حتى لو الطلب جوه عميل).
 */
final class TenantSiteService
{
    public function __construct(private TenantService $tenants, private DevLogService $logs)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private function db(): ConnectionInterface
    {
        return \Illuminate\Support\Facades\DB::connection(TenantContext::centralConnection());
    }

    private function audit(int $userId, int $tenantId, string $action, ?string $note = null): void
    {
        $this->logs->record(['user_id' => $userId ?: null, 'kind' => 'admin', 'method' => 'TENANT', 'path' => 'tenant/' . $tenantId . '/site/' . $action, 'status' => 200, 'note' => $note]);
    }

    private function decode($json): ?array
    {
        $a = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
        return is_array($a) ? $a : null;
    }

    /** صف الموقع (بيتعمل لو مش موجود). المسودة الافتراضية = قالب classic متطبّق على هوية العميل. */
    private function row(object $tenant): object
    {
        $r = $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
        if ($r) {
            return $r;
        }
        $draft = $this->withBrand($tenant, TenantSiteTemplates::find('classic')['settings']);
        $this->db()->table('tenant_sites')->insert([
            'tenant_id' => (int) $tenant->id, 'template_key' => 'classic', 'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE),
            'draft_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
    }

    /** ألوان هوية العميل (لو مضبوطة) بتغلب ألوان القالب الافتراضية عند تطبيقه. */
    private function withBrand(object $tenant, array $settings): array
    {
        $b = $this->db()->table('tenant_branding')->where('tenant_id', $tenant->id)->first();
        if ($b) {
            foreach (['primary_color' => 'primary', 'accent_color' => 'accent'] as $col => $key) {
                if (!empty($b->{$col})) {
                    $settings['theme'][$key] = strtoupper($b->{$col});
                }
            }
        }
        return TenantSiteSanitizer::full($settings);
    }

    private function draftOf(object $row): array
    {
        return TenantSiteSanitizer::full($this->decode($row->draft) ?? []);
    }

    private function meta(object $row): array
    {
        $draft = $this->decode($row->draft);
        $pub = $this->decode($row->published);
        return [
            'template_key' => $row->template_key, 'version' => (int) $row->version, 'published' => $pub !== null, 'published_at' => $row->published_at,
            'draft_updated_at' => $row->draft_updated_at,
            'has_unpublished_changes' => $pub === null || json_encode($draft) !== json_encode($pub),
        ];
    }

    /* ------------------------------------------------------------------ read */

    /** كل اللي صفحة الـ builder محتاجاه في request واحد. */
    public function overview(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'view');
        $row = $this->row($t);
        $pub = $this->decode($row->published);
        return [
            'site' => $this->meta($row) + ['draft' => $this->draftOf($row), 'published_settings' => $pub ? TenantSiteSanitizer::full($pub) : null],
            'address' => TenantAddress::payload($t),
            'preview' => $this->previewInfo($t, $row),
            'templates' => $this->templateList((int) $t->id),
            'catalog' => $this->catalog(),
        ];
    }

    /** اللي الـ builder يعرضه كخيارات (قيم enum مسموحة). */
    public function catalog(): array
    {
        return [
            'sections' => array_keys(TenantSiteTemplates::sectionSpecs()), 'icons' => TenantSiteTemplates::ICONS, 'fonts' => TenantSiteTemplates::FONTS,
            'radius' => ['none', 'sm', 'md', 'lg', 'xl'], 'modes' => ['light', 'dark', 'auto'], 'density' => ['compact', 'comfortable', 'spacious'],
            'login_styles' => ['split', 'centered', 'card'], 'nav_styles' => ['top', 'side'],
            'limits' => ['sections' => (int) config('tenancy.site.max_sections', 12), 'nav' => (int) config('tenancy.site.max_nav', 8)],
        ];
    }

    private function templateList(int $tenantId): array
    {
        $builtin = array_values(array_map([TenantSiteTemplates::class, 'card'], TenantSiteTemplates::builtin()));
        $custom = $this->db()->table('tenant_site_templates')->where('tenant_id', $tenantId)->orderByDesc('id')->get(['id', 'name', 'settings', 'created_at'])->map(function ($r) {
            $s = $this->decode($r->settings) ?? [];
            return ['id' => (int) $r->id, 'name' => $r->name, 'builtin' => false, 'created_at' => $r->created_at,
                'preview' => ['primary' => $s['theme']['primary'] ?? null, 'accent' => $s['theme']['accent'] ?? null, 'background' => $s['theme']['background'] ?? null]];
        })->all();
        return ['builtin' => $builtin, 'custom' => $custom];
    }

    public function templates(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'view');
        return $this->templateList((int) $t->id);
    }

    /* ------------------------------------------------------------------ draft */

    public function saveDraft(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $draft = TenantSiteSanitizer::settings($in, $this->draftOf($row));
        $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['draft' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'draft_updated_at' => now(), 'updated_at' => now()]);
        return ['draft' => $draft, 'site' => $this->meta($this->row($t))];
    }

    /** يطبّق قالب (جاهز: key — أو بتاع العميل: template_id) على المسودة. المنشور مبيتغيرش لحد ما العميل ينشر. */
    public function applyTemplate(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $this->row($t);
        if (!empty($in['template_id'])) {
            $c = $this->db()->table('tenant_site_templates')->where('tenant_id', $t->id)->where('id', (int) $in['template_id'])->first();
            if (!$c) {
                $this->fail('Template not found.', 404);
            }
            $settings = TenantSiteSanitizer::full($this->decode($c->settings) ?? []);
            $key = 'custom:' . $c->id;
        } else {
            $tpl = TenantSiteTemplates::find((string) ($in['key'] ?? ''));
            if (!$tpl) {
                $this->fail('Template not found.', 404);
            }
            $settings = $this->withBrand($t, $tpl['settings']);
            $key = $tpl['key'];
        }
        $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['draft' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'template_key' => $key, 'draft_updated_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'apply-template', $key);
        return ['draft' => $settings, 'site' => $this->meta($this->row($t))];
    }

    /** يرجّع المسودة لآخر منشور (أو لقالب classic لو لسه مفيش نشر). */
    public function discardDraft(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $pub = $this->decode($row->published);
        $draft = $pub ? TenantSiteSanitizer::full($pub) : $this->withBrand($t, TenantSiteTemplates::find('classic')['settings']);
        $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['draft' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'draft_updated_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'discard');
        return ['draft' => $draft, 'site' => $this->meta($this->row($t))];
    }

    /* ------------------------------------------------------------------ publish / versions */

    public function publish(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $draft = $this->draftOf($row);
        $this->db()->transaction(function () use ($t, $row, $draft, $userId) {
            $old = $this->decode($row->published);
            if ($old !== null) {
                $this->db()->table('tenant_site_versions')->insert([
                    'tenant_id' => (int) $t->id, 'version' => (int) $row->version, 'settings' => json_encode($old, JSON_UNESCAPED_UNICODE),
                    'published_by' => $row->published_by, 'published_at' => $row->published_at, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update([
                'published' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'version' => (int) $row->version + 1, 'published_at' => now(), 'published_by' => $userId, 'updated_at' => now(),
            ]);
            $keep = max(1, (int) config('tenancy.site.keep_versions', 10));
            $ids = $this->db()->table('tenant_site_versions')->where('tenant_id', $t->id)->orderByDesc('id')->skip($keep)->take(1000)->pluck('id')->all();
            if ($ids) {
                $this->db()->table('tenant_site_versions')->whereIn('id', $ids)->delete();
            }
        });
        // الألوان بتتزامن مع الهوية: الإيميلات والـ embed يطابقوا الموقع المنشور.
        $sync = array_filter(['primary_color' => $draft['theme']['primary'] ?? null, 'accent_color' => $draft['theme']['accent'] ?? null]);
        if ($sync) {
            try {
                TenantBranding::update($t, $sync);
            } catch (\Throwable $e) { /* الهوية مش شرط لنجاح النشر */ }
        }
        $this->audit($userId, (int) $t->id, 'publish', 'v' . ((int) $row->version + 1));
        return ['site' => $this->meta($this->row($t)), 'address' => TenantAddress::payload($t)];
    }

    public function versions(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'view');
        return ['versions' => $this->db()->table('tenant_site_versions')->where('tenant_id', $t->id)->orderByDesc('id')->get(['id', 'version', 'published_by', 'published_at'])
            ->map(fn ($v) => ['id' => (int) $v->id, 'version' => (int) $v->version, 'published_by' => $v->published_by ? (int) $v->published_by : null, 'published_at' => $v->published_at])->all()];
    }

    /** يحط نسخة قديمة في المسودة (مش بينشرها): العميل يراجعها في المعاينة وبعدين ينشر. */
    public function restoreVersion(int $userId, int $tenantId, int $versionId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $v = $this->db()->table('tenant_site_versions')->where('tenant_id', $t->id)->where('id', $versionId)->first();
        if (!$v) {
            $this->fail('Version not found.', 404);
        }
        $draft = TenantSiteSanitizer::full($this->decode($v->settings) ?? []);
        $this->row($t);
        $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['draft' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'draft_updated_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'restore', 'v' . $v->version);
        return ['draft' => $draft, 'site' => $this->meta($this->row($t))];
    }

    /* ------------------------------------------------------------------ preview */

    private function previewInfo(object $tenant, object $row): array
    {
        $addr = TenantAddress::url((string) $tenant->slug);
        return ['token' => $row->preview_token, 'url' => ($addr && $row->preview_token) ? $addr . '?preview=' . $row->preview_token : null];
    }

    /** يطلّع (أو يجدّد) توكن المعاينة. اللي معاه الرابط يشوف المسودة بس مايقدرش يعدّل ولا يدخل. */
    public function previewToken(int $userId, int $tenantId, bool $rotate = false): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        if ($rotate || !$row->preview_token) {
            $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['preview_token' => Str::lower(Str::random(40)), 'updated_at' => now()]);
            $row = $this->row($t);
            $this->audit($userId, (int) $t->id, 'preview-token');
        }
        return ['preview' => $this->previewInfo($t, $row)];
    }

    /* ------------------------------------------------------------------ custom templates */

    public function saveTemplate(int $userId, int $tenantId, string $name): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $name = TenantSiteSanitizer::text($name, 60);
        if (mb_strlen($name) < 2) {
            $this->fail('Give the template a name (2 characters at least).');
        }
        if ($this->db()->table('tenant_site_templates')->where('tenant_id', $t->id)->count() >= (int) config('tenancy.site.max_custom_templates', 20)) {
            $this->fail('You reached the maximum number of saved templates. Delete one first.', 403);
        }
        $draft = $this->draftOf($this->row($t));
        $id = $this->db()->table('tenant_site_templates')->insertGetId(['tenant_id' => (int) $t->id, 'name' => $name, 'settings' => json_encode($draft, JSON_UNESCAPED_UNICODE),
            'created_by' => $userId ?: null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'save-template', $name);
        return ['id' => $id, 'templates' => $this->templateList((int) $t->id)];
    }

    public function deleteTemplate(int $userId, int $tenantId, int $templateId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $n = $this->db()->table('tenant_site_templates')->where('tenant_id', $t->id)->where('id', $templateId)->delete();
        if (!$n) {
            $this->fail('Template not found.', 404);
        }
        $this->audit($userId, (int) $t->id, 'delete-template', (string) $templateId);
        return ['templates' => $this->templateList((int) $t->id)];
    }

    /* ------------------------------------------------------------------ address */

    public function setSlug(int $userId, int $tenantId, string $slug): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $new = TenantAddress::normalize($slug);
        if ($new === $t->slug) {
            return ['address' => TenantAddress::payload($t)];
        }
        if ($this->db()->table('tenants')->where('slug', $new)->where('id', '!=', $t->id)->exists()) {
            $this->fail('This address is already taken.', 409);
        }
        $this->db()->table('tenants')->where('id', $t->id)->update(['slug' => $new, 'updated_at' => now()]);
        TenantAddress::forget((string) $t->slug);
        TenantAddress::forget($new);
        $this->audit($userId, (int) $t->id, 'slug', $t->slug . ' -> ' . $new);
        return ['address' => TenantAddress::payload($this->db()->table('tenants')->find($t->id))];
    }

    /* ------------------------------------------------------------------ public (end users) */

    /** المنشور بس (مفيش نشر => site = null والفرونت بيعرض الشكل الافتراضي بهوية العميل). */
    public function publicPayload(object $tenant): array
    {
        $row = $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
        $pub = $row ? $this->decode($row->published) : null;
        return [
            'tenant' => $tenant->public_id,
            'site' => $pub ? ['version' => (int) $row->version, 'published_at' => $row->published_at, 'settings' => TenantSiteSanitizer::full($pub), 'preview' => false] : null,
            'branding' => TenantBranding::publicPayload($tenant),
        ];
    }

    /** المسودة لحامل توكن المعاينة. null لو التوكن غلط (من غير ما نكشف إن فيه مسودة). */
    public function previewPayload(object $tenant, string $token): ?array
    {
        $row = $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
        if (!$row || !$row->preview_token || $token === '' || !hash_equals((string) $row->preview_token, $token)) {
            return null;
        }
        return ['tenant' => $tenant->public_id, 'site' => ['version' => (int) $row->version, 'published_at' => $row->published_at, 'settings' => $this->draftOf($row), 'preview' => true],
            'branding' => TenantBranding::publicPayload($tenant)];
    }
}
