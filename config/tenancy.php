<?php

/**
 * UIPE Platform — Tenants (الخطوة 1): شركات / جامعات / مطورين بيستخدموا الـ API والـ SDK بهويتهم هما.
 */
return [
    /** الأدوار: owner (كل حاجة) · admin (أعضاء/branding/دومينات/موارد) · developer (تطبيقات ومفاتيح وwebhooks) · viewer (قراءة بس) · employee (موظف: بيدخل المساحة ويستخدمها من غير أي إدارة). */
    'roles' => ['owner', 'admin', 'developer', 'viewer', 'employee'],

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
        /** true (الافتراضي، الأكثر أمانًا): لازم TXT يثبت الملكية. false: لو TENANT_CNAME_TARGET أو TENANT_EDGE_IPS متضبطين، التوجيه الصح وحده بيفعّل الدومين. */
        'require_txt' => filter_var(env('TENANT_DOMAIN_REQUIRE_TXT', true), FILTER_VALIDATE_BOOLEAN),
        /** التفعيل التلقائي: أمر tenant:domains-check (كل دقيقة بالـ scheduler) بيفحص الـ DNS ويفعّل الدومين ويطلب/يفحص شهادة SSL بدون ضغط زرار. */
        'auto' => filter_var(env('TENANT_DOMAIN_AUTO', true), FILTER_VALIDATE_BOOLEAN),
        /** أقصى عدد دومينات بيتفحصوا في الدورة الواحدة. */
        'auto_batch' => (int) env('TENANT_DOMAIN_AUTO_BATCH', 50),
        /** فحص شهادة SSL: بعد التفعيل بنفتح اتصال TLS على الدومين:443 (ده كمان بيحفّز Caddy on-demand يصدر الشهادة). */
        'ssl_probe' => filter_var(env('TENANT_DOMAIN_SSL_PROBE', true), FILTER_VALIDATE_BOOLEAN),
        'ssl_renew_days' => (int) env('TENANT_DOMAIN_SSL_RENEW_DAYS', 14),
    ],

    /**
     * العزل الكامل (white-label): كل عميل بقاعدة بيانات خاصة (tenants.mode = isolated).
     */
    /** Launch (إنشاء منصة جاهزة بنفسه من مركز المطورين). الميزات دي بتتفعّل فورًا؛ أي ميزة تانية بتتبعت كطلب للأدمن. */
    'launch' => [
        'enabled' => filter_var(env('TENANT_LAUNCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'admin_mfa_grace_days' => (int) env('TENANT_ADMIN_MFA_GRACE_DAYS', 3),
        /** after_response (الافتراضي): بيشتغل بعد ما الرد يتبعت من غير worker؛ queue: لو عندك queue worker شغّال. */
        'run' => env('TENANT_LAUNCH_RUN', 'after_response'),
        'invite_days' => (int) env('TENANT_INVITE_DAYS', 7),
        /** ميزات بتتفعّل فورًا حسب نوع المنصة (الجامعة من غير "الإدارة الأكاديمية" مالهاش لازمة). */
        'auto_features_by_kind' => [
            'university' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_LAUNCH_UNIVERSITY_FEATURES', 'academic,meetings'))))),
        ],
        'auto_features' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_LAUNCH_AUTO_FEATURES', 'messaging,meetings,feed'))))),
    ],

    /** حسابات مستخدمي المنصة المعزولة (اللي بيعملها المالك بباسورد): اسم العرض => slug الدور جوّه قاعدة المنصة. */
    'platform_users' => [
        'roles' => ['admin' => 'admin', 'employee' => env('TENANT_EMPLOYEE_ROLE', 'student')],
    ],

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
        'max_bytes' => (int) env('TENANT_SITE_MAX_BYTES', 262144),
        'max_sections' => 12,
        'max_pages' => (int) env('TENANT_SITE_MAX_PAGES', 8),
        'max_page_sections' => 10,
        'max_posts' => (int) env('TENANT_SITE_MAX_POSTS', 30),
        /** رسائل نموذج التواصل: أقصى عدد محفوظ لكل عميل، وأقصى عدد يومي (حماية من السبام). */
        'max_messages' => (int) env('TENANT_SITE_MAX_MESSAGES', 1000),
        'messages_per_day' => (int) env('TENANT_SITE_MESSAGES_PER_DAY', 200),
        'max_nav' => 8,
        'max_custom_templates' => (int) env('TENANT_SITE_MAX_TEMPLATES', 20),
        'keep_versions' => (int) env('TENANT_SITE_KEEP_VERSIONS', 10),
    ],

    /**
     * إدارة بيانات المساحة (Database Manager) — لمالك المساحة المعزولة بس، على قاعدة المساحة نفسها.
     * الأنماط fnmatch (حروف صغيرة). الجداول المخفية مش بتظهر ومش بتتقري ولا بتتذكر في SQL.
     * الأعمدة الحساسة بتتقنّع في النتايج ومبتتعدّلش ومينفعش تتذكر في SQL.
     * مهم للإنتاج: اضبط TENANCY_DB_USERNAME على مستخدم MySQL صلاحياته على قواعد العملاء بس (مفيش GRANT على قواعد تانية)،
     * ولو عايز تفصل صلاحية القراءة عن الكتابة استخدم TENANT_DATA_DB_USERNAME (مستخدم SELECT بس للـ SQL editor).
     */
    'data' => [
        'enabled' => filter_var(env('TENANT_DATA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        /** false = قراءة بس (بيقفل الإضافة/التعديل/الحذف من الـ Grid). */
        'allow_write' => filter_var(env('TENANT_DATA_WRITE', true), FILTER_VALIDATE_BOOLEAN),
        'username' => env('TENANT_DATA_DB_USERNAME', ''),
        'password' => env('TENANT_DATA_DB_PASSWORD', ''),
        'page_max' => 100,
        'sql_max_rows' => (int) env('TENANT_DATA_SQL_ROWS', 500),
        'sql_timeout_ms' => (int) env('TENANT_DATA_SQL_TIMEOUT_MS', 8000),
        'sql_max_chars' => 20000,
        'cell_chars' => 300,
        'full_cell_bytes' => 65536,
        'count_cap' => 100000,
        'hidden_tables' => [
            'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'user_sessions', 'trusted_devices',
            'refresh_tokens', 'api_tokens', 'api_token_usages', 'password_reset_tokens', 'password_history', 'email_verification_tokens',
            'passkeys', 'scim_tokens', 'scim_identities', 'push_devices', 'user_social_accounts', 'github_connections', 'google_drive_connections',
            'dev_api_keys', 'dev_oauth_*', 'tenant_auth_settings', 'drive_share_visitors', 'blocked_devices', 'mail_accounts',
            '*_tokens', '*_secrets', '*_credentials', 'oauth_*',
        ],
        /** جداول بتتعرض وتتقري بس (سجلات تدقيق + داخليات المنصة). */
        'readonly_tables' => ['audit_logs', 'security_logs', 'login_events', 'mail_audit_logs', 'dev_request_logs', 'user_activity_events', 'content_safety_events', 'tenant_*', 'dev_*', 'provisioning_runs'],
        'sensitive_columns' => [
            '*password*', '*passwd*', '*token*', '*secret*', 'hash', '*_hash', 'hash_*', '*_enc', 'otp', 'otp_*', '*_otp', 'totp*', '*_totp*', '*recovery_code*',
            '*api_key*', 'key_hash', '*private_key*', '*public_key*', '*credential*', '*cookie*', 'salt', '*session_id*', 'remember_*',
        ],
    ],
];
