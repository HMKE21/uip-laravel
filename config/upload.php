<?php
/**
 * نسخة طبق الأصل من config/upload.php القديمة (كل الفئات — الموديولات
 * الجاية هتستخدم فئاتها بتاعتها، والموديول العام /api/v1/files بيستخدم
 * 'general'). antivirus.enabled=true افتراضيًا بيعتمد على وجود clamscan
 * فعليًا على السيرفر — لو مش موجود، FileUploadService بيسجّل تحذير
 * ويكمل الرفع (fail-open) إلا لو UPLOAD_AV_REQUIRED=true، بالظبط زي
 * القديم.
 */

return [
    'max_size_kb' => (int) env('UPLOAD_MAX_KB', 10240),
    // Spec section 13 "Virus/Malware Scanning". Shells out to a ClamAV CLI
    // binary (clamscan or, for large deployments, clamdscan talking to a
    // running clamd) against every uploaded file — this config lives in
    // the generic upload config on purpose, same reasoning as
    // 'allowed'/'paths' above. If the binary isn't installed on this
    // server: 'required' => false means the upload still proceeds (with a
    // Logger::security() warning so admins see the gap in Security Logs);
    // set UPLOAD_AV_REQUIRED=true in production once ClamAV is provisioned
    // to fail closed instead.
    // PDF فيه JavaScript/Launch/ملفات مضمّنة بيتشغّل على نفس الأوريجن => reject (الافتراضي) | allow.
    // محتوى المواد: سقف أكبر من الافتراضي لأن المحاضرات (pptx) والفيديوهات أكبر من 10MB. (بالـ KB)
    // لازم يتظبط معاه upload_max_filesize / post_max_size في PHP وحدّ الرفع في الـ reverse proxy.
    'course_materials_max_kb'       => (int) env('UPLOAD_COURSE_MATERIALS_MAX_KB', 51200),
    'course_materials_video_max_kb' => (int) env('UPLOAD_COURSE_VIDEO_MAX_KB', 204800),
    // التكليفات: ملف المدرّس وملف تسليم الطالب (بالـ KB).
    'course_assignments_max_kb'     => (int) env('UPLOAD_COURSE_ASSIGNMENTS_MAX_KB', 20480),
    'pdf_active_content' => env('UPLOAD_PDF_ACTIVE_CONTENT', 'reject'),
    'antivirus' => [
        'enabled'  => (bool) env('UPLOAD_AV_ENABLED', true),
        'binary'   => env('UPLOAD_AV_BINARY', 'clamscan'),
        'required' => (bool) env('UPLOAD_AV_REQUIRED', false),
        'timeout'  => (int) env('UPLOAD_AV_TIMEOUT', 30),
    ],
    'allowed' => [
        'documents' => ['pdf', 'doc', 'docx'],
        'avatars'   => ['jpg', 'jpeg', 'png', 'webp'],
        // خلفيات الشات الشخصية (صور المستخدم نفسه) — صور بس، والفحص بالمحتوى (getimagesize) بيتم في FileUploadService.
        'chat_wallpapers' => ['jpg', 'jpeg', 'png', 'webp'],
        'projects'  => ['pdf', 'doc', 'docx', 'zip', 'ppt', 'pptx'],
        'logos'     => ['jpg', 'jpeg', 'png', 'webp', 'svg'],
        // University signature / stamp / dean signature printed on certificates.
        // PNG only (transparency), no svg on purpose (XSS from the same origin).
        'branding'  => ['png'],
        // صورة السؤال التوضيحية — بدون svg عن قصد (XSS من نفس الأوريجن).
        'question_images' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        // Graduation Projects (Research Projects + Team Collaboration) —
        // spec's "Documents" section: Documents/Images/Videos as supporting
        // files on a research project. This key was missing entirely, which
        // meant ResearchProjectService::addFile()/replaceFile() resolved an
        // empty allow-list from config() and rejected every upload
        // regardless of file type ("File type not allowed. Accepted: ").
        'research-projects' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar',
            'png', 'jpg', 'jpeg', 'gif', 'webp',
            'mp4', 'mov', 'webm',
        ],
        // Cybersecurity Portal (Phase 28) — Incident Management "Upload
        // Evidence / Attach Files" (spec section 5). Investigation evidence
        // is a mix of screenshots, packet captures exported as text/CSV,
        // log bundles, and written reports — not a design-asset format
        // list, so this gets its own category rather than reusing 'documents'.
        'incident_evidence' => [
            'png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'tiff',
            'pdf', 'doc', 'docx', 'xlsx', 'csv', 'txt', 'log', 'json', 'xml',
            'zip', 'eml', 'msg',
        ],
        // Cybersecurity Portal (Phase 28) — Security Report Management
        // (spec section 1). The exact 7 formats the spec asks for support
        // both uploading AND exporting: PDF, Excel, CSV, JSON, XML, DOCX, PPTX.
        'security_reports' => ['pdf', 'xlsx', 'csv', 'json', 'xml', 'docx', 'pptx'],
        // Data Analysis Portal (Phase 29) — Report Management (spec section
        // 1). Same 7-format list as security_reports above, kept as its own
        // key so the two portals' upload policies can diverge independently
        // later (e.g. via /admin/settings "Upload Policy" per-portal limits).
        'data_analysis_reports' => ['pdf', 'xlsx', 'csv', 'json', 'xml', 'docx', 'pptx'],
        // Enterprise Messaging System (Global Upgrade) — File Sharing spec
        // section: images, video, audio, office docs, and common archive/
        // source-code formats attachable inside any conversation. Content-
        // sniffing (FileUploadService::assertContentMatchesExtension) only
        // has real signatures for a subset of these (image/pdf/zip-family/
        // fonts) — the rest (mp4/mp3/csv/source files/...) rely on the
        // extension whitelist + malware scan hook same as every other
        // category with formats outside that signature list.
        'messages' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'm4a',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
            'json', 'xml', 'zip', 'rar', '7z',
            'txt', 'md', 'log',
            'php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb',
            'html', 'css', 'sql', 'sh', 'yml', 'yaml',
        ],
        // AI Assistant (Phase 33) — spec section 2's exact message-attachment
        // format list (voice messages land as 'audio' formats, video/audio
        // players and image/PDF preview all reuse the same extensions the
        // Messaging category already supports). Admin can further narrow
        // this at runtime via Admin AI Controls -> Allowed File Types
        // (settings key 'ai_assistant_allowed_extensions', see
        // AiAssistantSettingsController) — this list is only the ceiling.
        'ai_assistant' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'm4a',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'csv', 'json', 'sql', 'zip', 'rar', '7z',
            'txt', 'md', 'log',
            'php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb',
            'html', 'css', 'sh', 'yml', 'yaml',
        ],
        // Student Portal spec section 2 "University Feed" — post attachments:
        // Images/Videos/PDFs/Attachments. Kept separate from 'messages' so an
        // admin can tune feed limits independently later.
        'feed' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'mp4', 'webm', 'mov',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
        ],
        // Course enrol / drop requests: the student's supporting document (medical note, form, screenshot...).
        'course_requests' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'zip'],
        // Course materials: التضييق per-kind بيحصل في CourseMaterialService::ALLOWED.
        'course_materials' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'txt', 'zip',
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'mp4', 'webm', 'mov',
        ],
        'group_files' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'mp4', 'webm', 'mov',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
        ],
        // Student Portal spec section 2 "University Announcements" — same
        // attachment shape as the Feed's, kept as its own key for the same
        // independent-tuning reason 'feed' already is.
        'announcements' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'mp4', 'webm', 'mov',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
        ],
        // Meetings & Collaboration Platform — Round 7 (بند 19 "File
        // Sharing"): "PDF/DOC/DOCX/PPT/PPTX/XLS/XLSX/Images/ZIP/Other
        // allowed formats" حرفيًا زي ما المواصفة بتنص، بالإضافة لـ 'rar'
        // كتمثيل لـ "Other allowed formats" (نفس أرشيف تاني شائع زي
        // 'group_files'/'feed' المتشابهين).
        //
        // تحديث: الاجتماع بيقبل أي نوع ملف ('*') — الحماية بقت من الفحص مش من
        // قائمة الامتدادات: ClamAV (لو متثبّت) + فحص EICAR المدمج + تحييد
        // الامتدادات اللي السيرفر ممكن ينفّذها (php/html/xml => .txt) +
        // SvgGuard للـ SVG. لو الأدمن فعّل سياسة "restrict_extensions" في
        // الإعدادات العامة بتفضل هي صاحبة القرار (راجع effectiveAllowed).
        'meetings' => ['*'],
        // Generic REST API Files/Uploads module (spec section 9 "File
        // Upload APIs") — the standalone /api/v1/files surface used when an
        // upload isn't tied to one of the domain-specific categories above.
        // Union of the broadest existing lists ('ai_assistant', 'messages')
        // plus a few extra vector/design formats for completeness.
        'general' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'm4a',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'csv', 'json', 'xml', 'sql', 'zip', 'rar', '7z',
            'txt', 'md', 'log',
            'php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb',
            'html', 'css', 'sh', 'yml', 'yaml',
            'fig', 'xd', 'sketch', 'ai', 'eps',
        ],
    ],
    'paths' => [
        'avatars'   => 'uploads/avatars',
        'chat_wallpapers' => 'uploads/chat-wallpapers',
        'course_assignments'     => 'uploads/course-assignments',
        'assignment_submissions' => 'uploads/assignment-submissions',
        'documents' => 'uploads/documents',
        'projects'  => 'uploads/projects',
        'reports'   => 'uploads/reports',
        'logos'     => 'uploads/logos',
        'branding'  => 'uploads/branding',
        'question_images' => 'uploads/question-images',
        'incident_evidence' => 'uploads/incident_evidence',
        'security_reports'  => 'uploads/security_reports',
        'data_analysis_reports' => 'uploads/data_analysis_reports',
        'messages'  => 'uploads/messages',
        'ai_assistant' => 'uploads/ai_assistant',
        'feed'      => 'uploads/feed',
        'group_files' => 'uploads/group-files',
        'course_requests' => 'uploads/course-requests',
        'course_materials' => 'uploads/course-materials',
        'announcements' => 'uploads/announcements',
        'meetings'  => 'uploads/meetings',
        // Round 9 (بند 18 — Recording storage). فئة منفصلة عن 'meetings'
        // العادية (مستندات/صور صغيرة) عشان ملفات الفيديو/الصوت الكبيرة
        // تتخزن في مجلد مستقل، أسهل في الأرشفة/التنظيف لاحقًا.
        'meeting-recordings' => 'uploads/meeting-recordings',
        'general'   => 'uploads/general',
    ],
];
