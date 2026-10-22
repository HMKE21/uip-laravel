<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\RefreshTokenController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\VerifyEmailController;
use App\Http\Controllers\Api\Auth\ConfirmEmailChangeController;
use App\Http\Controllers\Api\Auth\RequestEmailChangeController;
use App\Http\Controllers\Api\Auth\RoleSelectionController;
use App\Http\Controllers\Api\Auth\UniversitiesController;
use App\Http\Controllers\Api\Auth\SessionsController;
use App\Http\Controllers\Api\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\FilesApiController;
use App\Http\Controllers\Api\UniversitiesApiController;
use App\Http\Controllers\Api\FacultyApiController;
use App\Http\Controllers\Api\GroupsApiController;
use App\Http\Controllers\Api\AnalyticsApiController;
use App\Http\Controllers\Api\DataAnalysisAdvancedAnalyticsApiController;
use App\Http\Controllers\Api\DataAnalysisAiInsightsApiController;
use App\Http\Controllers\Api\DataAnalysisDashboardApiController;
use App\Http\Controllers\Api\DataAnalysisDataQualityApiController;
use App\Http\Controllers\Api\DataAnalysisExamsApiController;
use App\Http\Controllers\Api\DataAnalysisExplorerApiController;
use App\Http\Controllers\Api\DataAnalysisExportsApiController;
use App\Http\Controllers\Api\DataAnalysisForecastingApiController;
use App\Http\Controllers\Api\DataAnalysisKpisApiController;
use App\Http\Controllers\Api\DataAnalysisQueriesApiController;
use App\Http\Controllers\Api\DataAnalysisReportCommentsApiController;
use App\Http\Controllers\Api\DataAnalysisReportFilesApiController;
use App\Http\Controllers\Api\DataAnalysisReportsApiController;
use App\Http\Controllers\Api\DataAnalysisSegmentsApiController;
use App\Http\Controllers\Api\DataAnalysisSearchApiController;
use App\Http\Controllers\Api\DataAnalysisSettingsApiController;
use App\Http\Controllers\Api\DataAnalysisWorkspaceApiController;
use App\Http\Controllers\Api\SavedDashboardsApiController;
use App\Http\Controllers\Api\AnnouncementsApiController;
use App\Http\Controllers\Api\FeedApiController;
use App\Http\Controllers\Api\StudentsApiController;
use App\Http\Controllers\Api\BulkApiController;
use App\Http\Controllers\Api\CourseApiController;
use App\Http\Controllers\Api\CourseMaterialsApiController;
use App\Http\Controllers\Api\CourseTimetableApiController;
use App\Http\Controllers\Api\RegistrationApiController;
use App\Http\Controllers\Api\CourseAttendanceApiController;
use App\Http\Controllers\Api\CourseAssignmentApiController;
use App\Http\Controllers\Api\AccountLinkApiController;
use App\Http\Controllers\Api\StudentSettingsApiController;
use App\Http\Controllers\Api\AcademicStaffSettingsApiController;
use App\Http\Controllers\Api\ContactsApiController;
use App\Http\Controllers\Api\GroupHubApiController;
use App\Http\Controllers\Api\StudentGroupChatApiController;
use App\Http\Controllers\Api\UniversityApprovalsApiController;
use App\Http\Controllers\Api\UniversityJoinRequestsApiController;
use App\Http\Controllers\Api\UniversityVerificationApiController;
use App\Http\Controllers\Api\PatentsApiController;
use App\Http\Controllers\Api\NotificationsApiController;
use App\Http\Controllers\Api\MeetingsApiController;
use App\Http\Controllers\Api\MeetingsChatApiController;
use App\Http\Controllers\Api\MeetingsLobbyApiController;
use App\Http\Controllers\Api\MeetingsSignalingApiController;
use App\Http\Controllers\Api\MeetingsHostControlApiController;
use App\Http\Controllers\Api\MeetingsFilesApiController;
use App\Http\Controllers\Api\MeetingsNotesApiController;
use App\Http\Controllers\Api\MeetingsAnalyticsApiController;
use App\Http\Controllers\Api\MeetingsPollsApiController;
use App\Http\Controllers\Api\MeetingsRecordingsApiController;
use App\Http\Controllers\Api\MeetingsAttendanceApiController;
use App\Http\Controllers\Api\MeetingsInvitationsApiController;
use App\Http\Controllers\Api\MessagingController;
use App\Http\Controllers\Api\ChunkedUploadController;
use App\Http\Controllers\Api\GroupChatController;
use App\Http\Controllers\Api\ChatSafetyApiController;
use App\Http\Controllers\Api\ConversationTrashController;
use App\Http\Controllers\Api\AccountPrivacyApiController;
use App\Http\Controllers\Api\DesktopSettingsApiController;
use App\Http\Controllers\Api\AdminAiConversationsApiController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AiCodeAssistController;
use App\Http\Controllers\Api\AiAssistantSettingsController;
use App\Http\Controllers\Api\FaqIntentController;
use App\Http\Controllers\Api\SecurityDashboardApiController;
use App\Http\Controllers\Api\SecuritySessionsApiController;
use App\Http\Controllers\Api\SecurityAlertsApiController;
use App\Http\Controllers\Api\SecurityLogsApiController;
use App\Http\Controllers\Api\SecurityReportsApiController;
use App\Http\Controllers\Api\SecurityReportFilesApiController;
use App\Http\Controllers\Api\SecuritySettingsApiController;
use App\Http\Controllers\Api\SecurityIncidentsApiController;
use App\Http\Controllers\Api\SecurityVulnerabilitiesApiController;
use App\Http\Controllers\Api\SecurityPoliciesApiController;
use App\Http\Controllers\Api\SupervisorsApiController;
use App\Http\Controllers\Api\SupervisorSettingsApiController;
use App\Http\Controllers\Api\SupervisorDashboardApiController;
use App\Http\Controllers\Api\AcademicStaffApiController;
use App\Http\Controllers\Api\ExamAnalyticsApiController;
use App\Http\Controllers\Api\ExamAppealApiController;
use App\Http\Controllers\Api\ExamCopyApiController;
use App\Http\Controllers\Api\CourseGradebookApiController;
use App\Http\Controllers\Api\GradingApiController;
use App\Http\Controllers\Api\ExamQuestionQualityApiController;
use App\Http\Controllers\Api\ExamSimilarityApiController;
use App\Http\Controllers\Api\ExamAttemptApiController;
use App\Http\Controllers\Api\ExamGradingApiController;
use App\Http\Controllers\Api\ExamIntegrityApiController;
use App\Http\Controllers\Api\ExamResultsExportApiController;
use App\Http\Controllers\Api\ExamResultsHubApiController;
use App\Http\Controllers\Api\ExamExportsApiController;
use App\Http\Controllers\Api\ExamSecurityApiController;
use App\Http\Controllers\Api\ExamSystemApiController;
use App\Http\Controllers\Api\StudentExamApiController;
use App\Http\Controllers\Api\FacultyApprovalsApiController;
use App\Http\Controllers\Api\FacultySettingsApiController;
use App\Http\Controllers\Api\DashboardsApiController;
use App\Http\Controllers\Api\ProjectsApiController;
use App\Http\Controllers\Api\SandboxApiController;
use App\Http\Controllers\Api\DriveApiController;
use App\Http\Controllers\Api\DriveShareApiController;
use App\Http\Controllers\Api\GithubConnectionApiController;
use App\Http\Controllers\Api\GoogleDriveConnectionApiController;
use App\Http\Controllers\Api\AdminApprovalsApiController;
use App\Http\Controllers\Api\AdminFeaturedProjectsApiController;
use App\Http\Controllers\Api\SupervisorProjectsApiController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\PortfoliosApiController;
use App\Http\Controllers\Api\ReportsApiController;
use App\Http\Controllers\Api\AdminReportsApiController;
use App\Http\Controllers\Api\AdminSettingsApiController;
use App\Http\Controllers\Api\AdminDashboardApiController;
use App\Http\Controllers\Api\AdminVisitorsApiController;
use App\Http\Controllers\Api\AdminUniversitiesApiController;
use App\Http\Controllers\Api\AdminMessagingOversightApiController;
use App\Http\Controllers\Api\AdminMobileApiController;
use App\Http\Controllers\Api\AdminNotificationSettingsApiController;
use App\Http\Controllers\Api\AdminAiCodeReviewApiController;
use App\Http\Controllers\Api\AdminAiCodeReviewExportController;
use App\Http\Controllers\Api\AdminSecurityLogsApiController;
use App\Http\Controllers\Api\AdminUsersApiController;
use App\Http\Controllers\Api\AdminRolesApiController;
use App\Http\Controllers\Api\AdminAuditLogsApiController;
use App\Http\Controllers\Api\GraduationApiController;
use App\Http\Controllers\Api\SupervisorProjectGradeApiController;

/**
 * كل الـ 15 endpoint في AUTH_API_CONTRACT.md منقولين دلوقتي. two-factor/verify
 * و two-factor/cancel بقوا Stateless (challenge_token بدل session) — شوف
 * الملحوظة في TwoFactorChallengeController.php لتفاصيل الفرق عن العقد الأصلي.
 *
 * بند 2 (Infra): موديول /v1/files (5 endpoint) — شوف FILES_API_CONTRACT.md.
 * CORS/RateLimit/SecurityHeaders مسجّلين Global في bootstrap/app.php (مش
 * هنا) عشان يغطوا كل الريكوستس بما فيها اللي مالهاش route اتسجل (زي
 * OPTIONS preflight) — شوف README قسم "تسجيل الـ Middleware".
 */
Route::prefix('v1')->group(function () {
    // عام ومستثنى من UipDeviceRestrictionMiddleware — الفرونت بيسأل بيه لو الجهاز محظور
    // عشان يعرض صفحة الحظر بدل ما يفتح المنصة.
    Route::get('/device-status', [\App\Http\Controllers\Api\DeviceStatusController::class, 'show']);

    // Negotiates the per-client response-encryption key (UipResponseEncryptionMiddleware).
    Route::post('/secure/handshake', [\App\Http\Controllers\Api\SecureHandshakeController::class, 'submit'])->middleware('throttle:60,1');
    Route::get('/secure/selftest', [\App\Http\Controllers\Api\SecureHandshakeController::class, 'selftest'])->middleware('throttle:20,1');
    Route::get('/app-config', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'appConfig'])->middleware('throttle:30,1');
    // App-side failure reports (uploads that died on the phone) → shown in Admin → Mobile → token → Request log.
    Route::post('/app/client-report', [\App\Http\Controllers\Api\AppClientReportController::class, 'store'])->middleware(['uip.auth.optional', 'throttle:30,1']);
    Route::get('/platform/status', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'publicStatus'])->middleware('throttle:60,1');

    Route::prefix('auth')->group(function () {
        // بدون Middleware — يطابق القديم بالظبط
        Route::get('/staff-gate', [\App\Http\Controllers\Api\Auth\StaffGateController::class, 'status'])->middleware('throttle:60,1');
        Route::post('/staff-gate', [\App\Http\Controllers\Api\Auth\StaffGateController::class, 'submit'])->middleware('throttle:30,1');
        Route::post('/login', [LoginController::class, 'submit'])->middleware('throttle:20,1');
        Route::post('/register', [RegisterController::class, 'submit'])->middleware('throttle:10,1');
        // Sign in / sign up with Google (ويب + أندرويد).
        Route::get('/google/config', [\App\Http\Controllers\Api\Auth\GoogleAuthController::class, 'config']);
        Route::post('/google', [\App\Http\Controllers\Api\Auth\GoogleAuthController::class, 'submit'])->middleware('throttle:20,1');
        Route::post('/google/complete', [\App\Http\Controllers\Api\Auth\GoogleAuthController::class, 'complete'])->middleware('throttle:10,1');
        Route::post('/google/restore', [\App\Http\Controllers\Api\Auth\GoogleAuthController::class, 'restoreDeleted'])->middleware('throttle:10,1');
        Route::post('/refresh-token', [RefreshTokenController::class, 'submit'])->middleware('throttle:60,1');
        Route::post('/logout', [LogoutController::class, 'handle']);
        Route::post('/forgot-password', [ForgotPasswordController::class, 'submit'])->middleware('throttle:10,1');
        Route::post('/reset-password', [ResetPasswordController::class, 'submit'])->middleware('throttle:10,1');
        Route::post('/reset-password/verify', [ResetPasswordController::class, 'verify'])->middleware('throttle:10,1');
        Route::post('/verify-email', [VerifyEmailController::class, 'submit'])->middleware('throttle:10,1');
        Route::get('/verify-email', [VerifyEmailController::class, 'handle']); // legacy links only
        Route::post('/resend-verification', [\App\Http\Controllers\Api\Auth\ResendVerificationController::class, 'submit'])->middleware('throttle:5,1');
        // تفعيل طوارئ لما لينك التأكيد مش بيوصل (متحكّم فيه بـ EMAIL_ACTIVATION_FALLBACK).
        Route::post('/activate-without-email', [\App\Http\Controllers\Api\Auth\ActivateWithoutEmailController::class, 'submit'])->middleware('throttle:10,1');
        Route::get('/confirm-email-change', [ConfirmEmailChangeController::class, 'handle']);
        Route::get('/roles', [RoleSelectionController::class, 'index']);
        Route::get('/universities', [UniversitiesController::class, 'index']);
        Route::get('/university-hierarchy/{id}', [UniversitiesController::class, 'hierarchy']);

        // استرجاع حساب محذوف / دمج بياناته بإثبات ملكية الإيميل (OTP).
        Route::post('/account-recovery/check', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'check'])->middleware('throttle:20,1');
        Route::post('/account-recovery/send', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'send'])->middleware('throttle:6,1');
        Route::post('/account-recovery/restore', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'restore'])->middleware('throttle:12,1');

        // محتاج Bearer token — يطابق AuthMiddleware القديمة (الجزء الخاص بالـ API بس)
        Route::middleware('uip.auth')->group(function () {
            Route::get('/permissions', [\App\Http\Controllers\Api\MyPermissionsApiController::class, 'show']);
            // Joker accounts only (controller enforces it): act as another role they hold.
            Route::post('/switch-role', [\App\Http\Controllers\Api\Auth\SwitchRoleController::class, 'submit'])->middleware('throttle:30,1');
            // سياسة MFA Requirements — تفعيل 2FA الإجباري (مفتوحة حتى للحساب المقيّد).
            Route::get('/mfa/status', [\App\Http\Controllers\Api\Auth\MfaEnrollmentController::class, 'status']);
            Route::post('/mfa/setup', [\App\Http\Controllers\Api\Auth\MfaEnrollmentController::class, 'setup'])->middleware('throttle:20,1');
            Route::post('/mfa/confirm', [\App\Http\Controllers\Api\Auth\MfaEnrollmentController::class, 'confirm'])->middleware('throttle:10,1');
            Route::get('/account-recovery/merge/candidates', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'mergeCandidates']);
            Route::post('/account-recovery/merge/send', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'mergeSend'])->middleware('throttle:6,1');
            Route::post('/account-recovery/merge/confirm', [\App\Http\Controllers\Api\Auth\AccountRecoveryController::class, 'mergeConfirm'])->middleware('throttle:12,1');
            Route::get('/email-change', [RequestEmailChangeController::class, 'show']);
            Route::post('/email-change', [RequestEmailChangeController::class, 'submit'])->middleware('throttle:5,1');
            Route::delete('/email-change', [RequestEmailChangeController::class, 'cancel'])->middleware('throttle:10,1');
            Route::get('/sessions', [SessionsController::class, 'index']);
            Route::delete('/sessions/{id}', [SessionsController::class, 'revoke']);
        });

        // Stateless (challenge_token) — بدون Middleware، زي القديم بالظبط
        Route::post('/two-factor/verify', [TwoFactorChallengeController::class, 'submit'])->middleware('throttle:10,1');
        Route::post('/two-factor/cancel', [TwoFactorChallengeController::class, 'cancel'])->middleware('throttle:20,1');

        // "الحساب مفتوح في مكان تاني وده مش أنا": OTP على الإيميل ← كلمة سر جديدة ← طرد الجلسات التانية.
        Route::post('/session-takeover/release', [\App\Http\Controllers\Api\Auth\SessionTakeoverController::class, 'release'])->middleware('throttle:20,1');
        Route::post('/session-takeover/send', [\App\Http\Controllers\Api\Auth\SessionTakeoverController::class, 'send'])->middleware('throttle:6,1');
        Route::post('/session-takeover/verify', [\App\Http\Controllers\Api\Auth\SessionTakeoverController::class, 'verify'])->middleware('throttle:12,1');
    });

    // جلسة واحدة نشطة لكل حساب — نبضة + تحرير عند قفل المتصفح (SessionsController).
    Route::middleware('uip.auth')->prefix('session')->group(function () {
        Route::get('/heartbeat', [SessionsController::class, 'heartbeat']);
        Route::post('/release', [SessionsController::class, 'release']);
    });

    // -- Generic Files / Uploads (بند 2 — Infra) -------------------------
    // يطابق app/Controllers/Api/FilesApiController.php القديمة. AuthMiddleware
    // القديمة بقت uip.auth؛ CSRFMiddleware القديمة (على POST/DELETE) مش
    // منقولة عمدًا — الـ API ده Bearer-JWT بس، مفيش cookies يتحمى منها CSRF
    // أصلًا (نفس القرار اللي اتاخد في كل موديول Auth ببند 1).
    // ترجمة الفيديو التلقائية (تفريغ Whisper + ترجمة) — مشغّل الفيديو بيبعت قطع صوت قصيرة. throttle: قطعة كل ~4 دقايق فيديو.
    Route::prefix('subtitles/auto')->middleware(['uip.auth', 'throttle:40,1'])->group(function () {
        Route::get('/status', [\App\Http\Controllers\Api\SubtitlesApiController::class, 'status']);
        Route::post('/', [\App\Http\Controllers\Api\SubtitlesApiController::class, 'generate']);
    });

    // "جهازي" (My PC) — نظام ملفات شخصي معزول لكل طالب. راجع docblock الـ DriveApiController.
    // الروتس الثابتة (tree/search/trash/...) قبل nodes/{id} عشان Laravel ميطابقهاش كـ {id}.
    Route::prefix('drive')->middleware('uip.auth')->group(function () {
        Route::get('/', [DriveApiController::class, 'index']);
        Route::get('/tree', [DriveApiController::class, 'tree']);
        Route::get('/search', [DriveApiController::class, 'search']);
        Route::get('/storage', [DriveApiController::class, 'storage'])->middleware('throttle:30,1');
        Route::delete('/versions', [DriveApiController::class, 'clearVersions'])->middleware('throttle:10,1');
        Route::get('/resolve', [DriveApiController::class, 'resolve'])->middleware('throttle:120,1');
        Route::get('/search-content', [DriveApiController::class, 'searchContent'])->middleware('throttle:40,1');
        Route::get('/usage', [DriveApiController::class, 'usage']);
        Route::get('/disks', [DriveApiController::class, 'disks']);
        Route::post('/disks', [DriveApiController::class, 'createDisk'])->middleware('throttle:60,1');
        Route::post('/nodes/{id}/disk', [DriveApiController::class, 'setDisk'])->where('id', '[0-9]+')->middleware('throttle:60,1');
        Route::get('/templates', [DriveApiController::class, 'templates']);
        Route::get('/config', [DriveApiController::class, 'config']);
        Route::get('/manifest', [DriveApiController::class, 'manifest'])->middleware('throttle:60,1');
        Route::post('/upload/init', [DriveApiController::class, 'uploadInit'])->middleware('throttle:240,1');
        Route::post('/upload/complete', [DriveApiController::class, 'uploadComplete'])->middleware('throttle:240,1');
        Route::post('/folders', [DriveApiController::class, 'createFolder'])->middleware('throttle:120,1');
        Route::post('/files', [DriveApiController::class, 'createFile'])->middleware('throttle:120,1');
        Route::post('/projects', [DriveApiController::class, 'createProject'])->middleware('throttle:30,1');
        Route::post('/upload', [DriveApiController::class, 'upload'])->middleware('throttle:120,1');
        Route::post('/move', [DriveApiController::class, 'move']);
        Route::post('/copy', [DriveApiController::class, 'copy'])->middleware('throttle:30,1');
        Route::post('/zip', [DriveApiController::class, 'zip'])->middleware('throttle:20,1');
        Route::get('/archive-all', [DriveApiController::class, 'archiveAll'])->middleware('throttle:3,1');
        Route::post('/trash', [DriveApiController::class, 'trash']);
        Route::post('/restore', [DriveApiController::class, 'restore']);
        Route::post('/purge', [DriveApiController::class, 'purge']);
        Route::get('/trash', [DriveApiController::class, 'trashList']);
        Route::delete('/trash', [DriveApiController::class, 'emptyTrash']);
        // مشاركة برابط (راجع DriveShareApiController)
        Route::get('/shares', [DriveShareApiController::class, 'index']);
        Route::get('/nodes/{id}/shares', [DriveShareApiController::class, 'forNode'])->where('id', '[0-9]+');
        Route::post('/nodes/{id}/shares', [DriveShareApiController::class, 'create'])->where('id', '[0-9]+')->middleware('throttle:60,1');
        Route::patch('/shares/{id}', [DriveShareApiController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/shares/{id}', [DriveShareApiController::class, 'revoke'])->where('id', '[0-9]+');
        Route::get('/nodes/{id}/content', [DriveApiController::class, 'content']);
        Route::put('/nodes/{id}/content', [DriveApiController::class, 'saveContent'])->middleware('throttle:120,1');
        Route::post('/nodes/{id}/replace', [DriveApiController::class, 'replace'])->middleware('throttle:60,1');
        // سجل الإصدارات: النسخ القديمة لملف (قبل كل حفظ فوقه) + معاينة / استرجاع / حذف
        Route::get('/nodes/{id}/versions', [DriveApiController::class, 'versions'])->where('id', '[0-9]+');
        Route::get('/nodes/{id}/versions/{vid}/content', [DriveApiController::class, 'versionContent'])->where(['id' => '[0-9]+', 'vid' => '[0-9]+'])->middleware('throttle:240,1');
        Route::post('/nodes/{id}/versions/{vid}/restore', [DriveApiController::class, 'restoreVersion'])->where(['id' => '[0-9]+', 'vid' => '[0-9]+'])->middleware('throttle:60,1');
        Route::delete('/nodes/{id}/versions/{vid}', [DriveApiController::class, 'deleteVersion'])->where(['id' => '[0-9]+', 'vid' => '[0-9]+'])->middleware('throttle:60,1');
        Route::post('/nodes/{id}/replace/init', [DriveApiController::class, 'replaceInit'])->middleware('throttle:120,1');
        Route::post('/nodes/{id}/replace/complete', [DriveApiController::class, 'replaceComplete'])->middleware('throttle:120,1');
        Route::get('/nodes/{id}/download-url', [DriveApiController::class, 'downloadUrl'])->middleware('throttle:240,1');
        Route::patch('/nodes/{id}', [DriveApiController::class, 'rename']);
        Route::get('/nodes/{id}/archive', [DriveApiController::class, 'archive'])->middleware('throttle:20,1');
        Route::post('/nodes/{id}/unzip', [DriveApiController::class, 'unzip'])->middleware('throttle:20,1');
        Route::post('/nodes/{id}/sync', [DriveApiController::class, 'sync'])->middleware('throttle:30,1');
        Route::post('/terminal-token', [DriveApiController::class, 'terminalToken'])->middleware('throttle:30,1');
        Route::post('/nodes/{id}/sandbox-token', [DriveApiController::class, 'sandboxToken'])->middleware('throttle:30,1');
    });

    Route::prefix('files')->middleware('uip.auth')->group(function () {
        Route::get('/', [FilesApiController::class, 'index']);
        Route::post('/', [FilesApiController::class, 'store']);
        Route::get('/{id}', [FilesApiController::class, 'show']);
        Route::get('/{id}/download', [FilesApiController::class, 'download']);
        Route::delete('/{id}', [FilesApiController::class, 'delete']);
    });

    // -- Universities + Faculty (بند 3 — بيانات مرجعية) -------------------
    // يطابق UniversitiesApiController/FacultyApiController القديمين.
    // كل الروتس هنا محتاجة uip.auth (زي القديم بالظبط — AuthMiddleware
    // كانت بتغطي الجروب كله)، ما عدا publicIndex/publicShow (دليل عام،
    // بدون role check جوه الكنترولر نفسه، لكن برضو محتاجة تسجيل دخول
    // بما إن مفيش route عام خارج uip.auth في القديم لموديول ده تحديدًا —
    // شوف الملحوظة في UNIVERSITIES_FACULTY_API_CONTRACT.md لو محتاج
    // تفتحها بدون توكن).
    Route::prefix('universities')->middleware('uip.auth')->group(function () {
        Route::get('/', [UniversitiesApiController::class, 'index']);

        Route::get('/me', [UniversitiesApiController::class, 'me']);
        Route::patch('/me', [UniversitiesApiController::class, 'updateMe']);
        Route::post('/me/avatar', [UniversitiesApiController::class, 'uploadAvatar']);
        Route::post('/me/logo', [UniversitiesApiController::class, 'uploadLogo']);
        Route::post('/me/branding/{kind}', [UniversitiesApiController::class, 'uploadBranding'])->where('kind', 'signature|stamp|dean_signature');
        Route::delete('/me/branding/{kind}', [UniversitiesApiController::class, 'deleteBranding'])->where('kind', 'signature|stamp|dean_signature');
        Route::patch('/me/dean', [UniversitiesApiController::class, 'updateDean']);
        Route::patch('/me/preferences', [UniversitiesApiController::class, 'updatePreferences']);
        Route::patch('/me/notification-preferences', [UniversitiesApiController::class, 'updateNotificationPreferences']);
        Route::patch('/me/visibility', [UniversitiesApiController::class, 'updateVisibility']);

        Route::get('/{id}', [UniversitiesApiController::class, 'show']);
        Route::get('/{id}/innovation-hub', [UniversitiesApiController::class, 'innovationHub']);

        Route::middleware('uip.admin')->group(function () {
            Route::post('/{id}/verify', [UniversitiesApiController::class, 'verify']);
            Route::post('/{id}/reject', [UniversitiesApiController::class, 'reject']);
            Route::patch('/{id}/reverification', [UniversitiesApiController::class, 'updateReverification']);
            Route::delete('/{id}', [UniversitiesApiController::class, 'destroy']);
        });
    });

    // بند 10 — Academic Staff + Faculty portal، جزء 1: طابور اعتماد المشاريع
    // الخاص بالكلية (توأم university/approvals فوق). لازم يتسجل قبل
    // مجموعة prefix('faculty') تحت — 'faculty/approvals' و'faculty/{id}'
    // بيتطابقوا مع نفس الـ path لو 'approvals' اتحل كـ {id}، والراوتر بياخد
    // أول تطابق بالترتيب، فالمجموعة الأخص لازم تسبق الأعم.
    Route::prefix('project-deadlines')->middleware('uip.auth')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ProjectDeadlinesApiController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ProjectDeadlinesApiController::class, 'store']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\ProjectDeadlinesApiController::class, 'destroy']);
    });

    // تقرير مراجعة الكود لمشروع — لكل أطراف المشروع (طالب/دكتور/معيد/مشرف/كلية/جامعة/أدمن)؛
    // الصلاحية في CodeReviewReportService::resolveProject (نفس نطاق صفحات كل دور).
    Route::prefix('code-review-report')->middleware('uip.auth')->group(function () {
        Route::get('/{uuid}', [\App\Http\Controllers\Api\CodeReviewReportApiController::class, 'show']);
        Route::get('/{uuid}/export', [\App\Http\Controllers\Api\CodeReviewReportApiController::class, 'export']);
    });

    Route::prefix('faculty/approvals')->middleware('uip.auth')->group(function () {
        Route::get('/', [FacultyApprovalsApiController::class, 'index']);
        Route::get('/{id}', [FacultyApprovalsApiController::class, 'show']);
        Route::post('/{id}/approve', [FacultyApprovalsApiController::class, 'approve']);
        Route::post('/{id}/reject', [FacultyApprovalsApiController::class, 'reject']);
        Route::post('/{id}/request-changes', [FacultyApprovalsApiController::class, 'requestChanges']);
        Route::post('/{id}/grade', [FacultyApprovalsApiController::class, 'saveGrade']);
        Route::post('/{id}/grade/reopen', [FacultyApprovalsApiController::class, 'reopenGrade']);
    });

    // بند 10 — Academic Staff + Faculty portal، جزء 2: إعدادات حساب لوجين
    // الكلية نفسه (بروفايل/لوجو/ثيم/باسورد/2FA/تفضيلات إشعارات) — بيطابق
    // api/v1/faculty/settings/* القديمة. نفس ملحوظة ترتيب التسجيل فوق.
    Route::prefix('faculty/settings')->middleware('uip.auth')->group(function () {
        Route::get('/', [FacultySettingsApiController::class, 'index']);
        Route::patch('/notifications', [FacultySettingsApiController::class, 'updateNotificationPreferences']);
        Route::patch('/profile', [FacultySettingsApiController::class, 'updateProfile']);
        Route::post('/logo', [FacultySettingsApiController::class, 'uploadLogo']);
        Route::post('/theme/toggle', [FacultySettingsApiController::class, 'toggleTheme']);
        Route::patch('/password', [FacultySettingsApiController::class, 'updatePassword']);
        Route::post('/2fa/setup', [FacultySettingsApiController::class, 'twoFactorSetup']);
        Route::post('/2fa/confirm', [FacultySettingsApiController::class, 'twoFactorConfirm']);
        Route::post('/2fa/disable', [FacultySettingsApiController::class, 'twoFactorDisable']);
    });

    // بند 10 — Academic Staff + Faculty portal، جزء 3: روستر أعضاء هيئة
    // التدريس (جامعة + كلية معًا على نفس الجدول، انظر docblock الكنترولر) +
    // رتب أكاديمية. /me و/ranks لازم يتسجلوا قبل /{id} زي كل باقي البنود.
    Route::prefix('academic-staff')->middleware('uip.auth')->group(function () {
        Route::get('/', [AcademicStaffApiController::class, 'index']);
        Route::post('/', [AcademicStaffApiController::class, 'store']);
        Route::post('/import', [AcademicStaffApiController::class, 'import']);

        Route::get('/search', [\App\Http\Controllers\Api\AcademicStaffSearchApiController::class, 'index'])->middleware('uip.staff_alias');
        Route::get('/me', [AcademicStaffApiController::class, 'me'])->middleware('uip.staff_alias');
        Route::post('/me/avatar', [AcademicStaffApiController::class, 'uploadAvatar'])->middleware('uip.staff_alias');
        Route::get('/ranks', [AcademicStaffApiController::class, 'ranks']);
        Route::post('/ranks', [AcademicStaffApiController::class, 'storeRank']);

        Route::get('/{id}', [AcademicStaffApiController::class, 'show'])->where('id', '[0-9]+');
        Route::patch('/{id}', [AcademicStaffApiController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/{id}', [AcademicStaffApiController::class, 'destroy'])->where('id', '[0-9]+');
        Route::post('/{id}/activate', [AcademicStaffApiController::class, 'activate'])->where('id', '[0-9]+');
        Route::post('/{id}/deactivate', [AcademicStaffApiController::class, 'deactivate'])->where('id', '[0-9]+');
        Route::post('/{id}/resend', [AcademicStaffApiController::class, 'resend'])->where('id', '[0-9]+');
        Route::get('/{id}/password', [AcademicStaffApiController::class, 'revealPassword'])->where('id', '[0-9]+');
        Route::patch('/{id}/password', [AcademicStaffApiController::class, 'setPassword'])->where('id', '[0-9]+');
    });

    // مشاريع الطلبة المرتبطة بالدكتور/المعيد (اطلاع + approve/reject/request-changes + تقييم).
    Route::prefix('academic-staff/projects')->middleware('uip.auth')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'show']);
        Route::post('/{id}/approve', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'approve']);
        Route::post('/{id}/reject', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'reject']);
        Route::post('/{id}/request-changes', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'requestChanges']);
        Route::post('/{id}/grade', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'saveGrade']);
        Route::post('/{id}/grade/reopen', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'reopenGrade']);
        Route::patch('/{id}/ai-mode', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'setAiMode']);
        Route::get('/{id}/discussion', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'discussion']);
        Route::post('/{id}/discussion', [\App\Http\Controllers\Api\AcademicStaffProjectsApiController::class, 'postNote']);
    });

    // إعدادات حساب عضو هيئة التدريس اللوجن — /api/v1/academic-staff/settings/*.
    // كان ده الـ ACCOUNT_ITEM_OVERRIDES.academic_staff (settings: {built:false})
    // في navConfig.js؛ نفس نمط student/settings و faculty/settings فوق
    // بالظبط. لازم يتسجل بعد الجروب فوق (academic-staff/{id} العام) عشان
    // "settings" ماتتفسرش كـ id.
    Route::prefix('academic-staff/settings')->middleware('uip.auth')->group(function () {
        Route::get('/', [AcademicStaffSettingsApiController::class, 'index']);
        Route::patch('/profile', [AcademicStaffSettingsApiController::class, 'updateProfile']);
        Route::patch('/preferences', [AcademicStaffSettingsApiController::class, 'updatePreferences']);
        Route::patch('/notifications', [AcademicStaffSettingsApiController::class, 'updateNotificationPreferences']);
        Route::patch('/password', [AcademicStaffSettingsApiController::class, 'updatePassword']);
        Route::post('/2fa/setup', [AcademicStaffSettingsApiController::class, 'twoFactorSetup']);
        Route::post('/2fa/confirm', [AcademicStaffSettingsApiController::class, 'twoFactorConfirm']);
        Route::post('/2fa/disable', [AcademicStaffSettingsApiController::class, 'twoFactorDisable']);
    });

    // Exam & Assessment System — Round 1 (Foundation) بس: بنوك أسئلة،
    // أسئلة (mcq/true_false)، امتحانات draft، وربط سؤال بامتحان.
    // targeting/attempts/grading/security-events لسه Rounds 2-5 (راجع
    // docblock ExamSystemApiController). question-banks/{bankId}/questions
    // لازم يتسجل قبل questions/{id} العام — نفس ترتيب الـ nested routes
    // في باقي البنود.
    Route::prefix('exam-system')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        Route::get('question-banks', [ExamSystemApiController::class, 'indexBanks']);
        Route::post('question-banks', [ExamSystemApiController::class, 'storeBank']);
        Route::get('question-banks/{id}', [ExamSystemApiController::class, 'showBank'])->where('id', '[0-9]+');
        Route::patch('question-banks/{id}', [ExamSystemApiController::class, 'updateBank'])->where('id', '[0-9]+');
        Route::delete('question-banks/{id}', [ExamSystemApiController::class, 'destroyBank'])->where('id', '[0-9]+');

        Route::post('question-banks/{bankId}/questions', [ExamSystemApiController::class, 'storeQuestion'])->where('bankId', '[0-9]+');
        // Round 9 UX follow-up — AI fallback for the bulk "Add Questions" box
        // when the client-side regex parser can't make sense of a paste.
        // See ExamSystemApiController::parseQuestionsWithAi() docblock.
        Route::post('question-banks/{bankId}/questions/parse-ai', [ExamSystemApiController::class, 'parseQuestionsWithAi'])->where('bankId', '[0-9]+');
        Route::post('question-banks/{bankId}/questions/ai-edit', [ExamSystemApiController::class, 'reviseQuestionsWithAi'])->where('bankId', '[0-9]+');
        Route::post('questions/generate-model-answer', [ExamSystemApiController::class, 'generateModelAnswer']);
        Route::patch('questions/{id}', [ExamSystemApiController::class, 'updateQuestion'])->where('id', '[0-9]+');
        Route::delete('questions/{id}', [ExamSystemApiController::class, 'destroyQuestion'])->where('id', '[0-9]+');
        Route::post('questions/{id}/image', [ExamSystemApiController::class, 'uploadQuestionImage'])->where('id', '[0-9]+');
        Route::delete('questions/{id}/image', [ExamSystemApiController::class, 'removeQuestionImage'])->where('id', '[0-9]+');
        Route::get('questions/{id}/versions', [ExamSystemApiController::class, 'showQuestionVersions'])->where('id', '[0-9]+');

        // Round 6 — Rubrics (Phase 19). rubric واحد بالظبط لكل سؤال —
        // نفس منطق question-banks/{bankId}/questions فوق (نسبي لـ question
        // parent)، هنا الـ show/save/destroy كلهم تحت questions/{id}/rubric.
        Route::get('questions/{questionId}/rubric', [ExamSystemApiController::class, 'showRubric'])->where('questionId', '[0-9]+');
        Route::put('questions/{questionId}/rubric', [ExamSystemApiController::class, 'saveRubric'])->where('questionId', '[0-9]+');
        Route::delete('questions/{questionId}/rubric', [ExamSystemApiController::class, 'destroyRubric'])->where('questionId', '[0-9]+');

        // Round 7 — Question Pools (Phase 6). question-banks/{bankId}/pools
        // نفس نمط question-banks/{bankId}/questions فوق بالظبط (nested تحت
        // البنك للـ index/store، بعدين pools/{id} مستقلة). pools/{id}/questions
        // هي استبدال العضوية الكامل (زي questions/{questionId}/rubric فوق —
        // مفيش partial add/remove).
        Route::get('question-banks/{bankId}/pools', [ExamSystemApiController::class, 'indexPools'])->where('bankId', '[0-9]+');
        Route::post('question-banks/{bankId}/pools', [ExamSystemApiController::class, 'storePool'])->where('bankId', '[0-9]+');
        Route::get('pools/{id}', [ExamSystemApiController::class, 'showPool'])->where('id', '[0-9]+');
        Route::patch('pools/{id}', [ExamSystemApiController::class, 'updatePool'])->where('id', '[0-9]+');
        Route::delete('pools/{id}', [ExamSystemApiController::class, 'destroyPool'])->where('id', '[0-9]+');
        Route::put('pools/{id}/questions', [ExamSystemApiController::class, 'syncPoolQuestions'])->where('id', '[0-9]+');

        Route::get('exams', [ExamSystemApiController::class, 'indexExams']);
        Route::post('exams', [ExamSystemApiController::class, 'storeExam']);
        Route::get('exams/{id}', [ExamSystemApiController::class, 'showExam'])->where('id', '[0-9]+');
        Route::patch('exams/{id}', [ExamSystemApiController::class, 'updateExam'])->where('id', '[0-9]+');
        Route::delete('exams/{id}', [ExamSystemApiController::class, 'destroyExam'])->where('id', '[0-9]+');

        Route::post('exams/{id}/questions', [ExamSystemApiController::class, 'addExamQuestion'])->where('id', '[0-9]+');
        Route::post('exams/{id}/questions/bulk-remove', [ExamSystemApiController::class, 'bulkRemoveExamQuestions'])->where('id', '[0-9]+');
        Route::patch('exams/{id}/questions/reorder', [ExamSystemApiController::class, 'reorderExamQuestions'])->where('id', '[0-9]+');
        Route::patch('exams/{id}/questions/{examQuestionId}', [ExamSystemApiController::class, 'updateExamQuestionMarks'])->where('id', '[0-9]+')->where('examQuestionId', '[0-9]+');
        Route::put('exams/{id}/marks', [ExamSystemApiController::class, 'distributeExamMarks'])->where('id', '[0-9]+');
        Route::delete('exams/{id}/questions/{examQuestionId}', [ExamSystemApiController::class, 'removeExamQuestion'])->where('id', '[0-9]+')->where('examQuestionId', '[0-9]+');

        // Round 7 — Question Pool configs on an exam (Phases 6-7). زي
        // exams/{id}/questions فوق بالظبط بس لـ pools — attach/update/detach،
        // مفيش reorder مستقل هنا (sort_order بتتحدد أوتوماتيك وقت attach).
        Route::get('exams/{id}/pools', [ExamSystemApiController::class, 'indexExamPools'])->where('id', '[0-9]+');
        Route::post('exams/{id}/pools', [ExamSystemApiController::class, 'attachExamPool'])->where('id', '[0-9]+');
        Route::patch('exams/{id}/pools/{configId}', [ExamSystemApiController::class, 'updateExamPool'])->where('id', '[0-9]+')->where('configId', '[0-9]+');
        Route::delete('exams/{id}/pools/{configId}', [ExamSystemApiController::class, 'detachExamPool'])->where('id', '[0-9]+')->where('configId', '[0-9]+');

        // Round 2 — Targeting (Phase 8). /my-exams (الطالب) لازم يتسجل
        // قبل /exams/{id} فوق مايتلخبطش... مش لازم فعليًا لأن my-exams مش
        // تحت exams/، بس متسجلة هنا برضه عشان تفضل كل exam-system/* سطح
        // واحد. publish/unpublish وtargets كلها تحت exams/{id} الموجودة.
        // Round 2 UX follow-up — typeahead بحث طالب + قايمة مجموعات
        // للفورم (targeting/students, targeting/groups)، segments ثابتة
        // فلازم تتسجل قبل exams/{id} فوق (نفس منطق my-exams تحت) ولو
        // مفيش تعارض فعلي أصلًا لاختلاف الاسم.
        Route::get('targeting/students', [ExamSystemApiController::class, 'searchTargetStudents']);
        Route::get('targeting/groups', [ExamSystemApiController::class, 'listTargetGroups']);

        Route::get('exams/{id}/targets', [ExamSystemApiController::class, 'showExamTargets'])->where('id', '[0-9]+');
        Route::put('exams/{id}/targets', [ExamSystemApiController::class, 'replaceExamTargets'])->where('id', '[0-9]+');
        Route::post('exams/{id}/targets/preview', [ExamSystemApiController::class, 'previewExamTargets'])->where('id', '[0-9]+');
        Route::get('exams/{id}/targets/students', [ExamSystemApiController::class, 'listExamTargetStudents'])->where('id', '[0-9]+');
        Route::post('exams/{id}/publish', [ExamSystemApiController::class, 'publishExam'])->where('id', '[0-9]+');
        Route::post('exams/{id}/unpublish', [ExamSystemApiController::class, 'unpublishExam'])->where('id', '[0-9]+');

        // Round 2 — الطالب: "امتحاناتي". /my-exams لازم يتسجل هنا (تحت
        // نفس الـ exam-system prefix) قبل ما أي route عام زي exams/{id}
        // يحاول يطابقه غلط — مش فعليًا ملزم هنا لأن الـ prefix مختلف
        // (my-exams مش exams)، بس متسيب في نفس المكان عشان القراءة.
        Route::get('my-exams', [StudentExamApiController::class, 'index']);
        Route::get('my-exams/{id}', [StudentExamApiController::class, 'show'])->where('id', '[0-9]+');

        // Round 3 — Attempts + Timer + Auto-save (Phases 10-12). my-exams/{id}/attempts
        // (start + history) عمدًا تحت my-exams مش exams/ — نفس منطق my-exams
        // الطالب فوق، ده سطح طالب مش مدرس. attempts/{id}/* بعد كده مستقلة
        // عن exam id (المحاولة نفسها هي المرجع، زي StudentExamApiController
        // بيتحقق من الملكية عبر ExamAttemptRepository::findOwned() مش exam).
        Route::get('active-attempt', [ExamAttemptApiController::class, 'activeAttempt']);
        Route::get('my-exams/{id}/attempts', [ExamAttemptApiController::class, 'indexForExam'])->where('id', '[0-9]+');
        Route::post('my-exams/{id}/attempts', [ExamAttemptApiController::class, 'start'])->where('id', '[0-9]+');

        Route::get('attempts/{id}', [ExamAttemptApiController::class, 'show'])->where('id', '[0-9]+');
        Route::put('attempts/{id}/answers/{examQuestionId}', [ExamAttemptApiController::class, 'saveAnswer'])->where('id', '[0-9]+')->where('examQuestionId', '[0-9]+');
        Route::delete('attempts/{id}/answers/{examQuestionId}', [ExamAttemptApiController::class, 'deleteAnswer'])->where('id', '[0-9]+')->where('examQuestionId', '[0-9]+');
        Route::post('attempts/{id}/submit', [ExamAttemptApiController::class, 'submit'])->where('id', '[0-9]+');
        // جلسة واحدة + كاميرا: heartbeat بيثبّت الجلسة الحية، snapshots لقطات الكاميرا الدورية (multipart).
        Route::post('attempts/{id}/heartbeat', [ExamAttemptApiController::class, 'heartbeat'])->where('id', '[0-9]+');
        Route::post('attempts/{id}/snapshots', [ExamAttemptApiController::class, 'uploadSnapshot'])->where('id', '[0-9]+');

        // Round 4 — Grading Core (Phases 17/22/24). النتيجة للطالب —
        // /result مش /show تاني عشان الفصل واضح: attemptDetail() (Round 3)
        // عمرها ما بتكشف درجة/is_correct، studentResult() (هنا) هي الوحيدة
        // اللي بتكشفهم، ومحكومة بالكامل بـ ExamGradingService::isResultVisibleTo().
        Route::get('attempts/{id}/result', [ExamAttemptApiController::class, 'result'])->where('id', '[0-9]+');

        // Round 5 — Secure Exam Mode + Security Events (Phases 13-16).
        // الطالب بيبعت كل حدث أمان لحظيًا وقت المحاولة (fullscreen/tab/
        // copy-paste/...) — راجع ExamSecurityService::CLIENT_EVENTS
        // للقايمة المسموحة. threshold_exceeded (تخطى max_violations)
        // بيترجم auto-submit فوري في نفس الـ response.
        Route::post('attempts/{id}/security-events', [ExamSecurityApiController::class, 'recordEvent'])->where('id', '[0-9]+');

        // Round 4 — سطح المدرس: مراجعة/تصحيح محاولات الطلاب على امتحان
        // بعينه. كله تحت exams/{id}/attempts عمدًا (مش attempts/{id} عام
        // زي فوق) — هنا الملكية بتتفحص على الامتحان الأول (ExamSystemService::
        // findOwnedExam)، وبعدين إن المحاولة فعلاً تابعة له (راجع docblock
        // ExamGradingApiController::resolveAttemptForExam).
        Route::get('exams/{id}/attempts', [ExamGradingApiController::class, 'indexAttempts'])->where('id', '[0-9]+');
        Route::get('exams/{id}/attempts/{attemptId}', [ExamGradingApiController::class, 'showAttempt'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        // إدارة محاولات الطلاب (صلاحيات المدرس): إلغاء محاولة، وإعادة الامتحان لطالب معيّن.
        Route::post('exams/{id}/attempts/{attemptId}/cancel', [ExamGradingApiController::class, 'cancelAttempt'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        // وقت إضافي / إجراءات جماعية / بحث طالب غايب. المسارات الثابتة (bulk-*) قبل {attemptId} عمدًا،
        // وكل واحد بيتحقق من ملكية الامتحان قبل أي حاجة.
        Route::post('exams/{id}/attempts/bulk-cancel', [ExamGradingApiController::class, 'bulkCancel'])->where('id', '[0-9]+');
        Route::post('exams/{id}/attempts/bulk-extra-time', [ExamGradingApiController::class, 'bulkAddExtraTime'])->where('id', '[0-9]+');
        Route::post('exams/{id}/attempts/{attemptId}/extra-time', [ExamGradingApiController::class, 'addExtraTime'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        Route::post('exams/{id}/retake/bulk', [ExamGradingApiController::class, 'bulkGrantRetake'])->where('id', '[0-9]+');
        Route::get('exams/{id}/eligible-students', [ExamGradingApiController::class, 'searchEligibleStudents'])->where('id', '[0-9]+');
        Route::post('exams/{id}/students/{studentId}/retake', [ExamGradingApiController::class, 'grantRetake'])->where('id', '[0-9]+')->where('studentId', '[0-9]+');
        Route::delete('exams/{id}/students/{studentId}/retake', [ExamGradingApiController::class, 'revokeRetake'])->where('id', '[0-9]+')->where('studentId', '[0-9]+');
        Route::post('exams/{id}/attempts/{attemptId}/auto-grade', [ExamGradingApiController::class, 'autoGrade'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        Route::put('exams/{id}/attempts/{attemptId}/grades/{examQuestionId}', [ExamGradingApiController::class, 'gradeAnswer'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+')->where('examQuestionId', '[0-9]+');
        Route::get('exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/history', [ExamGradingApiController::class, 'gradeHistory'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+')->where('examQuestionId', '[0-9]+');

        // Round 6 — AI Grading (Phases 18/23). accept-ai بيحوّل درجة الـ
        // AI (source=ai) لـ source=instructor من غير تعديل الرقم؛ ai-regrade
        // بيعيد تشغيل الـ AI job من الأول لنفس السؤال (بعد تعديل model
        // answer/rubric مثلاً) — راجع docblock الميثودز في ExamGradingService.
        Route::post('exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/accept-ai', [ExamGradingApiController::class, 'acceptAiGrade'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+')->where('examQuestionId', '[0-9]+');
        Route::post('exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/ai-regrade', [ExamGradingApiController::class, 'aiRegrade'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+')->where('examQuestionId', '[0-9]+');

        // Round 5 — سطح المدرس بتاع التايم لاين (نفس منطق ملكية exams/{id}/
        // attempts/{attemptId} فوق بالظبط، راجع ExamSecurityApiController::timeline).
        Route::get('exams/{id}/attempts/{attemptId}/security-events', [ExamSecurityApiController::class, 'timeline'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');

        // نزاهة الامتحان (سطح المدرس): سجل الجلسات + لقطات الكاميرا + مراجعة الهوية + إعفاء طالب من الكاميرا.
        Route::get('exams/{id}/attempts/{attemptId}/integrity', [ExamIntegrityApiController::class, 'show'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        Route::get('exams/{id}/attempts/{attemptId}/snapshots/{snapshotId}', [ExamIntegrityApiController::class, 'snapshot'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+')->where('snapshotId', '[0-9]+');
        Route::post('exams/{id}/attempts/{attemptId}/identity-review', [ExamIntegrityApiController::class, 'reviewIdentity'])->where('id', '[0-9]+')->where('attemptId', '[0-9]+');
        Route::put('exams/{id}/students/{studentId}/proctoring-waiver', [ExamIntegrityApiController::class, 'setWaiver'])->where('id', '[0-9]+')->where('studentId', '[0-9]+');

        Route::post('exams/{id}/publish-results', [ExamGradingApiController::class, 'publishResults'])->where('id', '[0-9]+');
        Route::post('exams/{id}/unpublish-results', [ExamGradingApiController::class, 'unpublishResults'])->where('id', '[0-9]+');

        // Round 8 (Phases 25/26/27/28) — Analytics + Dashboards. لازم
        // تتسجل قبل exams/{id} العام مستحيلاش يتلخبط مع أي حاجة، بس هنا
        // كل الـ segments ثابتة (analytics/dashboard/faculty/university)
        // مش أرقام، فمفيش تعارض فعلي مع exams/{id} — مسجلة هنا بس عشان
        // تفضل مجمّعة مع باقي exam-system/* منطقيًا.
        Route::get('exams/{id}/analytics', [ExamAnalyticsApiController::class, 'examAnalytics'])->where('id', '[0-9]+');
        // بند 11 — تحليل جودة الأسئلة (صعوبة + قدرة تمييز + مشتتات). صاحب الامتحان/البنك بس.
        Route::get('exams/{id}/question-quality', [ExamQuestionQualityApiController::class, 'forExam'])->where('id', '[0-9]+');
        Route::get('question-banks/{id}/question-quality', [ExamQuestionQualityApiController::class, 'forBank'])->where('id', '[0-9]+');
        Route::get('dashboard/instructor', [ExamAnalyticsApiController::class, 'instructorDashboard']);
        Route::get('dashboard/student', [ExamAnalyticsApiController::class, 'studentDashboard']);
        Route::get('faculty/exams', [ExamAnalyticsApiController::class, 'facultyExams']);
        Route::get('faculty/dashboard', [ExamAnalyticsApiController::class, 'facultyDashboard']);
        Route::get('university/exams', [ExamAnalyticsApiController::class, 'universityExams']);
        Route::get('university/dashboard', [ExamAnalyticsApiController::class, 'universityDashboard']);

        // Round 8 (Phase 43 — Export). Results export لامتحان واحد؛
        // نفس ownership check بتاع باقي exams/{id}/* (findOwnedExam()).
        Route::get('exams/{id}/results/export', [ExamResultsExportApiController::class, 'exportExamResults'])->where('id', '[0-9]+');

        // نتائج عضو هيئة التدريس على مستوى كل امتحاناته (سايدبار: النتائج / نتائج الطلبة / التقارير).
        // مسارات ثابتة (results/*) فمفيش تعارض مع exams/{id}.
        Route::get('results/overview', [ExamResultsHubApiController::class, 'overview']);
        Route::get('results/students', [ExamResultsHubApiController::class, 'students']);
        Route::get('results/students/export', [ExamResultsHubApiController::class, 'exportStudents']);

        // مركز التصدير (Exports): تصديرات محفوظة + Preview + تنزيل + حذف.
        Route::get('exports', [ExamExportsApiController::class, 'index']);
        Route::post('exports', [ExamExportsApiController::class, 'store'])->middleware('throttle:30,1');
        Route::get('exports/{id}/preview', [ExamExportsApiController::class, 'preview'])->where('id', '[0-9]+');
        Route::get('exports/{id}/download', [ExamExportsApiController::class, 'download'])->where('id', '[0-9]+');
        Route::post('exports/bulk-delete', [ExamExportsApiController::class, 'bulkDestroy'])->middleware('throttle:30,1');
        Route::delete('exports/{id}', [ExamExportsApiController::class, 'destroy'])->where('id', '[0-9]+');

        // كشف التشابه بين إجابات المقالي (سطح المدرس، ownership بتاع findOwnedExam). إشارات للمراجعة البشرية بس.
        Route::get('exams/{id}/similarity', [ExamSimilarityApiController::class, 'index'])->where('id', '[0-9]+');
        Route::post('exams/{id}/similarity/analyze', [ExamSimilarityApiController::class, 'analyze'])->where('id', '[0-9]+')->middleware('throttle:6,1');
        Route::patch('exams/{id}/similarity/{flagId}', [ExamSimilarityApiController::class, 'review'])->where('id', '[0-9]+')->where('flagId', '[0-9]+');

        // تظلم الطالب على الدرجة. الطالب: my-appeals + attempts/{id}/appeals. المدرس: exams/{id}/appeals + appeal-settings.
        Route::get('my-appeals', [ExamAppealApiController::class, 'mine']);
        Route::get('attempts/{id}/appeals', [ExamAppealApiController::class, 'indexForAttempt'])->where('id', '[0-9]+');
        Route::post('attempts/{id}/appeals', [ExamAppealApiController::class, 'store'])->where('id', '[0-9]+')->middleware('throttle:20,1');
        Route::post('attempts/{id}/appeals/{appealId}/withdraw', [ExamAppealApiController::class, 'withdraw'])->where('id', '[0-9]+')->where('appealId', '[0-9]+');
        Route::get('exams/{id}/appeals', [ExamAppealApiController::class, 'index'])->where('id', '[0-9]+');
        Route::post('exams/{id}/appeals/{appealId}/resolve', [ExamAppealApiController::class, 'resolve'])->where('id', '[0-9]+')->where('appealId', '[0-9]+');
        Route::put('exams/{id}/appeal-settings', [ExamAppealApiController::class, 'settings'])->where('id', '[0-9]+');

        // نسخ امتحان + قوالب (المدرس صاحب الامتحان بس).
        Route::post('exams/{id}/duplicate', [ExamCopyApiController::class, 'duplicate'])->where('id', '[0-9]+')->middleware('throttle:30,1');
        Route::put('exams/{id}/template', [ExamCopyApiController::class, 'template'])->where('id', '[0-9]+');
    });

    // Integrated Meeting & Collaboration Platform — Round 1 (Foundation).
    // أي مستخدم مسجّل (أي uip_role) يقدر يستضيف/ينضم لاجتماع — مفيش قيد
    // دور هنا زي exam-system فوق. meetings/invitations لازم تتسجل قبل
    // meetings/{uuid} العام عشان "invitations" ميتفسرش كـ {uuid}.
    Route::prefix('meetings')->middleware('uip.auth')->group(function () {
        Route::get('invitations', [MeetingsApiController::class, 'myInvitations']);

        // Round 8 (Invitations & Calendar، بند 14/26). لازم تتسجل هنا،
        // قبل meetings/{uuid} العام تحت، عشان "calendar"/"by-attachable"
        // ميتفسروش كـ {uuid} — نفس السبب بالظبط اللي "invitations" فوق
        // مسجّلة عشانه.
        Route::get('calendar', [MeetingsInvitationsApiController::class, 'calendar']);
        Route::get('by-attachable', [MeetingsInvitationsApiController::class, 'byAttachable']);

        Route::get('/', [MeetingsApiController::class, 'index']);
        Route::post('/', [MeetingsApiController::class, 'store']);

        Route::get('{uuid}', [MeetingsApiController::class, 'show']);
        Route::patch('{uuid}', [MeetingsApiController::class, 'update']);
        Route::delete('{uuid}', [MeetingsApiController::class, 'destroy']);

        Route::post('{uuid}/cancel', [MeetingsApiController::class, 'cancel']);
        Route::post('{uuid}/start', [MeetingsApiController::class, 'start']);
        Route::post('{uuid}/end', [MeetingsApiController::class, 'end']);
        Route::post('{uuid}/verify-password', [MeetingsApiController::class, 'verifyPassword']);

        Route::get('{uuid}/participants', [MeetingsApiController::class, 'indexParticipants']);

        Route::get('{uuid}/invitations', [MeetingsApiController::class, 'indexInvitations']);
        Route::post('{uuid}/invitations', [MeetingsApiController::class, 'storeInvitation']);
        Route::post('{uuid}/invitations/{invitationId}/respond', [MeetingsApiController::class, 'respondToInvitation'])->where('invitationId', '[0-9]+');

        // Round 8 (Invitations & Calendar، بند 13 — Bulk Invitations).
        // هوست/co-host بس (canManage) — راجع docblock
        // MeetingsInvitationsApiController::bulk().
        Route::post('{uuid}/invitations/bulk', [MeetingsInvitationsApiController::class, 'bulk']);

        // Round 2 (Lobby & Access, بند 22 — Waiting Room). إدارة طلبات
        // الدخول من ناحية الهوست/co-host بس — إنشاء الطلب نفسه (من
        // ناحية الداخل، مستخدم أو ضيف) في مجموعة meetings/join العامة
        // تحت (مفيش uip.auth هناك، الدخول ده لسه ما دخلش الاجتماع أصلًا).
        Route::get('{uuid}/waiting-room', [MeetingsApiController::class, 'waitingRoomIndex']);
        Route::post('{uuid}/waiting-room/accept-all', [MeetingsApiController::class, 'waitingRoomAcceptAll']);
        Route::post('{uuid}/waiting-room/reject-all', [MeetingsApiController::class, 'waitingRoomRejectAll']);
        Route::post('{uuid}/waiting-room/{requestId}/accept', [MeetingsApiController::class, 'waitingRoomAccept'])->where('requestId', '[0-9]+');
        Route::post('{uuid}/waiting-room/{requestId}/reject', [MeetingsApiController::class, 'waitingRoomReject'])->where('requestId', '[0-9]+');

        // Round 6 (Host Controls، بند 5/10). uip.auth العادي (زي باقي
        // مجموعة meetings/* دي) مش uip.auth.optional — الـ actor هنا
        // دايمًا هوست/co-host، أي مستخدم UIP مسجّل بتوكن حقيقي، مفيش
        // ضيف يقدر يدير اجتماع. راجع docblock MeetingsHostControlApiController.
        Route::post('{uuid}/host-controls/lock', [MeetingsHostControlApiController::class, 'lock']);
        Route::post('{uuid}/host-controls/mute', [MeetingsHostControlApiController::class, 'mute']);
        Route::post('{uuid}/host-controls/disable-camera', [MeetingsHostControlApiController::class, 'disableCamera']);
        Route::post('{uuid}/host-controls/remove', [MeetingsHostControlApiController::class, 'remove']);
        Route::post('{uuid}/host-controls/promote', [MeetingsHostControlApiController::class, 'promote']);
        Route::post('{uuid}/host-controls/demote', [MeetingsHostControlApiController::class, 'demote']);
        Route::post('{uuid}/host-controls/transfer-host', [MeetingsHostControlApiController::class, 'transferHost']);

        // Round 7 (Collaboration Extras، بند 17 — Attendance Tracking).
        // uip.auth العادي (زي host-controls) — "authorized users"
        // اتفسّرت هوست/co-host (canManage)، راجع docblock
        // MeetingsAttendanceApiController.
        Route::get('{uuid}/attendance/report', [MeetingsAttendanceApiController::class, 'report']);
        Route::get('{uuid}/attendance/export', [MeetingsAttendanceApiController::class, 'export']);

        // Round 10 (Admin & Docs، بند 15 — Meeting Analytics widget).
        // نفس منطق canManage() بتاع attendance فوق — هوست/co-host بس.
        Route::get('{uuid}/analytics', [MeetingsAnalyticsApiController::class, 'forMeeting']);
    });

    // Round 2 (Lobby & Access, بند 3 — Join Meeting Experience/Pre-Join
    // Screen، بند 23 — Guest Access). سطح "الدخول برابط الاجتماع" العام
    // مقصود يكون منفصل تمامًا عن مجموعة meetings/* فوق: مفيش uip.auth
    // هنا (ميدلوير uip.auth.optional بس) عشان ضيف من غير حساب UIP يقدر
    // يوصله، ومبني على join_token (مش uuid) عشان ده هو اللي فعليًا في
    // رابط الانضمام المُشارك (بند 2). لازم يتسجل بعد مجموعة meetings/*
    // فوق عشان "join" ميتفسرش غلط كـ {uuid} لو ترتيبه قبلها (مفيش تعارض
    // فعلي هنا لأن الـ prefix مختلف، بس نفس منطق الترتيب المتبع أعلى).
    Route::prefix('meetings/join')->middleware('uip.auth.optional')->group(function () {
        Route::get('{joinToken}/info', [MeetingsLobbyApiController::class, 'info']);
        Route::post('{joinToken}/verify-password', [MeetingsLobbyApiController::class, 'verifyPassword']);
        Route::post('{joinToken}/request', [MeetingsLobbyApiController::class, 'requestAccess']);
        Route::get('{joinToken}/request/{requestId}/status', [MeetingsLobbyApiController::class, 'requestStatus'])->where('requestId', '[0-9]+');
    });

    // Round 3 (Signaling، بند 6 الجزء الخاص بالـ Signaling، بند 33 —
    // Real-Time Architecture). زي meetings/join بالظبط: uip.auth.optional
    // مش uip.auth، عشان ضيف مقبول (بند 23) يقدر يستخدم القناة/الـ media
    // state بتاعته برضو (guest_token في الـ body بدل Bearer). مبني على
    // uuid (مش join_token) لأن العنصر ده أصلًا داخل الاجتماع دلوقتي، مش
    // بيحاول يدخله لسه.
    Route::prefix('meetings/{uuid}/signaling')->middleware('uip.auth.optional')->group(function () {
        Route::post('auth', [MeetingsSignalingApiController::class, 'authorizeChannel']);
        Route::post('media-state', [MeetingsSignalingApiController::class, 'mediaState']);
        Route::post('connection-state', [MeetingsSignalingApiController::class, 'connectionState']);
        Route::post('heartbeat', [MeetingsSignalingApiController::class, 'heartbeat']);
        Route::post('leave', [MeetingsSignalingApiController::class, 'leave']);

        // Round 4 (WebRTC Core، بند 4/6/34).
        Route::get('ice-servers', [MeetingsSignalingApiController::class, 'iceServers']);
        Route::post('connection-quality', [MeetingsSignalingApiController::class, 'connectionQuality']);
        Route::post('failure', [MeetingsSignalingApiController::class, 'reportFailure']);
        Route::get('roster', [MeetingsSignalingApiController::class, 'roster']);

        // Round 5 (Live Collaboration، بند 11 — Raise Hand & Reactions).
        Route::post('hand', [MeetingsSignalingApiController::class, 'hand']);
        Route::post('reaction', [MeetingsSignalingApiController::class, 'reaction']);

        // Round 5 (Live Collaboration، بند 9 — Screen Sharing host controls).
        Route::post('screen-share-policy', [MeetingsSignalingApiController::class, 'screenSharePolicy']);
        Route::post('screen-share-policy/participant', [MeetingsSignalingApiController::class, 'screenShareParticipantPolicy']);
        Route::post('screen-share/stop', [MeetingsSignalingApiController::class, 'screenShareStop']);
    });

    // Round 5 (Live Collaboration، بند 7 — Meeting Chat، بند 8 — Private
    // Chat). زي مجموعة signaling بالظبط: uip.auth.optional مبني على uuid
    // (مش join_token) — راجع docblock MeetingsChatApiController.
    Route::prefix('meetings/{uuid}/chat')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsChatApiController::class, 'index']);
        Route::post('/', [MeetingsChatApiController::class, 'store']);
        Route::post('{messageId}/react', [MeetingsChatApiController::class, 'react'])->where('messageId', '[0-9]+');
    });

    // Round 7 (Collaboration Extras، بند 19 — File Sharing). زي مجموعة
    // chat بالظبط: uip.auth.optional — راجع docblock MeetingsFilesApiController.
    Route::prefix('meetings/{uuid}/files')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsFilesApiController::class, 'index']);
        Route::post('/', [MeetingsFilesApiController::class, 'store']);
        Route::delete('{fileId}', [MeetingsFilesApiController::class, 'destroy'])->where('fileId', '[0-9]+');
    });

    // Round 7 (Collaboration Extras، بند 20 — Meeting Notes). notes
    // (مستند واحد) + action-items (Decision/Action Item/Task) — راجع
    // docblock MeetingsNotesApiController.
    Route::prefix('meetings/{uuid}/notes')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsNotesApiController::class, 'show']);
        Route::put('/', [MeetingsNotesApiController::class, 'update']);
    });
    Route::prefix('meetings/{uuid}/action-items')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsNotesApiController::class, 'indexItems']);
        Route::post('/', [MeetingsNotesApiController::class, 'storeItem']);
        Route::patch('{itemId}', [MeetingsNotesApiController::class, 'updateItem'])->where('itemId', '[0-9]+');
        Route::delete('{itemId}', [MeetingsNotesApiController::class, 'destroyItem'])->where('itemId', '[0-9]+');
    });

    // Round 7 (Collaboration Extras، بند 21 — Polls). راجع docblock
    // MeetingsPollsApiController.
    Route::prefix('meetings/{uuid}/polls')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsPollsApiController::class, 'index']);
        Route::post('/', [MeetingsPollsApiController::class, 'store']);
        Route::post('{pollId}/vote', [MeetingsPollsApiController::class, 'vote'])->where('pollId', '[0-9]+');
        Route::post('{pollId}/close', [MeetingsPollsApiController::class, 'close'])->where('pollId', '[0-9]+');
    });

    // Round 9 (Recording، بند 18). زي مجموعة files بالظبط: uip.auth.optional
    // — راجع docblock MeetingsRecordingsApiController. start=host/co-host
    // بس، stop بياخد الملف النهائي بعد ما المتصفح ينهي MediaRecorder.
    Route::prefix('meetings/{uuid}/recordings')->middleware('uip.auth.optional')->group(function () {
        Route::get('/', [MeetingsRecordingsApiController::class, 'index']);
        Route::post('/', [MeetingsRecordingsApiController::class, 'store']);
        Route::post('{recordingId}/stop', [MeetingsRecordingsApiController::class, 'stop'])->where('recordingId', '[0-9]+');
        Route::delete('{recordingId}', [MeetingsRecordingsApiController::class, 'destroy'])->where('recordingId', '[0-9]+');
    });

    // بند 10 — Academic Staff + Faculty portal، جزء 4: سطح لوحة تحكم موحّد
    // (overview + polling) لأدوار faculty/academic_staff — نفس نمط
    // /dashboards/overview العام اللي باقي البنود بتستخدمه.
    Route::prefix('dashboards')->middleware('uip.auth')->group(function () {
        Route::get('/overview', [DashboardsApiController::class, 'overview']);
        Route::get('/live', [DashboardsApiController::class, 'live']);
    });

    // بند 11 — Projects. كل المراحل (1: Core CRUD+Files/Links/Team،
    // 2: create/update الباحث + approval-status، 4: discussion/activity/
    // analytics) مقفولة. create-meta لازم يتسجل قبل
    // /{id} — نفس ترتيب faculty/me و students/dashboard-stats فوق، عشان
    // Laravel ميحاولش يطابقه كـ {id}. AI analysis (بند 21) اتضافت تحت —
    // كانت متأجلة هنا مع code-review (بند 20، لسه متأجل). discover()
    // مفيهاش endpoint مستقل هنا — المشاريع المنشورة بتتقرا عبر
    // /api/v1/public/* أو كنترولر البورتال المعني، مش عبر
    // ProjectsApiController.
    Route::prefix('projects')->middleware('uip.auth')->group(function () {
        Route::get('/', [ProjectsApiController::class, 'index']);
        Route::post('/', [ProjectsApiController::class, 'store'])->middleware('uip.can:project.create');

        Route::get('/create-meta', [ProjectsApiController::class, 'createMeta']);

        // بند 15 (Future > GitHub Integration) — لازم تتسجل قبل GET
        // /{id} عشان Laravel ميحاولش يطابق "github" كـ {id} (نفس ترتيب
        // create-meta فوق).
        Route::get('/github', [ProjectsApiController::class, 'githubOverview']);

        Route::get('/{id}', [ProjectsApiController::class, 'show']);
        Route::patch('/{id}', [ProjectsApiController::class, 'update'])->middleware('uip.can:project.edit_own');
        Route::delete('/{id}', [ProjectsApiController::class, 'destroy']);
        Route::post('/{id}/submit', [ProjectsApiController::class, 'submit']);
        Route::post('/{id}/publish', [ProjectsApiController::class, 'publish']);
        Route::post('/{id}/archive', [ProjectsApiController::class, 'archive']);
        Route::post('/{id}/unarchive', [ProjectsApiController::class, 'unarchive']);

        // Sandbox gateway token (real terminal / Laravel / Node / Python). Signs only — nothing runs here.
        Route::post('/{id}/sandbox-token', [SandboxApiController::class, 'token'])->middleware('throttle:30,1');

        Route::get('/{id}/files', [ProjectsApiController::class, 'files']);
        Route::post('/{id}/files', [ProjectsApiController::class, 'storeFile']);
        Route::get('/{id}/media', [ProjectsApiController::class, 'media']);
        Route::put('/{id}/files/{fileId}', [ProjectsApiController::class, 'replaceFile']);
        // نفس replaceFile بـ POST: PHP مبيقراش multipart على PUT (الملف بيوصل فاضي)،
        // فمحرر الـ ZIP في الفرونت (زرار Save) بيستخدم الـ POST ده.
        Route::post('/{id}/files/{fileId}/replace', [ProjectsApiController::class, 'replaceFile']);
        Route::get('/{id}/files/{fileId}/versions', [ProjectsApiController::class, 'fileVersions']);
        Route::delete('/{id}/files/{fileId}', [ProjectsApiController::class, 'deleteFile']);

        Route::get('/{id}/links', [ProjectsApiController::class, 'links']);
        Route::post('/{id}/links', [ProjectsApiController::class, 'addLink']);
        Route::patch('/{id}/links/{linkId}', [ProjectsApiController::class, 'updateLink']);
        Route::delete('/{id}/links/{linkId}', [ProjectsApiController::class, 'deleteLink']);

        Route::get('/{id}/team', [ProjectsApiController::class, 'team']);
        Route::post('/{id}/team/invite', [ProjectsApiController::class, 'inviteTeamMember']);
        Route::post('/{id}/team/manual', [ProjectsApiController::class, 'addManualTeamMember']);
        Route::get('/{id}/team/search', [ProjectsApiController::class, 'searchTeamCandidates']);
        Route::get('/{id}/submission-check', [ProjectsApiController::class, 'submissionCheck']);
        Route::post('/{id}/team/add-user', [ProjectsApiController::class, 'addTeamUser']);
        Route::delete('/{id}/team/{memberId}', [ProjectsApiController::class, 'removeTeamMember']);

        // بند 11 مرحلة 2 — بانر تغذية راجعة المراجع، مالك المشروع بس.
        Route::get('/{id}/approval', [ProjectsApiController::class, 'approvalStatus']);

        // بند 11 مرحلة 4 — Discussion/Activity (المالك أو عضو فريق مقبول)
        // + Analytics (مالك المشروع بس).
        Route::get('/{id}/discussion', [ProjectsApiController::class, 'discussion']);
        Route::post('/{id}/discussion', [ProjectsApiController::class, 'postDiscussion']);
        Route::get('/{id}/activity', [ProjectsApiController::class, 'activity']);
        Route::get('/{id}/analytics', [ProjectsApiController::class, 'analytics']);

        // بند 15 (Future > GitHub Integration) — مراجعة الكود لمشروع
        // واحد. /github (فوق) بيغطي الملخص عبر كل المشاريع.
        Route::post('/{id}/code-review/run', [ProjectsApiController::class, 'runCodeReview']);
        Route::get('/{id}/code-review', [ProjectsApiController::class, 'latestCodeReview']);

        // بند 21 — AI Analysis. مالك المشروع بس على الخمسة كلهم (شوف
        // findOwnedOr404() في الكنترولر). consent/run لازم CSRF زي
        // القديمة — uip.auth middleware هنا بيغطي auth، الـ CSRF-equivalent
        // بتاع API tokens مش محتاج تكرار هنا (لا يوجد جلسة كوكي على REST).
        Route::post('/{id}/ai-analysis/consent', [ProjectsApiController::class, 'grantAiConsent'])->middleware('uip.can:ai.request_analysis');
        Route::post('/{id}/ai-analysis/run', [ProjectsApiController::class, 'runAiAnalysis'])->middleware('uip.can:ai.request_analysis');
        Route::get('/{id}/ai-analysis', [ProjectsApiController::class, 'latestAiAnalysis'])->middleware('uip.can:ai.request_analysis');
        Route::get('/{id}/ai-analysis/export', [ProjectsApiController::class, 'exportAiAnalysis'])->middleware('uip.can:ai.request_analysis');
        Route::get('/{id}/ai-analysis/similar', [ProjectsApiController::class, 'similarProjects'])->middleware('uip.can:ai.request_analysis');
        Route::get('/{id}/ai-analysis/duplicates', [ProjectsApiController::class, 'duplicateCheck'])->middleware('uip.can:ai.request_analysis');
    });

    // بند 11 مرحلة 2 — إشراف الأدمن على مستوى المنصة كلها على طابور
    // اعتماد المشاريع + override استثنائي محتاج سبب. مسار approve/reject/
    // request-changes العادي فاضل عند الجامعة/الكلية (routes فوق)، مش
    // بيتكرر هنا. مفيش تضارب مع أي بند تاني على prefix('admin') لأنه أول
    // ظهور له.
    Route::prefix('admin/approvals')->middleware('uip.auth')->group(function () {
        Route::get('/', [AdminApprovalsApiController::class, 'index']);
        Route::post('/{id}/override', [AdminApprovalsApiController::class, 'override']);
    });

    // تشغيل: حالة الـ Volume (التخزين الدائم) + حدود رفع PHP كما يراها الويب فعلًا.
    Route::get('admin/system/storage-health', [\App\Http\Controllers\Api\AdminStorageHealthApiController::class, 'show'])
        ->middleware(['uip.auth', 'uip.admin']);

    // بند 11 مرحلة 4 — تنسيق أدمن للمشاريع المميّزة على صفحة الهبوط
    // العامة. أول ظهور لـ prefix('admin/featured-projects')، فمفيش تضارب.
    Route::prefix('admin/featured-projects')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminFeaturedProjectsApiController::class, 'index']);
        Route::post('/{id}/feature', [AdminFeaturedProjectsApiController::class, 'feature']);
        Route::post('/{id}/unfeature', [AdminFeaturedProjectsApiController::class, 'unfeature']);
        Route::post('/{id}/order', [AdminFeaturedProjectsApiController::class, 'reorder']);
    });

    // بند 25 batch 1 — AdminDashboardApiController::index() القديمة، سطح
    // /api/v1/admin/dashboard واحد بيعيد استخدام نفس الـ repository/service
    // calls اللي Admin\AdminDashboardController القديمة كانت بتعملها للـ
    // Blade view، مفيش حاجة مختلَقة.
    Route::prefix('admin/dashboard')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminDashboardApiController::class, 'index']);
    });

    // الزوار الفريدين الحقيقيين (بدون تضخيم الريفريش) — قسم في داشبورد الأدمن.
    Route::prefix('admin/visitors')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminVisitorsApiController::class, 'index']);
    });

    // Round 10 (Admin & Docs، بند 39 — Admin Monitoring). راجع docblock
    // MeetingsAnalyticsApiController::platformMonitoring() — عدادات/
    // إحصائيات بس، مفيش محتوى اجتماع خاص.
    Route::prefix('admin/meetings')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('monitoring', [MeetingsAnalyticsApiController::class, 'platformMonitoring']);
    });

    // بند 25 batch 2 — دليل Universities
    // بتاعة الأدمن (React admin dashboard conversion). كل واحدة بتعيد
    // استخدام نفس الـ repository calls بالظبط زي الـ Blade view المطابقة
    // (AdminUniversityVerificationController
    // القديمة)، مفيش
    // حاجة مختلَقة.
    Route::prefix('admin/universities')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminUniversitiesApiController::class, 'index']);
        Route::post('/{id}/verify', [AdminUniversitiesApiController::class, 'verify']);
        Route::post('/{id}/reject', [AdminUniversitiesApiController::class, 'reject']);
        Route::patch('/{id}/reverification', [AdminUniversitiesApiController::class, 'updateReverification']);
        Route::delete('/{id}', [AdminUniversitiesApiController::class, 'destroy']);
        Route::post('/{id}/restore', [AdminUniversitiesApiController::class, 'restore']);
    });

    // بند 25 batch 4 — Mobile App Management (إدارة API bearer tokens
    // العميل الموبايل) + Notification Settings (سياسة أرشفة/حذف تلقائي
    // للإشعارات على مستوى المنصة). AdminMobileController/
    // AdminNotificationController القديمة بالظبط، مفيش حاجة مختلَقة.
    Route::prefix('admin/mobile-tokens')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminMobileApiController::class, 'index']);
        Route::post('/', [AdminMobileApiController::class, 'store']);
        Route::get('/alert-settings', [AdminMobileApiController::class, 'alertSettings']);
        Route::put('/alert-settings', [AdminMobileApiController::class, 'saveAlertSettings'])->middleware('throttle:30,1');
        Route::post('/{id}/alert-test', [AdminMobileApiController::class, 'alertTest'])->where('id', '[0-9]+')->middleware('throttle:10,1');
        Route::get('/blocked-devices', [AdminMobileApiController::class, 'blockedDevices']);
        Route::post('/blocked-devices', [AdminMobileApiController::class, 'blockDevice'])->middleware('throttle:30,1');
        Route::delete('/blocked-devices/{id}', [AdminMobileApiController::class, 'unblockDevice'])->where('id', '[0-9]+');
        Route::post('/{id}/revoke', [AdminMobileApiController::class, 'revoke']);
        Route::get('/{id}', [AdminMobileApiController::class, 'show'])->where('id', '[0-9]+');
        Route::get('/{id}/requests', [AdminMobileApiController::class, 'requests'])->where('id', '[0-9]+');
        Route::get('/{id}/daily', [AdminMobileApiController::class, 'daily'])->where('id', '[0-9]+');
        Route::delete('/{id}', [AdminMobileApiController::class, 'destroy'])->where('id', '[0-9]+');
    });

    // Admin → إعدادات الحضور والغياب: حدود الحرمان، مدة التحضير، الـ QR، الجيوفنس، ومواقع الكليات.
    Route::prefix('admin/attendance-settings')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AdminAttendanceSettingsApiController::class, 'show']);
        Route::put('/', [\App\Http\Controllers\Api\AdminAttendanceSettingsApiController::class, 'update'])->middleware('throttle:30,1');
        Route::delete('/', [\App\Http\Controllers\Api\AdminAttendanceSettingsApiController::class, 'reset'])->middleware('throttle:10,1');
        Route::put('/faculties/{id}', [\App\Http\Controllers\Api\AdminAttendanceSettingsApiController::class, 'setFaculty'])->where('id', '[0-9]+')->middleware('throttle:60,1');
    });

    // Admin → Platform Policy: حظر إيميلات/دومينات، قفل التسجيل، تشغيل/إيقاف تشفير الـ response.
    Route::prefix('admin/platform-policy')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'show']);
        Route::patch('/flags', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'updateFlags']);
        Route::put('/values', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'updateValues'])->middleware('throttle:20,1');
        Route::put('/devices', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'updateDevices'])->middleware('throttle:30,1');
        Route::delete('/devices', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'resetDevices'])->middleware('throttle:30,1');
        Route::post('/rules', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'addRules']);
        Route::delete('/rules', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'removeRule']);
        Route::post('/test', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'test']);
        Route::post('/decrypt', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'decrypt'])->middleware('throttle:30,1');
        Route::get('/staff-gate', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'staffGate']);
        Route::put('/staff-gate/{group}', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'setStaffGateCode'])->middleware('throttle:10,1');
        Route::delete('/staff-gate/{group}', [\App\Http\Controllers\Api\AdminPlatformPolicyApiController::class, 'resetStaffGateCode'])->middleware('throttle:10,1');
    });

    Route::prefix('admin/notification-settings')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminNotificationSettingsApiController::class, 'show']);
        Route::patch('/', [AdminNotificationSettingsApiController::class, 'update']);
    });

    // بند 25 batch 5 — Messaging Oversight: لوحة إشراف الأدمن على مستوى
    // المنصة كلها (كل محادثة عبر كل بورتال)، تحليلات الاستخدام، وإعدادات
    // الرسايل (حدود الرفع/الاحتفاظ/تفعيل كل بورتال). نفس
    // Admin\AdminMessagingController القديمة (قسم "Admin Controls gap
    // fix") بالظبط، مفيش حاجة مختلَقة.
    Route::prefix('admin/messaging')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/oversight', [AdminMessagingOversightApiController::class, 'index']);
        Route::get('/oversight/{id}', [AdminMessagingOversightApiController::class, 'thread']);
        // محادثات المستخدمين مع المساعد الذكي (قراءة-فقط، كل فتح بيتسجل).
        Route::get('/ai-conversations', [AdminAiConversationsApiController::class, 'index']);
        Route::get('/ai-conversations/{id}', [AdminAiConversationsApiController::class, 'thread'])->where('id', '[0-9]+');
        Route::post('/oversight/messages/{id}/delete', [AdminMessagingOversightApiController::class, 'deleteMessage']);
        Route::delete('/oversight/attachments/{id}', [AdminMessagingOversightApiController::class, 'deleteAttachment']);
        Route::get('/analytics', [AdminMessagingOversightApiController::class, 'analytics']);
        Route::get('/settings', [AdminMessagingOversightApiController::class, 'settings']);
        Route::patch('/settings/upload', [AdminMessagingOversightApiController::class, 'updateUploadSettings']);
        Route::patch('/settings/retention', [AdminMessagingOversightApiController::class, 'updateRetentionSettings']);
        Route::post('/settings/portal/{key}', [AdminMessagingOversightApiController::class, 'updatePortalToggle']);
    });

    // بند 26 (Admin) batch 6 — AI Code Review + Export: لوحة إشراف
    // الأدمن على مراجعات الكود بالـ AI عبر المنصة كلها (فلترة/بحث/
    // approve/note/override-score/rerun/compare)، + تصدير تقارير
    // (مشروع/جامعة/كلية/مقارنة) بأربع صيغ. نفس
    // Admin\AdminAiCodeReviewController + AdminAiCodeReviewExportController
    // القديمتين بالظبط، مفيش حاجة مختلَقة — مسجّلة تحت نفس الـ prefix زي
    // الكود القديم (routes/api.php هناك كانت بتسجّل تصدير Export controller
    // تحت نفس /admin/ai-code-review بدل ما تكررها).
    Route::prefix('admin/ai-code-review')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminAiCodeReviewApiController::class, 'index']);
        Route::get('/project/{projectId}/history', [AdminAiCodeReviewApiController::class, 'history']);
        Route::post('/{id}/approve', [AdminAiCodeReviewApiController::class, 'approve'])->where('id', '[0-9]+');
        Route::post('/{id}/note', [AdminAiCodeReviewApiController::class, 'addNote'])->where('id', '[0-9]+');
        Route::post('/{id}/override-score', [AdminAiCodeReviewApiController::class, 'overrideScore'])->where('id', '[0-9]+');
        Route::post('/{projectUuid}/rerun', [AdminAiCodeReviewApiController::class, 'rerun']);
        Route::get('/compare/{idA}/{idB}', [AdminAiCodeReviewApiController::class, 'compareVersions'])->where(['idA' => '[0-9]+', 'idB' => '[0-9]+']);

        Route::get('/project/{projectId}/export', [AdminAiCodeReviewExportController::class, 'exportProject']);
        Route::get('/university/{universityId}/export', [AdminAiCodeReviewExportController::class, 'exportUniversity']);
        Route::get('/faculty/export', [AdminAiCodeReviewExportController::class, 'exportFaculty']);
        Route::get('/compare/{idA}/{idB}/export', [AdminAiCodeReviewExportController::class, 'exportComparison'])->where(['idA' => '[0-9]+', 'idB' => '[0-9]+']);
    });

    // بند 25 batch 8 — Security Logs (admin-specific): سطح أدمن مستقل
    // بيعرض السجل الأمني الخام (log files، بدون DB) — نفس
    // Admin\AdminSecurityLogController القديمة بالظبط، عبر
    // SecurityLogService الموجودة بالفعل (منقولة قبل كده). مختلفة عن
    // /security/logs/* (بند 25 batch 4) اللي بتدمج مع audit_logs
    // للـ Security Portal.
    Route::prefix('admin/security-logs')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminSecurityLogsApiController::class, 'index']);
        Route::get('/export', [AdminSecurityLogsApiController::class, 'export']);
    });

    // بند 25 batch 9 — Users: سطح /api/v1/admin/users/* لدليل المستخدمين
    // الكامل (كل الأدوار الستة)، CRUD زائد suspend/activate/تغيير الدور/
    // منح-سحب دور إضافي/حظر IP، نفس AdminUsersApiController القديمة بالظبط.
    Route::prefix('admin/users')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminUsersApiController::class, 'index']);
        Route::post('/', [AdminUsersApiController::class, 'store']);
        Route::post('/bulk', [AdminUsersApiController::class, 'bulkStore'])->middleware('throttle:10,1');
        // static قبل /{id} عشان مايتبلعش كـ uuid
        Route::get('/affiliation-options', [AdminUsersApiController::class, 'affiliationOptions']);
        Route::get('/filter-options', [AdminUsersApiController::class, 'filterOptions']);
        Route::get('/{id}', [AdminUsersApiController::class, 'show']);
        Route::patch('/{id}', [AdminUsersApiController::class, 'update']);
        Route::delete('/{id}', [AdminUsersApiController::class, 'destroy']);
        Route::post('/{id}/restore', [AdminUsersApiController::class, 'restore']);
        Route::post('/{id}/suspend', [AdminUsersApiController::class, 'suspend']);
        Route::post('/{id}/activate', [AdminUsersApiController::class, 'activate']);
        Route::patch('/{id}/role', [AdminUsersApiController::class, 'changeRole']);
        Route::post('/{id}/roles', [AdminUsersApiController::class, 'assignRole']);
        Route::put('/{id}/joker', [AdminUsersApiController::class, 'setJoker']);
        Route::delete('/{id}/roles/{role}', [AdminUsersApiController::class, 'revokeRole']);
        Route::post('/{id}/block-ip', [AdminUsersApiController::class, 'blockIp']);
        Route::put('/{id}/affiliation', [AdminUsersApiController::class, 'updateAffiliation']);
        Route::delete('/{id}/affiliation', [AdminUsersApiController::class, 'removeAffiliation']);
    });

    // بند 25 batch 10 — Roles & Permissions: سطح /api/v1/admin/roles/*،
    // نفس AdminRolesApiController القديمة بالظبط. {slug} هو slug الدور،
    // مش رقم، زي شكل الـ URL بتاع الويب نفسه.
    Route::prefix('admin/roles')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminRolesApiController::class, 'index']);
        Route::post('/', [AdminRolesApiController::class, 'store']);
        Route::get('/{slug}', [AdminRolesApiController::class, 'show']);
        Route::patch('/{slug}/permissions', [AdminRolesApiController::class, 'updatePermissions']);
        Route::delete('/{slug}', [AdminRolesApiController::class, 'destroy']);
    });

    // بند 25 batch 11 — Audit Logs (آخر باتش في البند): سطح
    // /api/v1/admin/audit-logs/* قراءة-فقط، نفس AdminAuditLogsApiController
    // القديمة بالظبط (index بفلاتر + export CSV).
    Route::prefix('admin/audit-logs')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminAuditLogsApiController::class, 'index']);
        Route::get('/export', [AdminAuditLogsApiController::class, 'export']);
    });

    // publicIndex()/publicShow() لازم يفضلوا برّه أي middleware auth —
    // دول دليل عام حقيقي بيتفتحوا من غير تسجيل دخول (زي باقي
    // /api/v1/public/* فوق)؛ كانوا واقعين غلط جوه الجروب اللي تحت
    // 'uip.auth' فرجعوا 401 لأي زائر مش مسجل دخول، يعني رابط الكلية
    // العام (/universities/{slug}/faculties/{slug}) كان بيفشل فورًا.
    Route::prefix('faculty')->group(function () {
        Route::get('/public/{universitySlug}', [FacultyApiController::class, 'publicIndex']);
        Route::get('/public/{universitySlug}/{facultySlug}', [FacultyApiController::class, 'publicShow']);
    });

    Route::prefix('faculty')->middleware('uip.auth')->group(function () {
        Route::get('/', [FacultyApiController::class, 'index']);
        Route::post('/', [FacultyApiController::class, 'store']);

        Route::get('/me', [FacultyApiController::class, 'me']);
        Route::patch('/me/visibility', [FacultyApiController::class, 'updateOwnVisibility']);
        Route::post('/me/avatar', [FacultyApiController::class, 'uploadAvatar']);
        Route::get('/my/tree', [FacultyApiController::class, 'myTree']);

        Route::get('/{id}', [FacultyApiController::class, 'show']);
        Route::patch('/{id}', [FacultyApiController::class, 'update']);
        Route::post('/{id}/archive', [FacultyApiController::class, 'archive']);
        Route::post('/{id}/unarchive', [FacultyApiController::class, 'unarchive']);
        Route::post('/{id}/login', [FacultyApiController::class, 'provisionLogin']);
        Route::post('/{id}/login/reset', [FacultyApiController::class, 'resetLogin']);
        Route::patch('/{id}/login/email', [FacultyApiController::class, 'updateLoginEmail']);
        Route::patch('/{id}/login/password', [FacultyApiController::class, 'updateLoginPassword']);
        Route::post('/{id}/departments', [FacultyApiController::class, 'storeDepartment']);

        Route::post('/departments', [FacultyApiController::class, 'storeOwnDepartment']);
    });

    // GET/archive/unarchive أُضيفوا مع صفحة Department Portfolio الجديدة —
    // كان فيه بس PATCH (تبديل is_public)، فرابط "View Portfolio" في
    // faculty-portfolio.jsx كان بيودّي لـ 404 حقيقي (مفيش Route ولا
    // component خالص). راجع showDepartment()/archiveDepartment()/
    // unarchiveDepartment() في FacultyApiController.
    Route::prefix('departments')->middleware('uip.auth')->group(function () {
        Route::get('/{id}', [FacultyApiController::class, 'showDepartment']);
        Route::patch('/{id}', [FacultyApiController::class, 'updateDepartment']);
        Route::post('/{id}/archive', [FacultyApiController::class, 'archiveDepartment']);
        Route::post('/{id}/unarchive', [FacultyApiController::class, 'unarchiveDepartment']);
        Route::delete('/{id}', [FacultyApiController::class, 'deleteDepartment']);
        Route::post('/{id}/restore', [FacultyApiController::class, 'restoreDepartment']);
    });

    // بند 4 — Students portal (8 endpoint، STUDENTS_API_CONTRACT.md) +
    // /me الخاص بصفحة بروفايل الطالب (student/profile — كان ناقص، نفس
    // اتفاقية faculty/me). /me و/me/join-request
    // و dashboard-stats و dashboard-stats/live لازم يتسجلوا قبل /{id} —
    // نفس ترتيب faculty/me فوق، عشان Laravel ميحاولش يطابقهم كـ {id}.
    Route::prefix('students')->middleware('uip.auth')->group(function () {
        Route::get('/', [StudentsApiController::class, 'index']);
        Route::post('/', [StudentsApiController::class, 'store']);

        Route::get('/dashboard-stats', [StudentsApiController::class, 'dashboardStats']);
        Route::get('/dashboard-stats/live', [StudentsApiController::class, 'dashboardStatsLive']);

        Route::get('/me', [StudentsApiController::class, 'me']);
        Route::patch('/me', [StudentsApiController::class, 'updateMe']);
        Route::post('/me/avatar', [StudentsApiController::class, 'uploadAvatar']);
        Route::post('/me/join-request', [StudentsApiController::class, 'joinRequest']);

        Route::get('/{id}', [StudentsApiController::class, 'show']);
        Route::patch('/{id}', [StudentsApiController::class, 'update']);
        Route::delete('/{id}', [StudentsApiController::class, 'delete']);
        Route::post('/{id}/resend', [StudentsApiController::class, 'resend']);
        Route::patch('/{id}/password', [StudentsApiController::class, 'setPassword'])->where('id', '[0-9]+');
    });

    // التكليفات والتسليمات: مديرو المادة ينزّلوا ويصحّحوا، والطالب يسلّم ويشوف درجته.
    Route::prefix('assignments')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        Route::get('/my', [CourseAssignmentApiController::class, 'mine']);
        $ids = ['id' => '[0-9]+', 'aid' => '[0-9]+', 'sid' => '[0-9]+'];
        Route::get('/courses/{id}', [CourseAssignmentApiController::class, 'index'])->where($ids);
        Route::post('/courses/{id}', [CourseAssignmentApiController::class, 'store'])->where($ids)->middleware('throttle:30,1');
        Route::post('/courses/{id}/{aid}', [CourseAssignmentApiController::class, 'update'])->where($ids)->middleware('throttle:30,1');
        Route::patch('/courses/{id}/{aid}', [CourseAssignmentApiController::class, 'update'])->where($ids);
        Route::delete('/courses/{id}/{aid}', [CourseAssignmentApiController::class, 'destroy'])->where($ids);
        Route::get('/courses/{id}/{aid}/file', [CourseAssignmentApiController::class, 'attachment'])->where($ids);
        Route::post('/courses/{id}/{aid}/submit', [CourseAssignmentApiController::class, 'submit'])->where($ids)->middleware('throttle:20,1');
        Route::get('/courses/{id}/{aid}/submissions', [CourseAssignmentApiController::class, 'submissions'])->where($ids);
        Route::get('/courses/{id}/{aid}/submissions/{sid}/file', [CourseAssignmentApiController::class, 'submissionFile'])->where($ids);
        Route::put('/courses/{id}/{aid}/submissions/{sid}/grade', [CourseAssignmentApiController::class, 'grade'])->where($ids)->middleware('throttle:120,1');
    });

    // الحضور والغياب (QR متغيّر + جيوفنس الكلية / مكان المدرّس). مديرو المادة يفتحوا ويديروا، والطالب يسجّل ويشوف نسبته.
    Route::prefix('attendance')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        Route::post('/check-in', [CourseAttendanceApiController::class, 'checkIn'])->middleware('throttle:20,1');
        Route::get('/my', [CourseAttendanceApiController::class, 'mine']);
        Route::get('/locations', [CourseAttendanceApiController::class, 'locations']);
        Route::put('/locations/{facultyId}', [CourseAttendanceApiController::class, 'setLocation'])->where('facultyId', '[0-9]+');
        Route::get('/courses/{id}/windows', [CourseAttendanceApiController::class, 'windows'])->where('id', '[0-9]+');
        Route::post('/courses/{id}/windows', [CourseAttendanceApiController::class, 'open'])->where('id', '[0-9]+')->middleware('throttle:20,1');
        Route::get('/courses/{id}/report', [CourseAttendanceApiController::class, 'report'])->where('id', '[0-9]+');
        Route::get('/windows/{wid}', [CourseAttendanceApiController::class, 'show'])->where('wid', '[0-9]+');
        Route::get('/windows/{wid}/qr', [CourseAttendanceApiController::class, 'qr'])->where('wid', '[0-9]+')->middleware('throttle:60,1');
        Route::post('/windows/{wid}/close', [CourseAttendanceApiController::class, 'close'])->where('wid', '[0-9]+');
        Route::put('/windows/{wid}/students/{sid}', [CourseAttendanceApiController::class, 'mark'])->where(['wid' => '[0-9]+', 'sid' => '[0-9]+']);
    });

    // كشف الدرجات والـ GPA: نظام التقديرات (أدمن/جامعة/كلية) + درجة المقرر النهائية (مديرو المادة) + كشف الطالب.
    Route::prefix('grading')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        $ids = ['id' => '[0-9]+', 'cid' => '[0-9]+', 'sid' => '[0-9]+'];
        Route::get('/scale', [GradingApiController::class, 'scaleShow']);
        Route::put('/scale', [GradingApiController::class, 'scaleSave'])->middleware('throttle:30,1');
        Route::delete('/scale', [GradingApiController::class, 'scaleReset'])->middleware('throttle:10,1');
        Route::get('/transcript', [GradingApiController::class, 'transcript']);
        Route::get('/transcript/pdf', [GradingApiController::class, 'transcriptPdf'])->middleware('throttle:20,1');
        Route::get('/courses/{id}', [GradingApiController::class, 'setup'])->where($ids);
        Route::put('/courses/{id}/components', [GradingApiController::class, 'saveComponents'])->where($ids)->middleware('throttle:30,1');
        Route::get('/courses/{id}/components/{cid}/scores', [GradingApiController::class, 'manualScores'])->where($ids);
        Route::put('/courses/{id}/components/{cid}/scores', [GradingApiController::class, 'saveManualScores'])->where($ids)->middleware('throttle:60,1');
        Route::get('/courses/{id}/sheet', [GradingApiController::class, 'sheet'])->where($ids);
        Route::post('/courses/{id}/calculate', [GradingApiController::class, 'calculate'])->where($ids)->middleware('throttle:20,1');
        Route::put('/courses/{id}/students/{sid}', [GradingApiController::class, 'override'])->where($ids)->middleware('throttle:120,1');
        Route::post('/courses/{id}/publish', [GradingApiController::class, 'publish'])->where($ids)->middleware('throttle:20,1');
        Route::post('/courses/{id}/unpublish', [GradingApiController::class, 'unpublish'])->where($ids)->middleware('throttle:20,1');
        Route::get('/courses/{id}/export', [GradingApiController::class, 'export'])->where($ids);
    });

    // التسجيل الأكاديمي: فترات التسجيل + سياسة الساعات/المتطلبات/التعارض + حالة الطالب والكتالوج.
    Route::prefix('registration')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        Route::get('/status', [RegistrationApiController::class, 'status']);
        Route::get('/manage', [RegistrationApiController::class, 'manage']);
        Route::put('/policy', [RegistrationApiController::class, 'savePolicy'])->middleware('throttle:30,1');
        Route::post('/periods', [RegistrationApiController::class, 'createPeriod'])->middleware('throttle:30,1');
        Route::put('/periods/{pid}', [RegistrationApiController::class, 'updatePeriod'])->where('pid', '[0-9]+')->middleware('throttle:30,1');
        Route::delete('/periods/{pid}', [RegistrationApiController::class, 'deletePeriod'])->where('pid', '[0-9]+');
        Route::get('/courses/{id}/prerequisites', [RegistrationApiController::class, 'prerequisites'])->where('id', '[0-9]+');
        Route::put('/courses/{id}/prerequisites', [RegistrationApiController::class, 'savePrerequisites'])->where('id', '[0-9]+')->middleware('throttle:30,1');
    });

    // Courses (المواد): university / faculty / doctor manage them, students enrol, doctors see the roster.
    Route::prefix('courses')->middleware(['uip.auth', 'uip.staff_alias'])->group(function () {
        Route::get('/', [CourseApiController::class, 'index']);
        Route::post('/', [CourseApiController::class, 'store']);
        Route::get('/meta', [CourseApiController::class, 'meta']);
        // Enrol / drop requests (student -> university + faculty + course staff; first approval applies it).
        Route::get('/requests', [CourseApiController::class, 'requestsIndex']);
        Route::post('/requests/{rid}/approve', [CourseApiController::class, 'requestApprove'])->where('rid', '[0-9]+');
        Route::post('/requests/{rid}/reject', [CourseApiController::class, 'requestReject'])->where('rid', '[0-9]+');
        Route::post('/requests/{rid}/cancel', [CourseApiController::class, 'requestCancel'])->where('rid', '[0-9]+');
        Route::get('/requests/{rid}/attachment', [CourseApiController::class, 'requestAttachment'])->where('rid', '[0-9]+');
        // محتوى المادة (محاضرات / فيديوهات / صور / ملفات / منشورات / نتائج / إعلانات امتحانات) + حفظ الطالب.
        Route::get('/materials/saved', [CourseMaterialsApiController::class, 'saved']);
        // جدول المحاضرات (Study Time Table): الجدول الأسبوعي حسب الدور + إدارة حصص المادة.
        Route::get('/timetable', [CourseTimetableApiController::class, 'index']);
        Route::get('/{id}', [CourseApiController::class, 'show'])->where('id', '[0-9]+');
        Route::patch('/{id}', [CourseApiController::class, 'update'])->where('id', '[0-9]+');
        Route::post('/{id}/archive', [CourseApiController::class, 'archive'])->where('id', '[0-9]+');
        Route::post('/{id}/restore', [CourseApiController::class, 'restore'])->where('id', '[0-9]+');
        Route::post('/{id}/staff', [CourseApiController::class, 'assignStaff'])->where('id', '[0-9]+');
        Route::delete('/{id}/staff/{staffId}', [CourseApiController::class, 'removeStaff'])->where(['id' => '[0-9]+', 'staffId' => '[0-9]+']);
        Route::get('/{id}/students', [CourseApiController::class, 'roster'])->where('id', '[0-9]+');
        Route::get('/{id}/students/candidates', [CourseApiController::class, 'candidates'])->where('id', '[0-9]+');
        Route::post('/{id}/students', [CourseApiController::class, 'addStudents'])->where('id', '[0-9]+');
        Route::delete('/{id}/students/{studentId}', [CourseApiController::class, 'removeStudent'])->where(['id' => '[0-9]+', 'studentId' => '[0-9]+']);
        Route::get('/{id}/sessions', [CourseTimetableApiController::class, 'forCourse'])->where('id', '[0-9]+');
        Route::post('/{id}/sessions', [CourseTimetableApiController::class, 'store'])->where('id', '[0-9]+')->middleware('throttle:60,1');
        Route::patch('/{id}/sessions/{sid}', [CourseTimetableApiController::class, 'update'])->where(['id' => '[0-9]+', 'sid' => '[0-9]+'])->middleware('throttle:60,1');
        Route::delete('/{id}/sessions/{sid}', [CourseTimetableApiController::class, 'destroy'])->where(['id' => '[0-9]+', 'sid' => '[0-9]+']);
        Route::get('/{id}/materials', [CourseMaterialsApiController::class, 'index'])->where('id', '[0-9]+');
        Route::post('/{id}/materials', [CourseMaterialsApiController::class, 'store'])->where('id', '[0-9]+')->middleware('throttle:30,1');
        // التعديل بـ POST (multipart) عشان PHP مبيقراش multipart على PATCH/PUT؛ و PATCH للـ JSON.
        Route::post('/{id}/materials/{mid}', [CourseMaterialsApiController::class, 'update'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+'])->middleware('throttle:30,1');
        Route::patch('/{id}/materials/{mid}', [CourseMaterialsApiController::class, 'update'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+']);
        Route::delete('/{id}/materials/{mid}', [CourseMaterialsApiController::class, 'destroy'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+']);
        Route::get('/{id}/materials/{mid}/file', [CourseMaterialsApiController::class, 'file'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+']);
        Route::put('/{id}/materials/{mid}/save', [CourseMaterialsApiController::class, 'save'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+']);
        Route::delete('/{id}/materials/{mid}/save', [CourseMaterialsApiController::class, 'unsave'])->where(['id' => '[0-9]+', 'mid' => '[0-9]+']);
        Route::post('/{id}/enroll', [CourseApiController::class, 'enroll'])->where('id', '[0-9]+');
        Route::post('/{id}/unenroll', [CourseApiController::class, 'unenroll'])->where('id', '[0-9]+');
        Route::delete('/{id}/enroll', [CourseApiController::class, 'unenroll'])->where('id', '[0-9]+');

        // بند 12 — دفتر درجات المقرر (أوزان الامتحانات → درجة المقرر). المدرس/الكلية/الجامعة اللي بيديروا المقرر؛ الطالب درجته هو بس.
        Route::get('/{id}/gradebook', [CourseGradebookApiController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/{id}/gradebook/items', [CourseGradebookApiController::class, 'saveItems'])->where('id', '[0-9]+')->middleware('throttle:30,1');
        Route::get('/{id}/my-grade', [CourseGradebookApiController::class, 'mine'])->where('id', '[0-9]+');
    });

    // Link accounts that already exist on the platform to a university/faculty (instead of inviting new ones).
    Route::prefix('people')->middleware('uip.auth')->group(function () {
        Route::get('/lookup', [AccountLinkApiController::class, 'lookup']);
        Route::post('/link/student', [AccountLinkApiController::class, 'linkStudent']);
        Route::post('/link/academic-staff', [AccountLinkApiController::class, 'linkAcademicStaff']);
        Route::post('/link/supervisor', [AccountLinkApiController::class, 'linkSupervisor']);
    });

    // Bulk student actions (CSV/XLSX import, move to group) — called by the university/faculty students pages.
    Route::prefix('bulk/students')->middleware('uip.auth')->group(function () {
        Route::post('/import', [BulkApiController::class, 'studentsImport']);
        Route::post('/update', [BulkApiController::class, 'studentsUpdate']);
    });

    // بند 16 — Groups (Student Groups/Teams، migration 099/122). سطح
    // /api/v1/groups/* — جامعة مالكة المجموعات (CRUD كامل + عضوية)، كلية
    // بترى/تنشئ بس (مفيش faculty_id على student_groups)، طالب بيشوف
    // مجموعته هو بس. راجع GroupsApiController's docblock.
    Route::prefix('groups')->middleware('uip.auth')->group(function () {
        Route::get('/', [GroupsApiController::class, 'index']);
        Route::post('/', [GroupsApiController::class, 'store']);

        Route::get('/{id}', [GroupsApiController::class, 'show']);
        Route::patch('/{id}', [GroupsApiController::class, 'update']);
        Route::delete('/{id}', [GroupsApiController::class, 'destroy']);

        Route::get('/{id}/members', [GroupsApiController::class, 'members']);
        Route::post('/{id}/members', [GroupsApiController::class, 'addMembers']);
        Route::delete('/{id}/members/{studentId}', [GroupsApiController::class, 'removeMember']);

        Route::post('/{id}/invite', [GroupsApiController::class, 'invite']);
    });

    // سطح إعدادات /api/v1/student/settings/* لحساب لوجين الطالب — نفس نمط
    // faculty/settings فوق. راجع StudentSettingsApiController's docblock.
    Route::prefix('student/settings')->middleware('uip.auth')->group(function () {
        Route::get('/', [StudentSettingsApiController::class, 'index']);
        Route::patch('/preferences', [StudentSettingsApiController::class, 'updatePreferences']);
        Route::patch('/notifications', [StudentSettingsApiController::class, 'updateNotificationPreferences']);
        Route::patch('/messaging-privacy', [StudentSettingsApiController::class, 'updateMessagingPrivacy']);
        Route::patch('/password', [StudentSettingsApiController::class, 'updatePassword']);
        // حذف الحساب بنفسه: كلمة السر -> كود OTP على الإيميل -> تأكيد (soft delete، قابل للاسترجاع).
        Route::post('/delete-account/send-otp', [StudentSettingsApiController::class, 'deleteAccountSendOtp'])->middleware('throttle:6,1');
        Route::post('/delete-account', [StudentSettingsApiController::class, 'deleteAccount'])->middleware('throttle:10,1');
        Route::post('/2fa/setup', [StudentSettingsApiController::class, 'twoFactorSetup']);
        Route::post('/2fa/confirm', [StudentSettingsApiController::class, 'twoFactorConfirm']);
        Route::post('/2fa/disable', [StudentSettingsApiController::class, 'twoFactorDisable']);
    });

    // بند 25 — "My Contacts" للطالب (StudentContacts.jsx). راجع
    // ContactsApiController's docblock.
    Route::prefix('contacts')->middleware('uip.auth')->group(function () {
        Route::get('/', [ContactsApiController::class, 'index']);
    });

    // Group Hub (StudentGroupHub.jsx — Timeline/Announcements/Files/
    // Tasks لمجموعة الطالب). راجع GroupHubApiController's docblock.
    Route::prefix('group-hub')->middleware('uip.auth')->group(function () {
        Route::get('/', [GroupHubApiController::class, 'index']);

        Route::post('/announcements', [GroupHubApiController::class, 'postAnnouncement']);
        Route::delete('/announcements/{id}', [GroupHubApiController::class, 'deleteAnnouncement']);

        Route::post('/files', [GroupHubApiController::class, 'uploadFile']);
        Route::delete('/files/{id}', [GroupHubApiController::class, 'deleteFile']);

        Route::post('/tasks', [GroupHubApiController::class, 'createTask']);
        Route::patch('/tasks/{id}/status', [GroupHubApiController::class, 'setTaskStatus']);
        Route::delete('/tasks/{id}', [GroupHubApiController::class, 'deleteTask']);
    });

    // Group Chat (StudentGroupChat.jsx — get-or-create محادثة المجموعة
    // الجماعية بتاعة الطالب، إرسال الرسائل نفسه بيعدي على سطح
    // /messaging/conversations/{id}/* الموجود بالفعل). راجع
    // StudentGroupChatApiController's docblock.
    Route::prefix('group-chat')->middleware('uip.auth')->group(function () {
        Route::get('/', [StudentGroupChatApiController::class, 'resolve']);
    });

    // بند 22 — Announcements (University Announcements، migration
    // 113/118/122 — announcements + announcement_attachments). سطح
    // /api/v1/announcements/* واحد لدوري الجامعة (نشر/تعديل/حذف) والطالب
    // (قراءة مقيّدة بجامعته + نطاق رؤيته)، زي AnnouncementsApiController's
    // docblock بالظبط. نفس ترتيب القديم (routes/api.php سطر 913): قبل
    // مجموعة feed مباشرة.
    Route::prefix('announcements')->middleware('uip.auth')->group(function () {
        Route::get('/', [AnnouncementsApiController::class, 'index']);
        Route::post('/', [AnnouncementsApiController::class, 'store']);

        Route::get('/{id}', [AnnouncementsApiController::class, 'show']);
        Route::patch('/{id}', [AnnouncementsApiController::class, 'update']);
        Route::delete('/{id}', [AnnouncementsApiController::class, 'destroy']);
    });

    // بند 17 — Feed (University Feed، migration 122 — feed_posts +
    // attachments/likes/saves/shares/comments/reports). سطح /api/v1/feed/*
    // واحد لدوري الجامعة (نشر/إدارة/مراجعة) والطالب (قراءة/تفاعل)، زي
    // FeedApiController's docblock بالظبط. feed.comment_rate_limit إضافي
    // بس على POST /{id}/comments — لازم يتحط بعد uip.auth (محتاج
    // uip_user_id اللي uip.auth بيحطها).
    Route::prefix('feed')->middleware('uip.auth')->group(function () {
        Route::get('/', [FeedApiController::class, 'index']);
        Route::post('/', [FeedApiController::class, 'store']);

        // لازم تتسجل قبل /{id} عشان Laravel ميحاولش يفسّرها كـ {id}.
        Route::get('/reports', [FeedApiController::class, 'reports']);
        Route::post('/reports/{id}/resolve', [FeedApiController::class, 'resolveReport']);
        Route::delete('/comments/{commentId}', [FeedApiController::class, 'deleteComment']);

        Route::get('/{id}', [FeedApiController::class, 'show']);
        Route::patch('/{id}', [FeedApiController::class, 'update']);
        Route::delete('/{id}', [FeedApiController::class, 'destroy']);

        Route::post('/{id}/publish', [FeedApiController::class, 'publishDraft']);
        Route::post('/{id}/unpublish', [FeedApiController::class, 'unpublish']);
        Route::post('/{id}/pin', [FeedApiController::class, 'pin']);
        Route::post('/{id}/unpin', [FeedApiController::class, 'unpin']);

        Route::post('/{id}/like', [FeedApiController::class, 'like']);
        Route::post('/{id}/save', [FeedApiController::class, 'save']);
        Route::get('/{id}/comments', [FeedApiController::class, 'comments']);
        Route::post('/{id}/comments', [FeedApiController::class, 'comment'])->middleware('feed.comment_rate_limit');
        Route::post('/{id}/share', [FeedApiController::class, 'share']);
        Route::post('/{id}/report', [FeedApiController::class, 'report']);
    });

    // بند 23 — Analytics (سطح /api/v1/analytics/* واحد مشترك بين admin/
    // university، AnalyticsApiController's docblock بالظبط). كل روت هنا
    // بيتأكد من uip_role جوّه نفسه (مش عبر middleware منفصل) لأن admin
    // وuniversity بياخدوا بيانات مختلفة تمامًا لنفس الـendpoint.
    Route::prefix('analytics')->middleware(['uip.auth', 'uip.can:analytics.view'])->group(function () {
        Route::get('/overview', [AnalyticsApiController::class, 'overview']);
        Route::get('/trends', [AnalyticsApiController::class, 'trends']);
        Route::get('/innovation-statistics', [AnalyticsApiController::class, 'innovationStatistics']);
    });

    // بند 24 — Data Analysis Portal. مدخل البورتال + batch 1 (Saved
    // Dashboards) + batch 2 (Exports التنفيذ الفعلي + Segments) خلصوا.
    // باقي البورتال (kpis, reports, forecasting, advanced analytics,
    // explorer, query builder, ...) جاي مع بنودها الفرعية.
    Route::prefix('data-analysis')->middleware(['uip.auth', 'uip.can:data_analysis.dashboard.view'])->group(function () {
        Route::get('/dashboard', [DataAnalysisDashboardApiController::class, 'index']);

        // تحليلات الامتحانات والدرجات (قراءة بس) — الروتس الحرفية قبل أي {id}.
        Route::prefix('exams')->group(function () {
            Route::get('/filters', [DataAnalysisExamsApiController::class, 'filters']);
            Route::get('/overview', [DataAnalysisExamsApiController::class, 'overview']);
            Route::get('/students', [DataAnalysisExamsApiController::class, 'students']);
            Route::get('/exam/{id}', [DataAnalysisExamsApiController::class, 'showExam'])->where('id', '[0-9]+');
        });

        // بند 24 batch 1 — Saved Dashboards. catalog لازم يتسجل قبل
        // {id} عشان "catalog" متتاخدش على إنها id.
        Route::prefix('dashboards')->middleware('uip.can:write:data_analysis.dashboards.customize')->group(function () {
            Route::get('/', [SavedDashboardsApiController::class, 'index']);
            Route::get('/catalog', [SavedDashboardsApiController::class, 'catalog']);
            Route::post('/', [SavedDashboardsApiController::class, 'store']);
            Route::get('/{id}', [SavedDashboardsApiController::class, 'show']);
            Route::put('/{id}', [SavedDashboardsApiController::class, 'update']);
            Route::patch('/{id}', [SavedDashboardsApiController::class, 'update']);
            Route::delete('/{id}', [SavedDashboardsApiController::class, 'destroy']);
            Route::post('/{id}/duplicate', [SavedDashboardsApiController::class, 'duplicate']);
            Route::post('/{id}/share', [SavedDashboardsApiController::class, 'toggleShare']);
            Route::post('/{id}/archive', [SavedDashboardsApiController::class, 'archive']);
            Route::post('/{id}/unarchive', [SavedDashboardsApiController::class, 'unarchive']);
            Route::get('/{id}/export', [SavedDashboardsApiController::class, 'export']);
        });

        // بند 24 batch 2 — Exports (تنفيذ فعلي: Export Data + Batch
        // Export + Email Export + Scheduled Exports، enhancement spec
        // section 7). الروتس الحرفية (delete-selected/delete-all/
        // schedules) لازم تتسجل قبل DELETE /{id} عشان مايتلخبطوش مع
        // {id} — نفس ترتيب dashboards/catalog فوق.
        Route::prefix('exports')->middleware('uip.can:data_analysis.export')->group(function () {
            Route::get('/', [DataAnalysisExportsApiController::class, 'index']);
            Route::post('/', [DataAnalysisExportsApiController::class, 'store']);
            Route::post('/delete-selected', [DataAnalysisExportsApiController::class, 'destroySelected']);
            Route::post('/delete-all', [DataAnalysisExportsApiController::class, 'destroyAll']);

            Route::post('/schedules', [DataAnalysisExportsApiController::class, 'scheduleCreate']);
            Route::patch('/schedules/{id}', [DataAnalysisExportsApiController::class, 'scheduleUpdate']);
            Route::post('/schedules/{id}/toggle', [DataAnalysisExportsApiController::class, 'scheduleToggle']);
            Route::post('/schedules/{id}/run-now', [DataAnalysisExportsApiController::class, 'scheduleRunNow']);
            Route::delete('/schedules/{id}', [DataAnalysisExportsApiController::class, 'scheduleDelete']);

            Route::get('/{id}/preview', [DataAnalysisExportsApiController::class, 'preview']);
            Route::delete('/{id}', [DataAnalysisExportsApiController::class, 'destroy']);
        });

        // بند 24 batch 2 — Data Segments (فلاتر محفوظة قابلة لإعادة
        // الاستخدام فوق users/projects).
        Route::prefix('segments')->middleware('uip.can:data_analysis.segments.manage')->group(function () {
            Route::get('/', [DataAnalysisSegmentsApiController::class, 'index']);
            Route::post('/', [DataAnalysisSegmentsApiController::class, 'store']);
            Route::delete('/{id}', [DataAnalysisSegmentsApiController::class, 'destroy']);
        });

        // بند 24 batch 3 — Analytics العميقة (KPIs, Advanced Analytics,
        // Forecasting, Data Quality). كلهم read-heavy وبيبنوا على نفس
        // الست catalog datasets/جداول المنصة الحقيقية زي الداشبورد
        // الرئيسي. الروتس الحرفية (archive/restore/record-value/explain/
        // export) لازم تتسجل قبل أي {id}/{key} عام — نفس ترتيب
        // dashboards/catalog وexports/schedules فوق.
        Route::prefix('kpis')->middleware('uip.can:data_analysis.trends.view')->group(function () {
            Route::get('/', [DataAnalysisKpisApiController::class, 'index']);
            Route::post('/', [DataAnalysisKpisApiController::class, 'store']);
            Route::patch('/{id}', [DataAnalysisKpisApiController::class, 'update']);
            Route::post('/{id}/record-value', [DataAnalysisKpisApiController::class, 'recordValue']);
            Route::post('/{id}/archive', [DataAnalysisKpisApiController::class, 'archive']);
            Route::post('/{id}/restore', [DataAnalysisKpisApiController::class, 'restore']);
            Route::delete('/{id}', [DataAnalysisKpisApiController::class, 'destroy']);
        });

        Route::prefix('advanced-analytics')->middleware('uip.can:data_analysis.trends.view')->group(function () {
            Route::get('/', [DataAnalysisAdvancedAnalyticsApiController::class, 'index']);
            Route::get('/{key}', [DataAnalysisAdvancedAnalyticsApiController::class, 'show']);
        });

        Route::prefix('forecasting')->middleware('uip.can:data_analysis.trends.view')->group(function () {
            Route::get('/', [DataAnalysisForecastingApiController::class, 'index']);
            Route::get('/{key}', [DataAnalysisForecastingApiController::class, 'show']);
            Route::post('/{key}/explain', [DataAnalysisForecastingApiController::class, 'explain']);
        });

        Route::prefix('data-quality')->group(function () {
            Route::get('/', [DataAnalysisDataQualityApiController::class, 'index']);
            Route::get('/{key}/export', [DataAnalysisDataQualityApiController::class, 'export']);
        });

        // بند 24 batch 4 — أدوات الاستعلام (Data Explorer الكامل + SQL
        // Query Builder). نفس طبيعة ونفس مخاطر الأمان (فحص/تفويض SQL) —
        // القيود الحرفية (favorite/saved/history/export/run) لازم
        // تتسجل قبل {key}/{id} العامة.
        Route::prefix('data-explorer')->group(function () {
            Route::get('/', [DataAnalysisExplorerApiController::class, 'index']);
            Route::get('/{key}', [DataAnalysisExplorerApiController::class, 'show']);
            Route::post('/{key}/favorite', [DataAnalysisExplorerApiController::class, 'toggleFavorite']);
        });

        Route::prefix('queries')->group(function () {
            Route::get('/', [DataAnalysisQueriesApiController::class, 'index']);
            Route::post('/run', [DataAnalysisQueriesApiController::class, 'run']);
            Route::post('/save', [DataAnalysisQueriesApiController::class, 'save']);
            Route::get('/history', [DataAnalysisQueriesApiController::class, 'history']);
            Route::post('/export', [DataAnalysisQueriesApiController::class, 'export']);
            Route::get('/saved/{id}', [DataAnalysisQueriesApiController::class, 'showSaved']);
            Route::delete('/saved/{id}', [DataAnalysisQueriesApiController::class, 'destroySaved']);
        });

        // بند 24 batch 5 — Reports Suite (Reports, Report Files, Report
        // Comments, AI Insights، enhancement spec sections 1/4/12).
        // reports/schedules قبل reports/{id} DELETE — نفس ترتيب
        // dashboards/catalog وexports/schedules فوق. report-files/{id}/
        // comments بيسجل هنا (تحت report-files)، وباقي أفعال الكومنت
        // (note/unnote/resolve/reopen) بيسجلوا تحت prefix('comments')
        // منفصل لأنها بتاخد comment id مش report-file id.
        Route::prefix('reports')->middleware('uip.can:data_analysis.reports.manage')->group(function () {
            Route::get('/', [DataAnalysisReportsApiController::class, 'index']);
            Route::post('/generate', [DataAnalysisReportsApiController::class, 'generate']);
            Route::post('/delete-selected', [DataAnalysisReportsApiController::class, 'destroySelected']);
            Route::post('/delete-all', [DataAnalysisReportsApiController::class, 'destroyAll']);

            Route::post('/schedules', [DataAnalysisReportsApiController::class, 'scheduleCreate']);
            Route::patch('/schedules/{id}', [DataAnalysisReportsApiController::class, 'scheduleUpdate']);
            Route::post('/schedules/{id}/toggle', [DataAnalysisReportsApiController::class, 'scheduleToggle']);
            Route::post('/schedules/{id}/run-now', [DataAnalysisReportsApiController::class, 'scheduleRunNow']);
            Route::delete('/schedules/{id}', [DataAnalysisReportsApiController::class, 'scheduleDelete']);

            Route::delete('/{id}', [DataAnalysisReportsApiController::class, 'destroy']);
        });

        Route::prefix('report-files')->middleware('uip.can:data_analysis.reports.manage')->group(function () {
            Route::get('/', [DataAnalysisReportFilesApiController::class, 'index']);
            Route::post('/', [DataAnalysisReportFilesApiController::class, 'store']);
            Route::get('/{id}', [DataAnalysisReportFilesApiController::class, 'show']);
            Route::get('/{id}/download', [DataAnalysisReportFilesApiController::class, 'download']);
            Route::get('/{id}/preview', [DataAnalysisReportFilesApiController::class, 'preview']);
            Route::post('/{id}/replace', [DataAnalysisReportFilesApiController::class, 'replace']);
            Route::post('/{id}/archive', [DataAnalysisReportFilesApiController::class, 'archive']);
            Route::post('/{id}/unarchive', [DataAnalysisReportFilesApiController::class, 'unarchive']);
            Route::delete('/{id}', [DataAnalysisReportFilesApiController::class, 'destroy']);

            Route::post('/{id}/comments', [DataAnalysisReportCommentsApiController::class, 'store']);
        });

        Route::prefix('comments')->middleware('uip.can:data_analysis.reports.manage')->group(function () {
            Route::post('/{id}/note', [DataAnalysisReportCommentsApiController::class, 'markNote']);
            Route::post('/{id}/unnote', [DataAnalysisReportCommentsApiController::class, 'unmarkNote']);
            Route::post('/{id}/resolve', [DataAnalysisReportCommentsApiController::class, 'resolve']);
            Route::post('/{id}/reopen', [DataAnalysisReportCommentsApiController::class, 'reopen']);
        });

        Route::prefix('ai-insights')->middleware('uip.can:data_analysis.trends.view')->group(function () {
            Route::get('/', [DataAnalysisAiInsightsApiController::class, 'index']);
            Route::post('/regenerate', [DataAnalysisAiInsightsApiController::class, 'regenerate']);
            // تصدير تقرير + إدارة سجل التوليد. المسارات الحرفية قبل {id}.
            Route::get('/export', [DataAnalysisAiInsightsApiController::class, 'export']);
            Route::post('/delete-selected', [DataAnalysisAiInsightsApiController::class, 'destroySelected']);
            Route::post('/delete-all', [DataAnalysisAiInsightsApiController::class, 'destroyAll']);
            Route::delete('/{id}', [DataAnalysisAiInsightsApiController::class, 'destroy'])->where('id', '[0-9]+');
        });

        // بند 24 batch 6 — Team Workspace (Collaboration، enhancement
        // spec section 12). Messaging/Notifications الخاصين بالبورتال
        // مش محتاجين سطح مستقل — الفرونت بيستخدم /api/v1/messaging/*
        // و/api/v1/notifications/* العامين المشتركين مباشرة (بند 18/19،
        // منقولين بالفعل فوق).
        Route::get('/workspace', [DataAnalysisWorkspaceApiController::class, 'index']);

        // بند 24 batch 7 — Search. بحث platform-wide واحد (projects)،
        // بيغطي صفحة النتائج الكاملة وquick()
        // بتاعة الـ topbar palette القديمة، نفس DataAnalysisSearchApiController
        // القديمة بالظبط.
        Route::prefix('search')->group(function () {
            Route::get('/', [DataAnalysisSearchApiController::class, 'index']);
            Route::get('/quick', [DataAnalysisSearchApiController::class, 'quick']);
        });

        // بند 24 batch 8 — Settings + Profile (آخر batch في بند 24).
        // Profile بتاعة الفرونت (DataAnalysisProfile.jsx) بتقرا من نفس
        // /settings دي — مفيهاش سطح مستقل، الصورة الشخصية بس ليها
        // endpoint افتراضي هنا (/settings/avatar).
        Route::prefix('settings')->group(function () {
            Route::get('/', [DataAnalysisSettingsApiController::class, 'index']);
            Route::post('/avatar', [DataAnalysisSettingsApiController::class, 'uploadAvatar']);
            Route::patch('/notifications', [DataAnalysisSettingsApiController::class, 'updateNotificationPreferences']);
            Route::patch('/profile', [DataAnalysisSettingsApiController::class, 'updateProfile']);
            Route::patch('/preferences', [DataAnalysisSettingsApiController::class, 'updatePreferences']);
            Route::post('/theme/toggle', [DataAnalysisSettingsApiController::class, 'toggleTheme']);
            Route::patch('/password', [DataAnalysisSettingsApiController::class, 'updatePassword']);
            Route::post('/2fa/setup', [DataAnalysisSettingsApiController::class, 'twoFactorSetup']);
            Route::post('/2fa/confirm', [DataAnalysisSettingsApiController::class, 'twoFactorConfirm']);
            Route::post('/2fa/disable', [DataAnalysisSettingsApiController::class, 'twoFactorDisable']);
        });
    });

    // بند 5 — University portal (16 endpoint، UNIVERSITY_PORTAL_API_CONTRACT.md).
    // كل الروتس هنا محتاجة uip.auth + الجامعة اللي عاملة login بس تشوف/تتصرف
    // في الطوابير بتاعتها (مقيّد جوه الكنترولرز/الريبوزيتوريز نفسها عبر
    // universityIdForUser()، مش عبر middleware منفصل — يطابق القديم).
    Route::prefix('university')->middleware('uip.auth')->group(function () {
        Route::get('/approvals', [UniversityApprovalsApiController::class, 'index']);
        Route::get('/approvals/{id}', [UniversityApprovalsApiController::class, 'show']);
        Route::post('/approvals/{id}/approve', [UniversityApprovalsApiController::class, 'approve']);
        Route::post('/approvals/{id}/reject', [UniversityApprovalsApiController::class, 'reject']);
        Route::post('/approvals/{id}/request-changes', [UniversityApprovalsApiController::class, 'requestChanges']);
        Route::post('/approvals/{id}/grade', [UniversityApprovalsApiController::class, 'saveGrade']);
        Route::post('/approvals/{id}/grade/reopen', [UniversityApprovalsApiController::class, 'reopenGrade']);
        Route::post('/approvals/{id}/publish', [UniversityApprovalsApiController::class, 'publish']);
        Route::post('/approvals/{id}/unpublish', [UniversityApprovalsApiController::class, 'unpublish']);

        Route::get('/join-requests', [UniversityJoinRequestsApiController::class, 'index']);
        Route::get('/join-requests/{id}', [UniversityJoinRequestsApiController::class, 'show'])->where('id', '[0-9]+');
        Route::post('/join-requests/{id}/approve', [UniversityJoinRequestsApiController::class, 'approve']);
        Route::post('/join-requests/{id}/reject', [UniversityJoinRequestsApiController::class, 'reject']);

        Route::get('/verification', [UniversityVerificationApiController::class, 'index']);
        Route::post('/verification/documents', [UniversityVerificationApiController::class, 'submitDocument']);
    });

    // -- Patents (بند 14/Future — Patent Portal، طالب أو باحث). كانت
    // السبب في "The route api/v1/patents could not be found".
    Route::prefix('patents')->middleware('uip.auth')->group(function () {
        Route::get('/', [PatentsApiController::class, 'index']);
        Route::post('/', [PatentsApiController::class, 'store']);
    });

    // Push للموبايل: تسجيل/إلغاء توكن الجهاز (أي يوزر مسجّل دخول؛ من غير uip.can لأنه مش إدارة إشعارات).
    Route::prefix('push')->middleware('uip.auth')->group(function () {
        Route::post('/devices', [\App\Http\Controllers\Api\PushDevicesApiController::class, 'register']);
        Route::delete('/devices', [\App\Http\Controllers\Api\PushDevicesApiController::class, 'unregister']);
    });

    // -- بند 19: Notifications — Notification Center الكامل لكل الأدوار
    // (كنترولر موحّد، نفس نمط messaging). ملحوظة ترتيب الراوتس: /read-all
    // و /read و /bulk لازم يتسجلوا قبل /{id} عشان Laravel ميحاولش يفسّر
    // "read-all"/"read"/"bulk" كإنهم {id}.
    Route::prefix('notifications')->middleware(['uip.auth', 'uip.can:write:notifications.manage'])->group(function () {
        Route::get('/', [NotificationsApiController::class, 'index']);
        Route::get('/counts', [NotificationsApiController::class, 'counts']);
        Route::post('/read-all', [NotificationsApiController::class, 'markAllRead']);
        Route::delete('/read', [NotificationsApiController::class, 'deleteAllRead']);
        Route::post('/bulk', [NotificationsApiController::class, 'bulk']);

        Route::patch('/{id}/read', [NotificationsApiController::class, 'markRead']);
        Route::patch('/{id}/unread', [NotificationsApiController::class, 'markUnread']);
        Route::patch('/{id}/pin', [NotificationsApiController::class, 'pin']);
        Route::patch('/{id}/unpin', [NotificationsApiController::class, 'unpin']);
        Route::patch('/{id}/important', [NotificationsApiController::class, 'markImportant']);
        Route::patch('/{id}/unimportant', [NotificationsApiController::class, 'unmarkImportant']);
        Route::patch('/{id}/archive', [NotificationsApiController::class, 'archive']);
        Route::patch('/{id}/unarchive', [NotificationsApiController::class, 'unarchive']);
        Route::post('/{id}/restore', [NotificationsApiController::class, 'restore']);
        Route::delete('/{id}/purge', [NotificationsApiController::class, 'purge']);
        Route::delete('/{id}', [NotificationsApiController::class, 'delete']);
    });

    // -- بند 18: Messaging — كنترولر موحّد لكل البورتالات (زي Common\
    // MessagingController القديمة بالظبط). فك ربط
    // student-group-chat هنا: بتعمل
    // get-or-create للمحادثة بتاعتها بس، وبعد كده كل الإرسال/التفاعل
    // بيمر من هنا (نفس الـ conversation id).
    // خصوصية الصورة الشخصية — لأي دور مسجّل دخول (مش مربوطة بصلاحية المراسلة).
    Route::prefix('account/photo-privacy')->middleware('uip.auth')->group(function () {
        Route::get('/', [AccountPrivacyApiController::class, 'show']);
        Route::patch('/', [AccountPrivacyApiController::class, 'update']);
    });

    // إعدادات سطح المكتب ("جهازي") + باسورد شاشة القفل — بتتابع الطالب على أي جهاز. راجع DesktopSettingsApiController.
    Route::prefix('desktop')->middleware('uip.auth')->group(function () {
        Route::get('/settings', [DesktopSettingsApiController::class, 'show'])->middleware('throttle:120,1');
        Route::put('/settings', [DesktopSettingsApiController::class, 'update'])->middleware('throttle:120,1');
        // الملاحظات اللاصقة والويدجت — DesktopWidgetsApiController
        Route::get('/widgets', [\App\Http\Controllers\Api\DesktopWidgetsApiController::class, 'show'])->middleware('throttle:120,1');
        Route::put('/widgets', [\App\Http\Controllers\Api\DesktopWidgetsApiController::class, 'update'])->middleware('throttle:120,1');
        Route::get('/wallpaper', [DesktopSettingsApiController::class, 'wallpaper'])->middleware('throttle:60,1');
        Route::put('/wallpaper', [DesktopSettingsApiController::class, 'saveWallpaper'])->middleware('throttle:20,1');
        Route::post('/lock/set', [DesktopSettingsApiController::class, 'lockSet'])->middleware('throttle:20,1');
        Route::post('/lock/verify', [DesktopSettingsApiController::class, 'lockVerify'])->middleware('throttle:30,1');
        Route::post('/lock/disable', [DesktopSettingsApiController::class, 'lockDisable'])->middleware('throttle:20,1');
        Route::post('/lock/otp', [DesktopSettingsApiController::class, 'lockOtp'])->middleware('throttle:10,1');
        Route::post('/lock/reset', [DesktopSettingsApiController::class, 'lockReset'])->middleware('throttle:20,1');
        Route::get('/reset', [DesktopSettingsApiController::class, 'resetStatus'])->middleware('throttle:60,1');
        Route::post('/reset', [DesktopSettingsApiController::class, 'reset'])->middleware('throttle:5,1');
        Route::post('/reset/undo', [DesktopSettingsApiController::class, 'resetUndo'])->middleware('throttle:10,1');
        // الأمان والأجهزة (حد الأجهزة، قفل/خروج عن بُعد، القفل التلقائي، السكون، الخروج بعد الخمول) — DesktopSecurityApiController
        Route::prefix('security')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'show'])->middleware('throttle:60,1');
            Route::put('/', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'update'])->middleware('throttle:30,1');
            Route::get('/pulse', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'pulse'])->middleware('throttle:90,1');
            Route::post('/devices/logout-others', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'logoutOthers'])->middleware('throttle:20,1');
            Route::post('/devices/lock-others', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'lockOthers'])->middleware('throttle:20,1');
            Route::post('/devices/{id}/logout', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'logoutDevice'])->where('id', '[0-9]+')->middleware('throttle:30,1');
            Route::post('/devices/{id}/lock', [\App\Http\Controllers\Api\DesktopSecurityApiController::class, 'lockDevice'])->where('id', '[0-9]+')->middleware('throttle:30,1');
        });
    });

    // متابعة بلاغات الشات — admin (الكل) / university (جامعته) / faculty (كليته). الفحص جوّه ChatSafetyService::scopeFor.
    Route::prefix('chat-reports')->middleware('uip.auth')->group(function () {
        Route::get('/', [ChatSafetyApiController::class, 'index']);
        Route::post('/{id}/status', [ChatSafetyApiController::class, 'updateStatus'])->where('id', '[0-9]+');
    });

    Route::prefix('messaging')->middleware(['uip.auth', 'uip.can:messaging.use'])->group(function () {
        Route::get('/inbox', [MessagingController::class, 'inbox']);
        // سلة المحذوفات (30 يوم): حذف متعدد/الكل، استرجاع، حذف نهائي.
        Route::get('/trash', [ConversationTrashController::class, 'index']);
        Route::post('/trash/delete', [ConversationTrashController::class, 'delete']);
        Route::post('/trash/restore', [ConversationTrashController::class, 'restore']);
        Route::post('/trash/purge', [ConversationTrashController::class, 'purge']);
        Route::get('/recipients', [MessagingController::class, 'recipients']);
        Route::get('/search', [MessagingController::class, 'search']);
        Route::get('/mentions', [MessagingController::class, 'mentions']);
        Route::get('/categories', [MessagingController::class, 'categories']);
        Route::get('/privacy', [MessagingController::class, 'privacy']);
        Route::patch('/privacy', [MessagingController::class, 'updatePrivacy']);

        Route::post('/conversations/direct', [MessagingController::class, 'startDirect']);
        Route::post('/conversations/group', [MessagingController::class, 'createGroup']);
        Route::get('/conversations/{id}', [MessagingController::class, 'thread']);
        Route::post('/conversations/{id}/rename', [MessagingController::class, 'renameGroup']);
        Route::post('/conversations/{id}/members', [MessagingController::class, 'addMember']);
        Route::delete('/conversations/{id}/members/{userId}', [MessagingController::class, 'removeMember']);
        Route::post('/conversations/{id}/members/{userId}/role', [MessagingController::class, 'setMemberRole']);

        // WhatsApp-style group administration (GroupChatController)
        Route::patch('/conversations/{id}/group', [GroupChatController::class, 'update']);
        Route::delete('/conversations/{id}/group', [GroupChatController::class, 'destroy']);
        Route::post('/conversations/{id}/group/avatar', [GroupChatController::class, 'setAvatar']);
        Route::delete('/conversations/{id}/group/avatar', [GroupChatController::class, 'removeAvatar']);
        Route::post('/conversations/{id}/members/bulk', [GroupChatController::class, 'addMembers']);
        Route::post('/conversations/{id}/members/{userId}/restrict', [GroupChatController::class, 'restrict']);
        Route::post('/conversations/{id}/transfer-owner', [GroupChatController::class, 'transferOwner']);
        Route::post('/conversations/{id}/leave', [GroupChatController::class, 'leave']);
        Route::get('/conversations/{id}/invite', [GroupChatController::class, 'invite']);
        Route::post('/conversations/{id}/invite/reset', [GroupChatController::class, 'resetInvite']);
        Route::get('/conversations/{id}/join-requests', [GroupChatController::class, 'joinRequests']);
        Route::post('/conversations/{id}/join-requests/{requestId}/approve', [GroupChatController::class, 'approveRequest']);
        Route::post('/conversations/{id}/join-requests/{requestId}/reject', [GroupChatController::class, 'rejectRequest']);
        Route::get('/groups/invite/{token}', [GroupChatController::class, 'previewInvite']);
        Route::post('/groups/invite/{token}/join', [GroupChatController::class, 'join']);
        Route::delete('/groups/invite/{token}/request', [GroupChatController::class, 'cancelRequest']);
        Route::post('/conversations/{id}/flags/{flag}', [MessagingController::class, 'setFlag']);
        Route::post('/conversations/{id}/category', [MessagingController::class, 'setCategory']);
        Route::post('/conversations/{id}/messages', [MessagingController::class, 'send']);
        // رفع الملفات الكبيرة على أجزاء (فيديو لحد MESSAGING_MAX_UPLOAD_MB) — الرسالة نفسها بتتبعت بعدها بـ uploaded_ids[].
        Route::post('/uploads', [ChunkedUploadController::class, 'init']);
        Route::get('/uploads/{id}', [ChunkedUploadController::class, 'status'])->where('id', '[a-f0-9]{32}');
        Route::post('/uploads/{id}/chunk', [ChunkedUploadController::class, 'chunk'])->where('id', '[a-f0-9]{32}');
        Route::delete('/uploads/{id}', [ChunkedUploadController::class, 'cancel'])->where('id', '[a-f0-9]{32}');
        Route::get('/conversations/{id}/pinned', [MessagingController::class, 'pinned']);
        Route::post('/conversations/{id}/read', [MessagingController::class, 'markRead']);
        Route::get('/conversations/{id}/poll', [MessagingController::class, 'poll']);
        Route::post('/conversations/{id}/poll-message', [MessagingController::class, 'createPoll']);
        // Location sharing: one-off current location + live location (sender keeps posting positions until it ends).
        Route::post('/conversations/{id}/location-message', [MessagingController::class, 'sendLocation']);
        Route::post('/conversations/{id}/live-location', [MessagingController::class, 'startLiveLocation']);
        Route::post('/messages/{id}/live-location', [MessagingController::class, 'updateLiveLocation']);
        Route::delete('/messages/{id}/live-location', [MessagingController::class, 'stopLiveLocation']);

        Route::post('/messages/{id}/edit', [MessagingController::class, 'edit']);
        Route::delete('/messages/{id}/for-me', [MessagingController::class, 'deleteForMe']);
        Route::delete('/messages/{id}/for-everyone', [MessagingController::class, 'deleteForEveryone']);
        Route::post('/messages/{id}/restore', [MessagingController::class, 'restore']);
        Route::delete('/messages/{id}/undo-send', [MessagingController::class, 'undoSend']);
        Route::get('/messages/{id}/history', [MessagingController::class, 'history']);
        Route::post('/messages/{id}/pin', [MessagingController::class, 'pin']);
        Route::post('/messages/{id}/unpin', [MessagingController::class, 'unpin']);
        Route::post('/messages/{id}/react', [MessagingController::class, 'react']);
        Route::get('/messages/{id}/receipts', [MessagingController::class, 'receipts']);
        Route::post('/messages/{id}/vote', [MessagingController::class, 'votePoll']);
        Route::get('/messages/{id}/poll-voters', [MessagingController::class, 'pollVoters']);

        // حظر المستخدمين + بلاغات الشات (ChatSafetyApiController).
        Route::get('/blocks', [ChatSafetyApiController::class, 'blocks']);
        Route::post('/blocks', [ChatSafetyApiController::class, 'block']);
        Route::delete('/blocks/{userId}', [ChatSafetyApiController::class, 'unblock'])->where('userId', '[0-9]+');
        Route::post('/reports', [ChatSafetyApiController::class, 'report']);
        Route::get('/reports/mine', [ChatSafetyApiController::class, 'myReports']);

        Route::post('/presence/heartbeat', [MessagingController::class, 'heartbeat']);
        Route::post('/presence/typing', [MessagingController::class, 'typing']);
        Route::post('/presence/lookup', [MessagingController::class, 'presence']);

        Route::get('/attachments/{id}/download', [MessagingController::class, 'downloadAttachment']);
        Route::delete('/attachments/{id}', [MessagingController::class, 'deleteAttachment']);
    });

});

Route::prefix('v1')->group(function () {
    // بند 9 — Supervisors، جزء 1: روستر مملوك للجامعة + CRUD + نطاقات
    // الإسناد + صف المشرف نفسه (SUPERVISORS_API_CONTRACT.md). /me لازم
    // يتسجل قبل /{id} — نفس ترتيب students/faculty فوق، عشان
    // Laravel ميحاولش يطابقه كـ {id}.
    Route::prefix('supervisors')->middleware('uip.auth')->group(function () {
        Route::get('/', [SupervisorsApiController::class, 'index']);
        Route::post('/', [SupervisorsApiController::class, 'store']);

        Route::get('/me', [SupervisorsApiController::class, 'me']);

        Route::get('/{id}', [SupervisorsApiController::class, 'show'])->where('id', '[0-9]+');
        Route::patch('/{id}', [SupervisorsApiController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/{id}', [SupervisorsApiController::class, 'destroy'])->where('id', '[0-9]+');
        Route::post('/{id}/activate', [SupervisorsApiController::class, 'activate'])->where('id', '[0-9]+');
        Route::post('/{id}/deactivate', [SupervisorsApiController::class, 'deactivate'])->where('id', '[0-9]+');
        Route::post('/{id}/resend', [SupervisorsApiController::class, 'resend'])->where('id', '[0-9]+');
        Route::patch('/{id}/password', [SupervisorsApiController::class, 'setPassword'])->where('id', '[0-9]+');

        Route::get('/{id}/assignments', [SupervisorsApiController::class, 'assignments'])->where('id', '[0-9]+');
        Route::post('/{id}/assignments', [SupervisorsApiController::class, 'assign'])->where('id', '[0-9]+');
        Route::delete('/{id}/assignments/{assignmentId}', [SupervisorsApiController::class, 'unassign'])
            ->where(['id' => '[0-9]+', 'assignmentId' => '[0-9]+']);
    });

    // بند 9 — Supervisors، جزء 2: إعدادات حساب لوجين المشرف نفسه (بروفايل
    // روستر للقراءة بس + نطاقاته، تفضيلات إشعارات، تغيير باسورد) — بيطابق
    // api/v1/supervisor/settings/* القديمة (SupervisorSettingsApiController).
    Route::prefix('supervisor/settings')->middleware('uip.auth')->group(function () {
        Route::get('/', [SupervisorSettingsApiController::class, 'index']);
        Route::patch('/profile', [SupervisorSettingsApiController::class, 'updateProfile']);
        Route::post('/avatar', [SupervisorSettingsApiController::class, 'uploadAvatar']);
        Route::patch('/notifications', [SupervisorSettingsApiController::class, 'updateNotificationPreferences']);
        Route::patch('/password', [SupervisorSettingsApiController::class, 'updatePassword']);
    });

    // بند دوشبورد — SupervisorDashboard.jsx كان مبني بالفعل بينادي
    // /api/v1/supervisor/dashboard من غير ما الروت يتسجل خالص. مفيش تضارب
    // مع prefix('supervisors') أو prefix('supervisor/settings') فوق —
    // أول ظهور لـ 'supervisor/dashboard'.
    Route::prefix('supervisor/dashboard')->middleware('uip.auth')->group(function () {
        Route::get('/', [SupervisorDashboardApiController::class, 'index']);
    });

    // بند 11 مرحلة 2 — آخر جزء فيها: طابور مشاريع المشرف المُسند له
    // (نطاق كلية/قسم/سنة/مشروع بعينه) + قرارات approve/reject/
    // request-changes، بورت لـ Supervisor\SupervisorProjectController
    // القديمة (كانت web view بس، مفيهاش REST API قديمة). مفيش تضارب مع
    // prefix('supervisors') أو prefix('supervisor/settings') فوق —
    // 'supervisor/projects' أول ظهور له.
    Route::prefix('supervisor/projects')->middleware('uip.auth')->group(function () {
        Route::get('/', [SupervisorProjectsApiController::class, 'index']);
        Route::post('/{id}/approve', [SupervisorProjectsApiController::class, 'approve']);
        Route::post('/{id}/reject', [SupervisorProjectsApiController::class, 'reject']);
        Route::post('/{id}/request-changes', [SupervisorProjectsApiController::class, 'requestChanges']);

        // -- بند 14 (Graduation): الـ rubric grading اللي SupervisorProjectsApiController
        // كانت مؤجّلاها عمدًا (شوف الـ docblock بتاعها) — ProjectGradingService
        // اتنقلت دلوقتي، فسطح /grade بيتضاف هنا جنب approve/reject/request-changes.
        Route::get('/{id}/grade', [SupervisorProjectGradeApiController::class, 'show']);
        Route::post('/{id}/grade', [SupervisorProjectGradeApiController::class, 'save']);
        Route::post('/{id}/grade/reopen', [SupervisorProjectGradeApiController::class, 'reopen']);
    });

    // -- Portfolios (بند 12 — الصفحة العامة/القابلة للمشاركة، مش مقصورة
    // على role واحد. شوف PortfoliosApiController::docblock: /me لبروفايل
    // الكولر نفسه + المشاريع المؤهلة/المميزة، /publish و /unpublish و
    // /projects/{id}/feature أفعال على portfolio الكولر بس، /{uuid} في
    // الآخر عمدًا عشان ميبلعش /me أو /publish/unpublish (نفس ترتيب
    // القديمة بالظبط).
    Route::prefix('portfolios')->middleware('uip.auth')->group(function () {
        Route::get('/me', [PortfoliosApiController::class, 'me']);
        Route::patch('/me', [PortfoliosApiController::class, 'update']);
        Route::post('/me/cover', [PortfoliosApiController::class, 'uploadCover']);
        Route::delete('/me/cover', [PortfoliosApiController::class, 'deleteCover']);
        Route::post('/publish', [PortfoliosApiController::class, 'publish']);
        Route::post('/unpublish', [PortfoliosApiController::class, 'unpublish']);
        Route::post('/projects/{projectId}/feature', [PortfoliosApiController::class, 'toggleFeature']);
        Route::get('/{uuid}', [PortfoliosApiController::class, 'show']);
    });

    // -- Reports (بند الـ Reports + Report Files) — سطح مشترك واحد بين
    // University (كل واحد بأنواعه بس) + فرع
    // admin (كل التاريخ platform-wide، مفلتر ومُرقّم صفحات). RBAC كامل
    // جوّه ReportsApiController نفسه (uip_role/uip_user_id من التوكن،
    // عمرها ما تتاخد من العميل) — نفس نمط كل كنترولرز Reports/*
    // القديمة. delete-selected/delete-all قبل schedules عشان
    // مفيش تعارض روتات.
    Route::prefix('reports')->middleware('uip.auth')->group(function () {
        Route::get('/', [ReportsApiController::class, 'index']);
        Route::post('/generate', [ReportsApiController::class, 'generate']);
        Route::post('/delete-selected', [ReportsApiController::class, 'destroySelected']);
        Route::post('/delete-all', [ReportsApiController::class, 'destroyAll']);
        Route::delete('/{id}', [ReportsApiController::class, 'delete'])->where('id', '[0-9]+');

        Route::post('/schedules', [ReportsApiController::class, 'scheduleCreate']);
        Route::patch('/schedules/{id}', [ReportsApiController::class, 'scheduleUpdate'])->where('id', '[0-9]+');
        Route::post('/schedules/{id}/toggle', [ReportsApiController::class, 'scheduleToggle'])->where('id', '[0-9]+');
        Route::post('/schedules/{id}/run-now', [ReportsApiController::class, 'scheduleRunNow'])->where('id', '[0-9]+');
        Route::delete('/schedules/{id}', [ReportsApiController::class, 'scheduleDelete'])->where('id', '[0-9]+');
    });

    // سطح admin منفصل (AdminReports.jsx بينادي /api/v1/admin/reports/* —
    // مش /api/v1/reports رغم إن الأدمن كمان عنده فرع جوّه ReportsApiController
    // نفسه؛ الاتنين شغالين، كل فعل هنا بيتسجّل في audit log زي الكنترولر
    // القديم بالظبط، عكس فرع الأدمن جوّه ReportsApiController).
    Route::prefix('admin/reports')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminReportsApiController::class, 'index']);
        Route::post('/generate', [AdminReportsApiController::class, 'generate']);
        Route::post('/schedules', [AdminReportsApiController::class, 'scheduleCreate']);
        Route::post('/schedules/{id}/toggle', [AdminReportsApiController::class, 'scheduleToggle'])->where('id', '[0-9]+');
        Route::post('/schedules/{id}/run-now', [AdminReportsApiController::class, 'scheduleRunNow'])->where('id', '[0-9]+');
        Route::delete('/schedules/{id}', [AdminReportsApiController::class, 'scheduleDelete'])->where('id', '[0-9]+');
    });

    // بند 11 مرحلة 3 — Publishing + Public Discovery: سطح
    // /api/v1/public/* بالكامل، **مفيش uip.auth هنا عمدًا** — بورت لـ
    // PublicLandingController/PublicProjectController القديمين (زائر مش
    // مسجّل دخول، Landing.jsx/ProjectsShowcase.jsx/ProjectDetail.jsx/
    // SiteFooter.jsx/PrivacyPolicy.jsx/VerifyCertificate.jsx). نفس تجمّع
    // published() اللي بورتالات الاكتشاف التانية (شركة/مستثمر/باحث)
    // بتقرا منه — مفيش نظام موازي. {slug} بياخد أي نص (مش رقم/uuid بس)
    // عشان findPublishedBySlugOrUuid() تقدر تتسامح مع uuid زيادة على
    // الـ slug العادي.
    Route::prefix('public')->group(function () {
        Route::get('/landing', [PublicApiController::class, 'landing']);
        Route::get('/site-info', [PublicApiController::class, 'siteInfo']);
        Route::get('/visits', [PublicApiController::class, 'visits'])->middleware('throttle:120,1');
        Route::post('/visits', [PublicApiController::class, 'recordVisit'])->middleware('throttle:20,1');
        Route::get('/verify-certificate', [PublicApiController::class, 'verifyCertificate']);
        // رابط مشاركة ملف/مجلد من "جهازي" — من غير تسجيل دخول؛ الصلاحية = معرفة الـ token (راجع DriveShareService).
        Route::prefix('drive-share/{token}')->where(['token' => '[A-Za-z0-9]{8,64}'])->middleware('throttle:120,1')->group(function () {
            Route::get('/', [DriveShareApiController::class, 'show']);
            Route::get('/file', [DriveShareApiController::class, 'file']);
            Route::get('/zip', [DriveShareApiController::class, 'zip'])->middleware('throttle:20,1');
        });
        Route::get('/projects', [PublicApiController::class, 'projects']);
        Route::get('/projects/{slug}', [PublicApiController::class, 'projectShow']);
        // تواصل مع فريق المشروع — الوحيد في المجموعة دي اللي محتاج تسجيل دخول.
        Route::post('/projects/{slug}/contact', [PublicApiController::class, 'contact'])->middleware('uip.auth');

        // -- Public portfolio share link (/p/{uuid} في الفرونت، من غير
        // auth خالص) — بيعيد استخدام PortfoliosApiController::show نفسها
        // (اللي بترجع 404 لو الـ portfolio مش موجود أو is_public=false)،
        // مش كنترولر منفصل. القديمة كانت عندها كنترولرين مختلفين لنفس
        // الفكرة: PublicPortfolioController (صفحة PHP مُرندرة من غير
        // auth، /p/{uuid}) و PortfoliosApiController::show (API endpoint
        // جوّه AuthMiddleware، لمستخدم مسجّل داخل التطبيق بيتصفح
        // portfolio حد تاني). هنا SPA واحدة، فبنعمل نفس الفصل عن طريق
        // route تانية بس لنفس الميثود — /api/v1/portfolios/{uuid} (بند
        // 12 الأصلي) فاضلة جوّه uip.auth زي ما هي بالظبط للاستهلاك
        // الداخلي، وده مسار عام جنبها لصفحة /p/{uuid} العامة.
        Route::get('/portfolios/{uuid}', [PortfoliosApiController::class, 'show']);

        // -- Public University Profile (/u/{uuid} في الفرونت، من غير
        // auth خالص) — مكافئ الجامعة لـ /public/portfolios/{uuid} فوق،
        // PublicApiController::
        // universityProfile() (404 لأي جامعة لسه مفعّلاش is_public، شوف
        // docblock الميثود هناك).
        Route::get('/universities/{uuid}', [PublicApiController::class, 'universityProfile']);
    });
});

// بند 14 — Graduation: قائمة المراجعة + transcript + approve/revoke/edit + الشهادة.
// الـ role (student / university / faculty) بيتحدد جوّه GraduationApiController.
// الـ {studentId} رقمي، وكل مسار ثابت بيتسجل قبل /{studentId} على نفس مبدأ باقي المجموعات.
Route::prefix('v1')->group(function () {
    Route::prefix('graduation')->middleware('uip.auth')->group(function () {
        Route::get('/', [GraduationApiController::class, 'index']);

        Route::get('/{studentId}/certificate', [GraduationApiController::class, 'certificate'])->where('studentId', '[0-9]+');
        Route::post('/{studentId}/approve', [GraduationApiController::class, 'approve'])->where('studentId', '[0-9]+');
        Route::post('/{studentId}/revoke', [GraduationApiController::class, 'revoke'])->where('studentId', '[0-9]+');
        Route::post('/{studentId}/restore', [GraduationApiController::class, 'restore'])->where('studentId', '[0-9]+');

        Route::get('/{studentId}', [GraduationApiController::class, 'show'])->where('studentId', '[0-9]+');
        Route::patch('/{studentId}', [GraduationApiController::class, 'update'])->where('studentId', '[0-9]+');
    });
});

// بند 20 — AI Assistant: كنترولر موحّد لكل بورتال (زي MessagingController
// بالظبط)، منقول من app/Controllers/Common/AiAssistantController.php +
// app/Controllers/Admin/AiAssistantSettingsController.php +
// app/Controllers/Admin/FaqIntentController.php القديمة. طبقة الرد
// الجاهز الذكي/FAQ بتقف قبل مسار الـ AI العادي جوّه streamMessage() نفسها
// — مفيش route منفصلة لها. ملحوظة ترتيب: /conversations/{id}/... و
// /messages/{id}/... و /attachments/{id}/... لازم تتسجل بعد الراوتس
// الثابتة (status/search) زي كل مجموعة تانية في المشروع.
Route::prefix('v1')->group(function () {
    Route::prefix('ai-assistant')->middleware(['uip.auth', 'uip.exam_lock'])->group(function () {
        // /status مفتوح عمدًا: الودجت بيسأله عشان يعرف يعرض نفسه ولا لأ.
        Route::get('/status', [AiAssistantController::class, 'status']);

        Route::middleware('uip.can:ai.assistant_use')->group(function () {
        Route::get('/conversations', [AiAssistantController::class, 'conversations']);
        Route::post('/conversations', [AiAssistantController::class, 'createConversation']);
        Route::get('/conversations/{id}', [AiAssistantController::class, 'showConversation']);
        Route::patch('/conversations/{id}', [AiAssistantController::class, 'updateConversation']);
        Route::delete('/conversations/{id}', [AiAssistantController::class, 'deleteConversation']);
        Route::get('/conversations/{id}/export', [AiAssistantController::class, 'exportConversation']);

        Route::post('/conversations/{id}/attachments', [AiAssistantController::class, 'uploadAttachment']);
        Route::post('/conversations/{id}/messages', [AiAssistantController::class, 'streamMessage']);

        Route::get('/attachments/{id}/download', [AiAssistantController::class, 'downloadAttachment']);

        Route::patch('/messages/{id}', [AiAssistantController::class, 'editMessage']);
        Route::delete('/messages/{id}', [AiAssistantController::class, 'deleteMessage']);
        Route::post('/messages/{id}/regenerate', [AiAssistantController::class, 'regenerateMessage']);
        Route::post('/messages/{id}/bookmark', [AiAssistantController::class, 'bookmarkMessage']);
        Route::post('/messages/{id}/react', [AiAssistantController::class, 'reactToMessage']);

        Route::get('/search', [AiAssistantController::class, 'search']);
        });
    });

    // مساعد الكود داخل محرر MyPC (ZipWorkbench) — شرح/تصليح الأخطاء. نفس حماية الـ AI Assistant:
    // uip.exam_lock يقفله أثناء الامتحان، وصلاحية مستقلة ai.code_assist يتحكم فيها الأدمن من /admin/roles.
    Route::prefix('ai-code')->middleware(['uip.auth', 'uip.exam_lock', 'uip.can:ai.code_assist'])->group(function () {
        Route::get('/status', [AiCodeAssistController::class, 'status']);
        Route::post('/fix', [AiCodeAssistController::class, 'fix']);
        Route::post('/assist', [AiCodeAssistController::class, 'assist']);
        Route::post('/chat', [AiCodeAssistController::class, 'chat']);
        Route::post('/complete', [AiCodeAssistController::class, 'complete']);
    });

    // بند 14 (Admin Settings) — كان الوحيد من كل بورتالات Settings من غير
    // route/كنترولر أصلًا؛ AdminSettings.jsx + AdminProfile.jsx بينادوا
    // على السطح ده بالفعل (14 endpoint)، شوف docblock
    // AdminSettingsApiController للتفاصيل.
    Route::prefix('admin/settings')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AdminSettingsApiController::class, 'index']);
        Route::patch('/profile', [AdminSettingsApiController::class, 'updateProfile']);
        Route::patch('/preferences', [AdminSettingsApiController::class, 'updatePreferences']);
        Route::post('/theme/toggle', [AdminSettingsApiController::class, 'toggleTheme']);
        Route::post('/avatar', [AdminSettingsApiController::class, 'uploadAvatar']);
        Route::patch('/notifications', [AdminSettingsApiController::class, 'updateNotificationPreferences']);
        Route::post('/2fa/setup', [AdminSettingsApiController::class, 'twoFactorSetup']);
        Route::post('/2fa/confirm', [AdminSettingsApiController::class, 'twoFactorConfirm']);
        Route::post('/2fa/disable', [AdminSettingsApiController::class, 'twoFactorDisable']);
        Route::post('/maintenance/toggle', [AdminSettingsApiController::class, 'toggleMaintenance']);
        Route::patch('/mail', [AdminSettingsApiController::class, 'updateMailSettings']);
        Route::post('/mail/test', [AdminSettingsApiController::class, 'sendTestEmail']);
        Route::patch('/ai', [AdminSettingsApiController::class, 'updateAiSettings']);
    });

    Route::prefix('admin/ai-assistant-settings')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [AiAssistantSettingsController::class, 'show']);
        Route::put('/', [AiAssistantSettingsController::class, 'update']);
        Route::get('/analytics', [AiAssistantSettingsController::class, 'analytics']);
    });

    // ملحوظة ترتيب: /unmatched و /top-matched لازم يتسجلوا قبل /{id} عشان
    // Laravel ميحاولش يفسّرهم كـ {id} (نفس ملحوظة notifications فوق).
    Route::prefix('admin/faq-intents')->middleware(['uip.auth', 'uip.admin'])->group(function () {
        Route::get('/', [FaqIntentController::class, 'index']);
        Route::post('/', [FaqIntentController::class, 'store']);
        Route::get('/unmatched', [FaqIntentController::class, 'unmatched']);
        Route::get('/top-matched', [FaqIntentController::class, 'topMatched']);
        Route::get('/{id}', [FaqIntentController::class, 'show']);
        Route::put('/{id}', [FaqIntentController::class, 'update']);
        Route::delete('/{id}', [FaqIntentController::class, 'destroy']);
        Route::post('/{id}/active', [FaqIntentController::class, 'setActive']);
    });

    // بند 25 batch 1 — Security Portal: Dashboard + Sessions. RBAC
    // بيتأكد منه جوّه كل كنترولر نفسه (security_admin/security_officer/
    // admin) مش عبر middleware منفصل — نفس منطق analytics/data-analysis
    // فوق، لأن admin عنده وصول كامل لكل بورتال. باقي البورتال (alerts,
    // incidents, vulnerabilities, policies, logs, reports, report-files,
    // settings) جاي مع batches 2-6.
    Route::prefix('security')->middleware('uip.auth')->group(function () {
        Route::get('/dashboard', [SecurityDashboardApiController::class, 'index'])->middleware('uip.can:security.dashboard.view');

        Route::prefix('sessions')->middleware('uip.can:security.sessions.manage')->group(function () {
            Route::get('/', [SecuritySessionsApiController::class, 'index']);
            Route::post('/{id}/revoke', [SecuritySessionsApiController::class, 'revoke']);
        });

        // بند 25 batch 2 — Alerts. /export لازم يتسجل هنا (مفيش /{id}
        // bare route في alerts أصلًا، فمفيش تعارض).
        Route::prefix('alerts')->middleware('uip.can:security.notifications.manage')->group(function () {
            Route::get('/', [SecurityAlertsApiController::class, 'index']);
            Route::get('/export', [SecurityAlertsApiController::class, 'export']);
            Route::post('/{id}/acknowledge', [SecurityAlertsApiController::class, 'acknowledge']);
            Route::post('/{id}/resolve', [SecurityAlertsApiController::class, 'resolve']);
            Route::post('/{id}/escalate', [SecurityAlertsApiController::class, 'escalate']);
            Route::patch('/{id}/assign', [SecurityAlertsApiController::class, 'assign']);
        });

        // بند 25 batch 2 — Incidents. مفيش تعارض بين GET /{id} والباقي
        // لاختلاف عدد الأجزاء في المسار (زي faq-intents فوق).
        Route::prefix('incidents')->middleware('uip.can:security.incidents.manage')->group(function () {
            Route::get('/', [SecurityIncidentsApiController::class, 'index']);
            Route::post('/', [SecurityIncidentsApiController::class, 'store']);
            Route::get('/{id}', [SecurityIncidentsApiController::class, 'show']);
            Route::get('/{id}/export', [SecurityIncidentsApiController::class, 'exportReport']);
            Route::patch('/{id}/status', [SecurityIncidentsApiController::class, 'updateStatus']);
            Route::patch('/{id}/assign', [SecurityIncidentsApiController::class, 'assign']);
            Route::post('/{id}/comment', [SecurityIncidentsApiController::class, 'comment']);
            Route::post('/{id}/evidence', [SecurityIncidentsApiController::class, 'uploadEvidence']);
            Route::get('/{id}/evidence/{evidenceId}/download', [SecurityIncidentsApiController::class, 'downloadEvidence']);
            Route::delete('/{id}/evidence/{evidenceId}', [SecurityIncidentsApiController::class, 'deleteEvidence']);
        });

        // بند 25 batch 3 — Vulnerabilities. /export لازم يتسجل قبل
        // /{id}/... زي alerts فوق (مفيش /{id} bare route هنا أصلًا).
        Route::prefix('vulnerabilities')->middleware('uip.can:security.vulnerabilities.manage')->group(function () {
            Route::get('/', [SecurityVulnerabilitiesApiController::class, 'index']);
            Route::post('/', [SecurityVulnerabilitiesApiController::class, 'store']);
            Route::get('/export', [SecurityVulnerabilitiesApiController::class, 'export']);
            Route::patch('/{id}/status', [SecurityVulnerabilitiesApiController::class, 'updateStatus']);
            Route::patch('/{id}/deadline', [SecurityVulnerabilitiesApiController::class, 'setDeadline']);
            Route::patch('/{id}/assign', [SecurityVulnerabilitiesApiController::class, 'assign']);
        });

        // بند 25 batch 3 — Policies. /unlock/{id} مسار منفصل عن أي
        // {id}/... تاني هنا (مفيش تعارض)، والمسارات الحرفية (لockout/
        // password/upload/session/mfa/rate-limit/ip-restriction/
        // country-restriction/device-restriction) قبل PATCH / العامة.
        Route::prefix('policies')->middleware('uip.can:write:security.policies.manage')->group(function () {
            Route::get('/', [SecurityPoliciesApiController::class, 'index']);
            Route::patch('/', [SecurityPoliciesApiController::class, 'update']);

            Route::patch('/lockout', [SecurityPoliciesApiController::class, 'updateLockoutPolicy']);
            Route::patch('/password', [SecurityPoliciesApiController::class, 'updatePasswordPolicy']);
            Route::patch('/upload', [SecurityPoliciesApiController::class, 'updateUploadPolicy']);
            Route::patch('/session', [SecurityPoliciesApiController::class, 'updateSessionPolicy']);
            Route::patch('/mfa', [SecurityPoliciesApiController::class, 'updateMfaPolicy']);
            Route::patch('/rate-limit', [SecurityPoliciesApiController::class, 'updateRateLimitPolicy']);
            Route::patch('/ip-restriction', [SecurityPoliciesApiController::class, 'updateIpRestrictionPolicy']);
            Route::patch('/country-restriction', [SecurityPoliciesApiController::class, 'updateCountryRestrictionPolicy']);
            Route::patch('/device-restriction', [SecurityPoliciesApiController::class, 'updateDeviceRestrictionPolicy']);

            Route::post('/unlock/{id}', [SecurityPoliciesApiController::class, 'unlockAccount']);
        });

        // بند 25 batch 4 — Logs. /export لازم يتسجل قبل /blocked-ips/{id}/unblock
        // زي باقي المجموعات فوق (مفيش /{id} bare route هنا أصلًا فمفيش تعارض).
        Route::prefix('logs')->middleware('uip.can:logs.view_security')->group(function () {
            Route::get('/', [SecurityLogsApiController::class, 'index']);
            Route::get('/export', [SecurityLogsApiController::class, 'export']);
            Route::post('/blocked-ips/{id}/unblock', [SecurityLogsApiController::class, 'unblockIp'])->middleware('uip.can:security.ip.manage');
        });

        // بند 25 batch 5 — Reports (ReportService/ReportRepository
        // المشتركين، أنواع security_incident_summary/vulnerability_summary).
        Route::prefix('reports')->middleware('uip.can:security.reports.generate')->group(function () {
            Route::get('/', [SecurityReportsApiController::class, 'index']);
            Route::post('/generate', [SecurityReportsApiController::class, 'generate']);
            Route::delete('/{id}', [SecurityReportsApiController::class, 'destroy']);
        });

        // بند 25 batch 5 — Report Files (رفع/عرض/معاينة/تنزيل/استبدال/
        // أرشفة/حذف نهائي + تاريخ نسخ). مفيش تعارض بين GET /{id} والباقي
        // لاختلاف عدد الأجزاء في المسار.
        Route::prefix('report-files')->middleware('uip.can:security.reports.generate')->group(function () {
            Route::get('/', [SecurityReportFilesApiController::class, 'index']);
            Route::post('/', [SecurityReportFilesApiController::class, 'store']);
            Route::get('/{id}', [SecurityReportFilesApiController::class, 'show']);
            Route::get('/{id}/download', [SecurityReportFilesApiController::class, 'download']);
            Route::get('/{id}/preview', [SecurityReportFilesApiController::class, 'preview']);
            Route::post('/{id}/replace', [SecurityReportFilesApiController::class, 'replace']);
            Route::post('/{id}/archive', [SecurityReportFilesApiController::class, 'archive']);
            Route::post('/{id}/unarchive', [SecurityReportFilesApiController::class, 'unarchive']);
            Route::delete('/{id}', [SecurityReportFilesApiController::class, 'destroy']);
        });

        // بند 25 batch 6 — Settings (آخر batch في بند 25). المسارات
        // الحرفية (notifications/profile/preferences/password/2fa/*)
        // مفيهاش تعارض مع بعض — كل واحدة segment مختلف.
        Route::prefix('settings')->group(function () {
            Route::get('/', [SecuritySettingsApiController::class, 'index']);
            Route::post('/avatar', [SecuritySettingsApiController::class, 'uploadAvatar']);
            Route::patch('/notifications', [SecuritySettingsApiController::class, 'updateNotificationPreferences']);
            Route::patch('/profile', [SecuritySettingsApiController::class, 'updateProfile']);
            Route::patch('/preferences', [SecuritySettingsApiController::class, 'updatePreferences']);
            Route::post('/theme/toggle', [SecuritySettingsApiController::class, 'toggleTheme']);
            Route::patch('/password', [SecuritySettingsApiController::class, 'updatePassword']);
            Route::post('/2fa/setup', [SecuritySettingsApiController::class, 'twoFactorSetup']);
            Route::post('/2fa/confirm', [SecuritySettingsApiController::class, 'twoFactorConfirm']);
            Route::post('/2fa/disable', [SecuritySettingsApiController::class, 'twoFactorDisable']);
            Route::post('/2fa/trusted-devices/{id}/revoke', [SecuritySettingsApiController::class, 'revokeTrustedDevice']);
        });
    });
});

// Connect GitHub (OAuth) — الـ callback عام عمدًا (GitHub بيرجّع المتصفح من غير Authorization header؛
// الهوية جاية من state الكاش). باقي المسارات محمية.
Route::prefix('v1/github')->group(function () {
    Route::get('/callback', [GithubConnectionApiController::class, 'callback']);

    Route::middleware('uip.auth')->group(function () {
        Route::get('/status', [GithubConnectionApiController::class, 'status']);
        Route::post('/connect', [GithubConnectionApiController::class, 'connect']);
        Route::get('/repos', [GithubConnectionApiController::class, 'repos']);
        Route::post('/link', [GithubConnectionApiController::class, 'linkToProject'])->middleware('uip.can:project.edit_own');
        Route::post('/create-project', [GithubConnectionApiController::class, 'createProject'])->middleware('uip.can:project.create');
        Route::delete('/connection', [GithubConnectionApiController::class, 'disconnect']);
    });
});

// Google Drive backup link (OAuth code flow + refresh token) — the callback is public on purpose (Google redirects the
// pop-up without an Authorization header; identity comes from the cached state).
Route::prefix('v1/cloud/google')->group(function () {
    Route::get('/callback', [GoogleDriveConnectionApiController::class, 'callback']);

    Route::middleware('uip.auth')->group(function () {
        Route::get('/status', [GoogleDriveConnectionApiController::class, 'status']);
        Route::post('/connect', [GoogleDriveConnectionApiController::class, 'connect']);
        Route::get('/token', [GoogleDriveConnectionApiController::class, 'token'])->middleware('throttle:60,1');
        Route::delete('/connection', [GoogleDriveConnectionApiController::class, 'disconnect']);
    });
});
