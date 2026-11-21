<?php

/**
 * UIPE Platform — Tenants (الخطوة 1): شركات / جامعات / مطورين بيستخدموا الـ API والـ SDK بهويتهم هما.
 */
return [
    /** الأدوار: owner (كل حاجة) · admin (أعضاء/branding/دومينات/موارد) · developer (تطبيقات ومفاتيح وwebhooks) · viewer (قراءة بس). */
    'roles' => ['owner', 'admin', 'developer', 'viewer'],

    /** الحد الأقصى لعدد الـ tenants اللي المستخدم الواحد يقدر يبقى owner لها (self-service). */
    'max_owned_per_user' => (int) env('TENANT_MAX_OWNED', 5),

    /** الحدود الافتراضية لكل tenant (tenants.limits بيغلبها). */
    'limits' => [
        'members' => (int) env('TENANT_MAX_MEMBERS', 25),
        'domains' => (int) env('TENANT_MAX_DOMAINS', 3),
        'apps'    => (int) env('TENANT_MAX_APPS', 20),
        'keys'    => (int) env('TENANT_MAX_KEYS', 50),
        'webhooks' => (int) env('TENANT_MAX_WEBHOOKS', 20),
        'participants' => (int) env('TENANT_MAX_PARTICIPANTS', 1000),
        'embed_origins' => (int) env('TENANT_MAX_EMBED_ORIGINS', 20),
    ],

    /** أدوار المشاركين الخارجيين (المستخدم النهائي عند العميل — من غير حساب UIPE). */
    'participant_roles' => ['participant', 'host', 'moderator', 'viewer'],

    /** الخطوة 2: Embed SDK. */
    'embed' => [
        /** عمر توكن الدخول (مرة واحدة) بالثواني: الافتراضي والحد الأقصى. */
        'join_ttl' => (int) env('TENANT_EMBED_JOIN_TTL', 120),
        'join_ttl_max' => (int) env('TENANT_EMBED_JOIN_TTL_MAX', 900),
        /** عمر جلسة الـ iframe بعد تبديل التوكن (ثواني). */
        'session_ttl' => (int) env('TENANT_EMBED_SESSION_TTL', 7200),
        /** اسم الـ global في الـ loader وبادئة رسائل postMessage (قابلة للتخصيص للـ white label). */
        'global' => env('TENANT_EMBED_GLOBAL', 'UIPE'),
        'namespace' => env('TENANT_EMBED_NAMESPACE', 'uipe'),
        /** مصادر إضافية مسموحة في connect-src لصفحة الـ iframe (مثلًا wss://media.example.com للـ media server في الخطوة 3). مفصولة بمسافة. */
        'connect_extra' => (string) env('TENANT_EMBED_CONNECT_EXTRA', ''),
    ],

    /** Features اللي أدمن المنصة بس يقدر يديها لـ tenant. */
    'features' => ['white_label', 'custom_domain'],

    'domains' => [
        /** سجل TXT المطلوب: _uipe-challenge.<domain> = uipe-verify=<token> */
        'txt_prefix' => '_uipe-challenge',
        /** هدف الـ CNAME (البروكسي بتاعنا: Cloudflare for SaaS / Caddy). فاضي = مفيش فحص CNAME والدومين بيبقى active بعد التحقق من الـ TXT. */
        'cname_target' => env('TENANT_CNAME_TARGET', ''),
        /** IPs بتاعة سيرفرنا (مفصولة بفاصلة). الدومين اللي A/AAAA بتاعه بيشاور عليها بيعدّي كـ "متوجّه" — لدومينات DuckDNS وأي مزوّد مبيدعمش CNAME. */
        'edge_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_EDGE_IPS', ''))))),
        /** دومين محجوز بمطالبة pending أقدم من كده ممكن حد تاني ياخده. */
        'pending_ttl_hours' => (int) env('TENANT_DOMAIN_PENDING_HOURS', 48),
        /** نطاقات المنصة نفسها — مينفعش تتسجّل (بالإضافة لـ host بتاع APP_URL تلقائيًا). */
        'reserved' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_RESERVED_DOMAINS', ''))))),
        /** سر نقطة الـ "ask" بتاعة Caddy on-demand TLS (GET /api/dev/v1/tenant/domain-check?domain=…&token=…). فاضي = النقطة مقفولة (404). */
        'ask_secret' => env('TENANT_ASK_SECRET', ''),
    ],

    /**
     * العزل الكامل (white-label): كل عميل بقاعدة بيانات خاصة (tenants.mode = isolated).
     */
    'isolation' => [
        'enabled' => filter_var(env('TENANCY_ISOLATION', true), FILTER_VALIDATE_BOOLEAN),
        /** اسم connection في config/database.php اللي بيتنسخ منه إعدادات قواعد العملاء. فاضي = الـ default بتاع المنصة. */
        'template' => env('TENANCY_DB_TEMPLATE', ''),
        /** connection بيعمل CREATE DATABASE (محتاج الصلاحية). فاضي = الـ default بتاع المنصة. */
        'admin_connection' => env('TENANCY_ADMIN_CONNECTION', ''),
        /** مستخدم MySQL لقواعد العملاء (اختياري: لو فاضي بيستخدم نفس مستخدم المنصة). */
        'username' => env('TENANCY_DB_USERNAME', ''),
        'password' => env('TENANCY_DB_PASSWORD', ''),
        /** بادئة أسماء قواعد العملاء. */
        'db_prefix' => env('TENANCY_DB_PREFIX', 'uipt_'),
        /** دومينات المنصة الأم (مفصولة بفاصلة) بالإضافة لـ APP_URL: لو الفرونت بتاعها بعت X-Tenant-Domain بأي منها نعتبره المنصة. */
        'platform_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_PLATFORM_HOSTS', ''))))),
        /** جداول بتتنسخ كما هي من قاعدة المنصة لقاعدة العميل الجديد (الأدوار والصلاحيات) — بالترتيب. */
        'copy_tables' => ['roles', 'permissions', 'role_permission'],
        /** دور أول أدمن عند العميل. */
        'admin_role' => 'admin',
    ],

    /**
     * رابط جاهز من المنصة لأي عميل (من غير دومين): https://<المنصة>/w/<slug>. الـ slug ممكن يكون أرقام بس.
     * ده مسار للتجربة والبداية: نفس origin المنصة (الكوكيز/التخزين مشتركين). العزل الكامل = دومين العميل أو subdomain.
     */
    'address' => [
        'enabled' => filter_var(env('TENANT_ADDRESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        /** أصل الروابط (https://uip-nub.vercel.app). فاضي = app.frontend_url. */
        'base_url' => env('TENANT_ADDRESS_BASE', ''),
        'path_prefix' => 'w',
        /** slugs محجوزة للمنصة. */
        'reserved' => ['admin', 'api', 'w', 'www', 'app', 'login', 'register', 'signup', 'dashboard', 'developer', 'developers', 'assets', 'static', 'uploads',
            'mail', 'support', 'help', 'status', 'docs', 'uip', 'uipe', 'platform', 'null', 'undefined', 'test', 'demo', 'privacy-policy', 'projects'],
    ],

    /** موقع العميل (قوالب/تخصيص/نشر). */
    'site' => [
        'max_bytes' => (int) env('TENANT_SITE_MAX_BYTES', 32768),
        'max_sections' => 12,
        'max_nav' => 8,
        'max_custom_templates' => (int) env('TENANT_SITE_MAX_TEMPLATES', 20),
        'keep_versions' => (int) env('TENANT_SITE_KEEP_VERSIONS', 10),
    ],
];
