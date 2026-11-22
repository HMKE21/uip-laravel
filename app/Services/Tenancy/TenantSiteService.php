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

    /**
     * يضمن إن الإعدادات فيها صفحتي الخصوصية والشروط الخاصتين بالمساحة (باسمها وإيميل دعمها) — فمفيش زائر بيتحوّل لسياسة المنصة الأصلية.
     * @param array<string,mixed> $settings إعدادات سليمة (full)
     */
    private function legalize(object $tenant, array $settings): array
    {
        $name = (string) ($tenant->name ?? '');
        $email = '';
        try {
            $b = TenantBranding::get((int) $tenant->id);
            $name = (string) (($b['display_name'] ?? '') ?: $name);
            $email = (string) ($b['support_email'] ?? '');
        } catch (\Throwable $e) { /* الهوية مش شرط */ }
        return TenantSiteLegal::fill($settings, $name, $email);
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
        return $this->legalize($tenant, TenantSiteSanitizer::full($settings));
    }

    private function draftOf(object $row, object $tenant): array
    {
        return $this->legalize($tenant, TenantSiteSanitizer::full($this->decode($row->draft) ?? []));
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
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'site');
        $row = $this->row($t);
        $pub = $this->decode($row->published);
        return [
            'site' => $this->meta($row) + ['draft' => $this->draftOf($row, $t), 'published_settings' => $pub ? $this->legalize($t, TenantSiteSanitizer::full($pub)) : null],
            'address' => TenantAddress::payload($t),
            'preview' => $this->previewInfo($t, $row),
            'templates' => $this->templateList((int) $t->id),
            'catalog' => $this->catalog(),
            'messages' => ['unread' => TenantSiteMessages::unread((int) $t->id)],
        ];
    }

    /** اللي الـ builder يعرضه كخيارات (قيم enum مسموحة). */
    public function catalog(): array
    {
        return [
            'sections' => array_keys(TenantSiteTemplates::sectionSpecs()), 'icons' => TenantSiteTemplates::ICONS, 'fonts' => TenantSiteTemplates::FONTS,
            'radius' => ['none', 'sm', 'md', 'lg', 'xl'], 'modes' => ['light', 'dark', 'auto'], 'density' => ['compact', 'comfortable', 'spacious'],
            'login_styles' => ['split', 'centered', 'card'], 'nav_styles' => ['top', 'centered', 'side', 'burger'], 'nav_scrolls' => ['sticky', 'static'], 'nav_buttons' => ['show', 'hide'],
            // الخطوط: كل عنصر {key,label,group(sans|serif|display),arabic,family}. family = اسم Google Fonts (null = خط النظام). font_heading بيقبل كمان 'same'.
            'font_catalog' => TenantSiteTemplates::fontCatalog(), 'font_scales' => ['sm', 'md', 'lg', 'xl'], 'heading_weights' => ['medium', 'semibold', 'bold', 'extrabold'],
            'page_kinds' => TenantSiteTemplates::PAGE_KINDS, 'legal_kinds' => TenantSiteTemplates::LEGAL_KINDS, 'template_kinds' => TenantSiteTemplates::TEMPLATE_KINDS, 'reserved_page_slugs' => TenantSiteTemplates::RESERVED_PAGE_SLUGS,
            'limits' => ['sections' => (int) config('tenancy.site.max_sections', 12), 'nav' => (int) config('tenancy.site.max_nav', 8),
                'pages' => (int) config('tenancy.site.max_pages', 8), 'page_sections' => (int) config('tenancy.site.max_page_sections', 10), 'posts' => (int) config('tenancy.site.max_posts', 30),
                'bytes' => (int) config('tenancy.site.max_bytes', 262144), 'seo_title' => 70, 'seo_description' => 160],
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
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'site');
        return $this->templateList((int) $t->id);
    }

    /* ------------------------------------------------------------------ draft */

    public function saveDraft(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $draft = $this->legalize($t, TenantSiteSanitizer::settings($in, $this->draftOf($row, $t)));
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
            $settings = $this->legalize($t, TenantSiteSanitizer::full($this->decode($c->settings) ?? []));
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
        $this->requestTemplateFeatures((int) $t->id, $userId, $key);
        return ['draft' => $settings, 'site' => $this->meta($this->row($t))];
    }

    /** القالب محتاج ميزات (طلبة/دكاترة/اجتماعات...): نطلب الناقص منها ونبلّغ أدمن المنصة. فشلها مايوقّفش تطبيق القالب. */
    private function requestTemplateFeatures(int $tenantId, int $userId, string $key): void
    {
        try {
            $need = TenantSiteTemplates::featuresFor($key);
            if (!$need || ($this->db()->table('tenants')->where('id', $tenantId)->value('mode') ?? 'shared') !== 'isolated') {
                return;
            }
            $missing = array_values(array_diff($need, TenantFeatures::enabledKeys($tenantId)));
            if (!$missing) {
                return;
            }
            $res = TenantFeatures::request($tenantId, $missing, $userId, 'needed by template ' . $key);
            TenantFeatureNotifier::requested($tenantId, $res['requested'] ?? [], $userId, 'needed by template ' . $key);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::notice('Template features not requested', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }

    /** يرجّع المسودة لآخر منشور (أو لقالب classic لو لسه مفيش نشر). */
    public function discardDraft(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $pub = $this->decode($row->published);
        $draft = $pub ? $this->legalize($t, TenantSiteSanitizer::full($pub)) : $this->withBrand($t, TenantSiteTemplates::find('classic')['settings']);
        $this->db()->table('tenant_sites')->where('tenant_id', $t->id)->update(['draft' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'draft_updated_at' => now(), 'updated_at' => now()]);
        $this->audit($userId, (int) $t->id, 'discard');
        return ['draft' => $draft, 'site' => $this->meta($this->row($t))];
    }

    /* ------------------------------------------------------------------ publish / versions */

    public function publish(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $row = $this->row($t);
        $draft = $this->draftOf($row, $t);
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
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'site');
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
        $draft = $this->legalize($t, TenantSiteSanitizer::full($this->decode($v->settings) ?? []));
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
        $draft = $this->draftOf($this->row($t), $t);
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
        $full = $pub ? $this->legalize($tenant, TenantSiteSanitizer::full($pub)) : null;
        $out = [
            'tenant' => $tenant->public_id,
            'site' => $pub ? ['version' => (int) $row->version, 'published_at' => $row->published_at, 'settings' => $full, 'preview' => false] : null,
            'branding' => TenantBranding::publicPayload($tenant),
            'canonical_base' => TenantSiteSeo::base($tenant), 'contact_form' => $full ? TenantSiteMessages::hasForm($full) : false,
        ];
        if (!$pub) {
            // لسه مفيش نشر: الفرونت بيعرض الشكل الافتراضي، لكن صفحتي الخصوصية والشروط لازم يكونوا موجودين وخاصين بالمساحة.
            $out['legal_pages'] = $this->legalize($tenant, TenantSiteSanitizer::full([]))['pages'];
        }
        return $out;
    }

    /** المسودة لحامل توكن المعاينة. null لو التوكن غلط (من غير ما نكشف إن فيه مسودة). */
    public function previewPayload(object $tenant, string $token): ?array
    {
        $row = $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
        if (!$row || !$row->preview_token || $token === '' || !hash_equals((string) $row->preview_token, $token)) {
            return null;
        }
        $draft = $this->draftOf($row, $tenant);
        return ['tenant' => $tenant->public_id, 'site' => ['version' => (int) $row->version, 'published_at' => $row->published_at, 'settings' => $draft, 'preview' => true],
            'branding' => TenantBranding::publicPayload($tenant), 'canonical_base' => TenantSiteSeo::base($tenant), 'contact_form' => TenantSiteMessages::hasForm($draft)];
    }

    /** الإعدادات المنشورة (سليمة) أو null لو لسه مفيش نشر. */
    private function publishedSettings(object $tenant): ?array
    {
        $row = $this->db()->table('tenant_sites')->where('tenant_id', $tenant->id)->first();
        $pub = $row ? $this->decode($row->published) : null;
        return $pub ? $this->legalize($tenant, TenantSiteSanitizer::full($pub)) : null;
    }

    /** SEO لمسار معيّن (للـ <head> وكروت المشاركة). بتوكن معاينة صالح بيتحسب من المسودة وبـ noindex. null لو التوكن غلط. */
    public function publicSeo(object $tenant, string $path, string $preview = ''): ?array
    {
        $branding = TenantBranding::publicPayload($tenant);
        if ($preview !== '') {
            $p = $this->previewPayload($tenant, $preview);
            return $p === null ? null : TenantSiteSeo::resolve($tenant, $p['site']['settings'], $branding, $path, true);
        }
        $settings = $this->publishedSettings($tenant) ?? $this->legalize($tenant, TenantSiteSanitizer::full([]));
        return TenantSiteSeo::resolve($tenant, $settings, $branding, $path, false);
    }

    public function publicSitemap(object $tenant): string
    {
        return TenantSiteSeo::sitemap($tenant, $this->publishedSettings($tenant) ?? $this->legalize($tenant, TenantSiteSanitizer::full([])));
    }

    public function publicRobots(object $tenant): string
    {
        return TenantSiteSeo::robots($tenant, $this->publishedSettings($tenant) ?? $this->legalize($tenant, TenantSiteSanitizer::full([])));
    }

    /** رسالة من نموذج التواصل في الموقع المنشور. */
    public function contact(object $tenant, array $in, string $ip): array
    {
        $pub = $this->publishedSettings($tenant);
        if ($pub === null) {
            $this->fail('This site does not accept messages.', 404);
        }
        return TenantSiteMessages::submit($tenant, $pub, $in, $ip);
    }

    /* ------------------------------------------------------------------ contact inbox (owner/admin) */

    public function messages(int $userId, int $tenantId, ?string $status, int $page): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        return TenantSiteMessages::list((int) $t->id, $status, $page);
    }

    public function setMessageStatus(int $userId, int $tenantId, int $messageId, string $status): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        TenantSiteMessages::setStatus((int) $t->id, $messageId, $status);
        return ['unread' => TenantSiteMessages::unread((int) $t->id)];
    }

    public function deleteMessage(int $userId, int $tenantId, int $messageId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        TenantSiteMessages::delete((int) $t->id, $messageId);
        $this->audit($userId, (int) $t->id, 'delete-message', (string) $messageId);
        return ['unread' => TenantSiteMessages::unread((int) $t->id)];
    }
}
