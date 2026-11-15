<?php
/**
 * منقولة حرفيًا من config/ai.php القديمة. إعدادات عامة لأي endpoint
 * متوافق مع OpenAI /chat/completions، بيستهلكها App\Services\Ai\AIClient.
 * تغيير AI_PROVIDER/AI_BASE_URL/AI_MODEL في .env كفاية للتبديل لمزوّد
 * تاني من غير أي تعديل كود — نفس فلسفة القديمة بالظبط.
 */

return [
    'enabled'  => (bool) env('AI_FEATURES_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'none'),

    // أي endpoint متوافق مع OpenAI /chat/completions (OpenAI نفسها،
    // Azure OpenAI gateway، OpenRouter، vLLM ذاتي الاستضافة، ...).
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'api_key'  => env('AI_API_KEY', ''),
    'model'    => env('AI_MODEL', 'gpt-4o-mini'),

    // موديل احتياطي للمساعد لما الأساسي يفشل بـ 402/429/5xx (رصيد/كوتا/ضغط). اختياري —
    // base_url/api_key لو فاضيين بيستخدموا نفس الأساسيين. مثال: AI_FALLBACK_MODEL=gpt-oss:20b
    'fallback_model'    => env('AI_FALLBACK_MODEL', ''),
    'fallback_base_url' => rtrim((string) env('AI_FALLBACK_BASE_URL', ''), '/'),
    'fallback_api_key'  => env('AI_FALLBACK_API_KEY', ''),
    // نفس القديمة: 30 ثانية كحد أدنى آمن (بعض الطلبات — خصوصًا AI
    // Assistant الكامل بسياق طويل — بتاخد أكتر من الافتراضي القديم 12s).
    // زوّد AI_TIMEOUT في .env حسب المزوّد بتاعك لو احتجت.
    'timeout'  => (int) env('AI_TIMEOUT', 30),

    // توليد الصور داخل شات المساعد (أمر /image أو /صورة). base_url/api_key فاضيين = نفس مزوّد الشات (OpenRouter مثلًا).
    // model: لازم موديل بيطلّع صور (OpenRouter: Output modalities = image). الأدمن يغيّره من Settings -> AI (ai_image_model).
    // api: 'auto' (الافتراضي: OpenRouter => /images، غيره => /images/generations) | 'images' (نفس auto) | 'chat' = /chat/completions مع modalities.
    'image' => [
        'model'    => env('AI_IMAGE_MODEL', ''),
        'base_url' => rtrim((string) env('AI_IMAGE_BASE_URL', ''), '/'),
        'api_key'  => env('AI_IMAGE_API_KEY', ''),
        'api'      => env('AI_IMAGE_API', 'auto'),
        'timeout'  => (int) env('AI_IMAGE_TIMEOUT', 120),
        // Cloudflare flux-1-schnell: عدد الخطوات 1..8 (الأعلى = جودة أحسن واستهلاك أكبر من الكوتا المجانية).
        'steps'    => (int) env('AI_IMAGE_STEPS', 6),
        // سياق المنصة بيتبعت لمهندس البرومت (استخدمه لما الطلب عن المنصة نفسها: لوجو/بوستر/بانر).
        'brand_context' => env('AI_IMAGE_BRAND_CONTEXT',
            'UIPE is a university campus platform ("One Platform. Endless Connections."): chat, video meetings, courses and exams, projects, community, AI tutor. '
            . 'Brand look: deep navy blue (#0B2A6F) with a warm orange/amber accent swoosh, indigo/violet (#4F2CF5) call-to-action color, '
            . 'modern, friendly, academic, trustworthy, clean sans-serif typography. The brand name is written "UIPE" (Latin letters).'),
    ],

    // رؤية الصور: الصور المرفقة بتتبعت للموديل كـ image_url (مصغّرة). لو موديل الشات مش بيشوف صور:
    //  - حط موديل بيشوف في AI_VISION_MODEL (وممكن AI_VISION_BASE_URL / AI_VISION_API_KEY لو مزوّد تاني)، أو
    //  - AI_VISION=false وهيقول للمستخدم إنه مش قادر يشوف الصورة بدل ما يخمّن.
    // لو الموديل رفض الصور (400/415/422) بيكمّل تلقائيًا من غير الصور مع تنبيه للموديل إنه ما شافهاش.
    'vision' => [
        'enabled'    => filter_var(env('AI_VISION', true), FILTER_VALIDATE_BOOL),
        'model'      => env('AI_VISION_MODEL', ''),
        'base_url'   => rtrim((string) env('AI_VISION_BASE_URL', ''), '/'),
        'api_key'    => env('AI_VISION_API_KEY', ''),
        'max_images' => (int) env('AI_VISION_MAX_IMAGES', 4),
        'max_dim'    => (int) env('AI_VISION_MAX_DIM', 1568),
    ],

    // حدود نص المرفقات اللي بيتبعت للموديل (حروف).
    'attachments' => [
        'max_chars'     => (int) env('AI_ATTACHMENT_MAX_CHARS', 24000),   // لكل ملف في التيرن الحالي
        'history_chars' => (int) env('AI_ATTACHMENT_HISTORY_CHARS', 30000), // إجمالي نص ملفات التيرنات السابقة
    ],

    // ترجمة الفيديو التلقائية (VideoPlayer ← POST /api/v1/subtitles/auto): تفريغ الصوت بـ Whisper ثم ترجمة النص.
    // بيستخدم نفس AI_BASE_URL / AI_API_KEY لو سيبت base_url/api_key فاضيين. لو مزوّد الشات عندك مش بيدعم
    // /audio/transcriptions (Ollama مثلاً) حط مزوّد تاني هنا (مثلاً Groq: https://api.groq.com/openai/v1 + whisper-large-v3).
    'transcribe' => [
        'model'             => env('AI_TRANSCRIBE_MODEL', 'whisper-1'),
        'base_url'          => rtrim((string) env('AI_TRANSCRIBE_BASE_URL', ''), '/'),
        'api_key'           => env('AI_TRANSCRIBE_API_KEY', ''),
        'timeout'           => (int) env('AI_TRANSCRIBE_TIMEOUT', 120),
        'max_upload_kb'     => (int) env('AI_TRANSCRIBE_MAX_KB', 24000), // حد Whisper = 25MB
        'max_chunk_seconds' => (int) env('AI_TRANSCRIBE_CHUNK_SECONDS', 240),
    ],

    // مساعد الكود داخل محرر MyPC (App\Services\AiCodeAssistService). الأدمن يقدر يلبس فوق
    // enabled / default_mode / allow_mode_switch / rate_limit_per_min / temperature / system_prompt_extra
    // + autocomplete_enabled / complete_rate_limit_per_min (الإكمال التلقائي) + chat_enabled (شات اللوحة الجانبية)
    // من جدول settings (مفاتيح ai_code_*) من غير تعديل كود.
    'code' => [
        'max_code_chars'     => (int) env('AI_CODE_MAX_CODE_CHARS', 30000),
        'max_error_chars'    => (int) env('AI_CODE_MAX_ERROR_CHARS', 6000),
        'max_tokens'         => (int) env('AI_CODE_MAX_TOKENS', 1500),
        'rate_limit_per_min' => (int) env('AI_CODE_RATE_LIMIT_PER_MIN', 10),
        'complete_rate_limit_per_min' => (int) env('AI_CODE_COMPLETE_RATE_LIMIT_PER_MIN', 40), // الإكمال التلقائي
        'complete_max_tokens' => (int) env('AI_CODE_COMPLETE_MAX_TOKENS', 96),
        'chat_max_tokens'    => (int) env('AI_CODE_CHAT_MAX_TOKENS', 4000),          // شات اللوحة الجانبية
        'chat_max_context_chars' => (int) env('AI_CODE_CHAT_MAX_CONTEXT_CHARS', 80000), // إجمالي الملفات في طلب واحد
        'default_mode'       => env('AI_CODE_DEFAULT_MODE', 'tutor'), // tutor | assistant
    ],

    // مفتاح على مستوى الميزة: حتى لو enabled فوق = true، تخصيص الإيميلات
    // فاضل مقفول لحد ما ده يتفعّل صراحة.
    'email_personalization_enabled' => (bool) env('AI_EMAIL_PERSONALIZATION_ENABLED', false),

    // بيانات مؤسس/مطوّر المنصة اللي المساعد بيدّيها لأي يوزر يسأل عن التواصل
    // أو عن اللي بنى المنصة. تتغيّر من .env من غير تعديل كود.
    'founder' => [
        'name_en'  => env('UIP_FOUNDER_NAME_EN', 'Haitham Mohamed'),
        'name_ar'  => env('UIP_FOUNDER_NAME_AR', 'هيثم محمد'),
        'email'    => env('UIP_FOUNDER_EMAIL', 'haythemmohamed478@gmail.com'),
        'phone'    => env('UIP_FOUNDER_PHONE', '01143894042'),
        'linkedin' => env('UIP_FOUNDER_LINKEDIN', 'https://www.linkedin.com/in/haithem-mohamed-8b7143243/'),
    ],
];
