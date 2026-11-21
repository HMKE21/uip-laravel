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
    ],

    /** Features اللي أدمن المنصة بس يقدر يديها لـ tenant. */
    'features' => ['white_label', 'custom_domain'],

    'domains' => [
        /** سجل TXT المطلوب: _uipe-challenge.<domain> = uipe-verify=<token> */
        'txt_prefix' => '_uipe-challenge',
        /** هدف الـ CNAME (البروكسي بتاعنا: Cloudflare for SaaS / Caddy). فاضي = مفيش فحص CNAME والدومين بيبقى active بعد التحقق من الـ TXT. */
        'cname_target' => env('TENANT_CNAME_TARGET', ''),
        /** دومين محجوز بمطالبة pending أقدم من كده ممكن حد تاني ياخده. */
        'pending_ttl_hours' => (int) env('TENANT_DOMAIN_PENDING_HOURS', 48),
        /** نطاقات المنصة نفسها — مينفعش تتسجّل (بالإضافة لـ host بتاع APP_URL تلقائيًا). */
        'reserved' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_RESERVED_DOMAINS', ''))))),
        /** سر نقطة الـ "ask" بتاعة Caddy on-demand TLS (GET /api/dev/v1/tenant/domain-check?domain=…&token=…). فاضي = النقطة مقفولة (404). */
        'ask_secret' => env('TENANT_ASK_SECRET', ''),
    ],
];
