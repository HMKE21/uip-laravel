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
    // نفس القديمة: 30 ثانية كحد أدنى آمن (بعض الطلبات — خصوصًا AI
    // Assistant الكامل بسياق طويل — بتاخد أكتر من الافتراضي القديم 12s).
    // زوّد AI_TIMEOUT في .env حسب المزوّد بتاعك لو احتجت.
    'timeout'  => (int) env('AI_TIMEOUT', 30),

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
    // من جدول settings (مفاتيح ai_code_*) من غير تعديل كود.
    'code' => [
        'max_code_chars'     => (int) env('AI_CODE_MAX_CODE_CHARS', 30000),
        'max_error_chars'    => (int) env('AI_CODE_MAX_ERROR_CHARS', 6000),
        'max_tokens'         => (int) env('AI_CODE_MAX_TOKENS', 1500),
        'rate_limit_per_min' => (int) env('AI_CODE_RATE_LIMIT_PER_MIN', 10),
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
