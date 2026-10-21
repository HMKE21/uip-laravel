<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Sign in with Google. GOOGLE_CLIENT_IDS = Web client ID (بيستخدمه زرار الويب وتطبيق الأندرويد
    // مع بعض). ممكن تحط أكتر من ID مفصولين بفاصلة.
    'google' => [
        'client_ids' => env('GOOGLE_CLIENT_IDS', env('GOOGLE_CLIENT_ID', '')),
        // Drive backup that stays connected (server-side OAuth + refresh token). Secret of the SAME Web client, and
        // GOOGLE_DRIVE_REDIRECT = https://<api-domain>/api/v1/cloud/google/callback (add it to the client's Authorized redirect URIs).
        'client_secret'  => env('GOOGLE_CLIENT_SECRET', ''),
        'drive_redirect' => env('GOOGLE_DRIVE_REDIRECT', ''),
    ],

    // Microsoft (OneDrive backup من جوه "جهازي"). سجّل SPA app في Entra ID (Azure) وحط Redirect URI = https://<front-domain>/ms-auth.html
    // الصلاحيات: Files.ReadWrite.AppFolder + User.Read. MS_AUTHORITY: .../common (حسابات شخصية + جامعة) أو .../<tenant-id>.
    'microsoft' => [
        'client_id' => env('MS_CLIENT_ID', ''),
        'authority' => env('MS_AUTHORITY', 'https://login.microsoftonline.com/common'),
    ],

    // Connect GitHub (OAuth App). أنشئ OAuth App من GitHub → Settings → Developer settings،
    // وخلي Authorization callback URL = GITHUB_OAUTH_REDIRECT بالظبط.
    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID', ''),
        'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        'redirect'      => env('GITHUB_OAUTH_REDIRECT', ''),   // https://<api-domain>/api/v1/github/callback
        'scope'         => env('GITHUB_OAUTH_SCOPE', 'repo read:user'),
        // توكن المنصة (Personal Access Token بدون صلاحيات، للريبوز العامة بس) — بيرفع حد GitHub من 60 لـ 5000 طلب/ساعة
        'token'         => env('GITHUB_TOKEN', ''),
    ],

    // Push للموبايل (Firebase Cloud Messaging HTTP v1). شوف PUSH_NOTIFICATIONS.md.
    'fcm' => [
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
        'credentials_json' => env('FCM_CREDENTIALS_JSON', ''),
        'project_id'       => env('FCM_PROJECT_ID', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Project sandbox gateway (uip-sandbox — a SEPARATE service). SANDBOX_JWT_SECRET must be identical on both sides.
    'sandbox' => [
        'url'       => env('SANDBOX_URL', ''),
        'secret'    => env('SANDBOX_JWT_SECRET', ''),
        'token_ttl' => (int) env('SANDBOX_TOKEN_TTL', 120),
    ],

];
