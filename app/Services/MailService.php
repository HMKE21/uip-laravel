<?php

namespace App\Services;

use App\Mail\GenericMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * كانت دي فجوة متعمدة موثقة (README.md، بند "متعمّد إنه لسه على النظام
 * القديم" رقم 4): كل ميثود هنا كانت بتعمل Log::info() بس وترجع true —
 * من غير ما تبعت أي إيميل حقيقي. ده سبب إن دعوات (عضو هيئة تدريس/طالب/
 * فريق شركة...الخ) كانت بتتسجل في السيستم وبتغيّر الباسورد، بس مفيش
 * إيميل بيوصل فعليًا للمستلم.
 *
 * الإصلاح: كل الميثودز بقت بتبعت فعليًا عبر Laravel Mail (GenericMail
 * Mailable + resources/views/emails/generic.blade.php)، باستخدام الـ
 * mailer المضبوط في config/mail.php (يعتمد على MAIL_MAILER في .env —
 * لازم تتظبط SMTP حقيقية في .env عشان الإرسال يشتغل فعليًا؛ لو
 * MAIL_MAILER=log (الافتراضي في Laravel لو الـ .env فاضي) هيفضل بيسجل في
 * storage/logs/laravel.log بس زي الأول، من غير error — ده سلوك Laravel
 * نفسه مش حاجة إضافية هنا).
 *
 * كل الميثودز اللي نتيجتها بتتحول لرسالة نجاح/فشل فعلية للمستخدم (دعوات،
 * إعادة تعيين باسورد، "Send Test Email to Myself") بترجع true/false حسب
 * نجاح الإرسال الفعلي دلوقتي (مش true دايمًا زي الاستَب القديم) — عشان
 * resendInvite() وشبهها يقدروا يبلّغوا المستخدم صح لو الإرسال فشل.
 *
 * الميثودز اللي كانت موثّقة إنها "لازم تفضل ترجع true دايمًا عشان الخدمة
 * المستدعية (ReportScheduler/DataExport/AccountLockout) متتوقفش" باقية
 * على نفس العقد ده — بتحاول تبعت فعليًا، وبتسجّل أي فشل في الـ log، لكن
 * برضه بترجع true عشان معملياتها الأساسية (تسجيل، تصدير، قفل حساب)
 * تفضل شغالة حتى لو SMTP واقع مؤقتًا.
 */
class MailService
{
    /** آخر خطأ إرسال (بيظهر للأدمن في "Send Test Email"). */
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<int, array{label: string, value: string}> $meta صفوف إضافية (باسورد مؤقت...الخ)
     */
    private function deliver(
        string $toEmail,
        string $subject,
        string $heading,
        array $lines,
        ?array $button,
        string $locale,
        array $meta,
        string $logContext,
        string $variant = 'info',
        ?array $replyTo = null
    ): bool {
        $this->lastError = null;

        try {
            app(MailConfigService::class)->apply();

            // لو الـ mailer الفعّال log/array (الافتراضي لو MAIL_MAILER مش متظبط ومفيش مزوّد محفوظ من
            // Admin > Settings > Mail) الإيميل بيتكتب في storage/logs/laravel.log بس ومبيتبعتش لحد —
            // فمينفعش نرجّع "اتبعت بنجاح" (ده كان سبب إن التسجيل يقول "بعتنالك رسالة" ومفيش حاجة توصل).
            if (!app()->environment('testing') && in_array((string) config('mail.default'), ['log', 'array'], true)) {
                $this->lastError = 'No real mail provider is configured (mailer is "' . config('mail.default') . '"). Set MAIL_MAILER=smtp/brevo in .env or configure it in Admin > Settings > Mail.';
                Log::error("{$logContext}: email NOT sent — {$this->lastError}", ['email' => $toEmail]);

                return false;
            }

            Mail::to($toEmail)->send(new GenericMail($subject, $heading, $lines, $button, $locale, $meta, $variant, $replyTo['email'] ?? null, $replyTo['name'] ?? null));

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error("{$logContext}: failed to send email", [
                'email' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * "Copy code" page on the frontend. E-mail clients can't run scripts, so the button opens a page
     * that copies the code. The code goes in the URL fragment (#), which browsers never send to a server.
     */
    private function copyCodeUrl(string $otp, ?string $next = null): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
        $query = $next ? '?next=' . rawurlencode($next) : '';

        return "{$base}/auth/copy-code{$query}#code=" . rawurlencode($otp);
    }

    private function loginUrl(): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');

        return "{$base}/login";
    }

    public function sendStudentInvite(string $email, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $email,
            $ar ? "دعوة للانضمام إلى {$universityName}" : "You're invited to join {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كطالب في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as a student. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $email],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendStudentInvite',
            'invite'
        );
    }

    public function sendSupervisorInvite(string $toEmail, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? "دعوة للانضمام كمشرف في {$universityName}" : "You're invited as a supervisor at {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كمشرف في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as a supervisor. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $toEmail],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendSupervisorInvite',
            'invite'
        );
    }

    public function sendAcademicStaffInvite(string $toEmail, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? "دعوة للانضمام كعضو هيئة تدريس في {$universityName}" : "You're invited as academic staff at {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كعضو هيئة تدريس في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as academic staff. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $toEmail],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendAcademicStaffInvite',
            'invite'
        );
    }

    /**
     * رسالة من زائر (من غير تسجيل) لفريق مشروع منشور — بتتبعت لكل عضو
     * في إيميل منفصل، والـ Reply-To هو إيميل الزائر عشان الرد يوصله مباشرة.
     */
    public function sendProjectInquiry(
        string $toEmail,
        string $recipientName,
        string $projectTitle,
        string $senderName,
        string $senderEmail,
        string $message,
        ?string $projectUrl,
        string $locale = 'ar'
    ): bool {
        $ar = $locale === 'ar';
        $subject = $ar ? "رسالة جديدة بخصوص مشروع: {$projectTitle}" : "New message about your project: {$projectTitle}";

        return $this->deliver(
            $toEmail,
            $subject,
            $ar ? "أهلاً {$recipientName}" : "Hi {$recipientName}",
            [
                $ar
                    ? "وصلتك رسالة من زائر لصفحة مشروعك \"{$projectTitle}\" على المنصة. للرد، اضغط Reply على الإيميل ده وهيوصل للمرسل مباشرة."
                    : "A visitor sent a message through your project page \"{$projectTitle}\". Just hit Reply and your answer goes straight to the sender.",
                $message,
            ],
            $projectUrl ? ['label' => $ar ? 'فتح صفحة المشروع' : 'Open project page', 'url' => $projectUrl] : null,
            $locale,
            [
                ['label' => $ar ? 'الاسم' : 'Name', 'value' => $senderName],
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $senderEmail],
            ],
            'sendProjectInquiry',
            'notification',
            ['email' => $senderEmail, 'name' => $senderName]
        );
    }

    /**
     * دعوة اجتماع بالإيميل — بتتبعت لما الهوست يدعو مستخدم عن طريق بريده.
     * $details: صفوف label/value (موضوع الاجتماع، الموعد، المدة، المضيف...).
     * كلمة سر الاجتماع (لو فيه) مش بتتبعت في الإيميل عمدًا.
     */
    public function sendMeetingInvite(
        string $toEmail,
        string $inviteeName,
        string $hostName,
        string $meetingTitle,
        array $details,
        ?string $message,
        string $meetingUrl,
        string $locale = 'ar',
        bool $guest = false
    ): bool {
        $ar = $locale === 'ar';

        $lines = [
            $ar
                ? "{$hostName} دعاك لحضور اجتماع \"{$meetingTitle}\" على المنصة. اضغط على الزر أدناه لفتح صفحة الاجتماع والرد على الدعوة (قبول/رفض)."
                : "{$hostName} invited you to the meeting \"{$meetingTitle}\" on the platform. Use the button below to open the meeting page and accept or decline.",
        ];
        if ($guest) {
            $lines[] = $ar
                ? 'مش محتاج حساب على المنصة: افتح الرابط من المتصفح واكتب اسمك وانضم مباشرة.'
                : 'No account needed: open the link in your browser, enter your name and join directly.';
        }
        if ($message !== null && trim($message) !== '') {
            $lines[] = ($ar ? 'رسالة من المضيف: ' : 'Message from the host: ') . trim($message);
        }

        return $this->deliver(
            $toEmail,
            $ar ? "دعوة لحضور اجتماع: {$meetingTitle}" : "Meeting invitation: {$meetingTitle}",
            $ar ? "أهلاً {$inviteeName}" : "Hi {$inviteeName}",
            $lines,
            ['label' => $guest ? ($ar ? 'الانضمام للاجتماع' : 'Join the meeting') : ($ar ? 'فتح الاجتماع' : 'Open the meeting'), 'url' => $meetingUrl],
            $locale,
            $details,
            'sendMeetingInvite',
            'meeting'
        );
    }

    public function sendNotificationEmail(string $toEmail, string $fullName, string $title, ?string $body, ?string $linkUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $lines = [$ar ? "أهلاً {$fullName}" : "Hi {$fullName}"];
        if ($body) {
            $lines[] = $body;
        }

        return $this->deliver(
            $toEmail,
            $title,
            $title,
            $lines,
            $linkUrl ? ['label' => $ar ? 'عرض التفاصيل' : 'View details', 'url' => $linkUrl] : null,
            $locale,
            [],
            'sendNotificationEmail',
            'notification'
        );
    }

    /** ملخص إشعارات (إيميل واحد لكل المستخدم) — للي مختار ملخص يومي/أسبوعي. @param array<int,array{title:string,body:?string,link:?string}> $items */
    public function sendNotificationDigest(string $toEmail, string $fullName, array $items, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $count = count($items);
        $subject = $ar ? "ملخص إشعارات مشاريع التخرج ({$count})" : "Graduation projects digest ({$count})";
        $lines = [$ar ? "أهلاً {$fullName}" : "Hi {$fullName}"];
        foreach ($items as $it) {
            $lines[] = '• ' . $it['title'] . (!empty($it['body']) ? ' — ' . mb_substr((string) $it['body'], 0, 160) : '');
        }
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', '')), '/');

        return $this->deliver(
            $toEmail, $subject, $subject, $lines,
            $base !== '' ? ['label' => $ar ? 'فتح المنصة' : 'Open the platform', 'url' => $base] : null,
            $locale, [], 'sendNotificationDigest', 'notification'
        );
    }

    /**
     * تسليم تقرير مجدول — بترجع true دايمًا حتى لو الإرسال فشل (نفس العقد
     * القديم، موثّق في README) عشان ReportSchedulerService يكمل جدولة
     * الجري الجاي حتى لو SMTP واقع مؤقتًا؛ الفشل نفسه بيتسجل في الـ log.
     */
    public function sendScheduledReport(string $toEmail, string $reportLabel, string $frequency, ?string $downloadUrl, string $locale = 'en'): bool
    {
        $ar = $locale === 'ar';
        $freqAr = ['daily' => 'يومي', 'weekly' => 'أسبوعي', 'monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'yearly' => 'سنوي', 'custom' => 'مخصص'];
        $freq = $ar ? ($freqAr[strtolower($frequency)] ?? $frequency) : $frequency;

        $this->deliver(
            $toEmail,
            "{$reportLabel} — {$freq}",
            $reportLabel,
            [$ar ? "تقريرك المجدول \"{$reportLabel}\" ({$freq}) أصبح جاهزًا." : "Your scheduled report \"{$reportLabel}\" ({$frequency}) is ready."],
            $downloadUrl ? ['label' => $ar ? 'تحميل' : 'Download', 'url' => $downloadUrl] : null,
            $locale,
            [],
            'sendScheduledReport',
            'report'
        );

        return true;
    }

    public function sendDataExportReady(string $toEmail, string $exportTypeLabel, string $format, ?string $downloadUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        $this->deliver(
            $toEmail,
            $ar ? "تصدير {$exportTypeLabel} جاهز" : "Your {$exportTypeLabel} export is ready",
            $ar ? 'تصديرك جاهز' : 'Your export is ready',
            [
                $ar
                    ? "تصدير \"{$exportTypeLabel}\" بصيغة " . strtoupper($format) . ' أصبح جاهزًا للتحميل.'
                    : "Your \"{$exportTypeLabel}\" export in " . strtoupper($format) . ' format is ready to download.',
            ],
            $downloadUrl ? ['label' => $ar ? 'تحميل' : 'Download', 'url' => $downloadUrl] : null,
            $locale,
            [],
            'sendDataExportReady',
            'success'
        );

        return true;
    }

    public function sendAccountLockedNotice(
        string $toEmail,
        string $fullName,
        bool $permanent,
        ?string $lockedUntil,
        string $supportEmail,
        string $supportPhone,
        string $locale = 'ar',
        ?string $resetUrl = null,
        ?string $attemptIp = null
    ): bool {
        $ar = $locale === 'ar';

        $lines = [
            $permanent
                ? ($ar ? 'تم قفل حسابك بشكل دائم لأسباب أمنية.' : 'Your account has been permanently locked for security reasons.')
                : ($ar ? "تم قفل حسابك مؤقتًا حتى {$lockedUntil}." : "Your account is temporarily locked until {$lockedUntil}."),
            $ar
                ? 'السبب: محاولات دخول فاشلة متكررة' . ($attemptIp ? " (آخر محاولة من IP: {$attemptIp})" : '') . '.'
                : 'Reason: repeated failed login attempts' . ($attemptIp ? " (latest attempt from IP: {$attemptIp})" : '') . '.',
        ];
        if ($resetUrl) {
            $lines[] = $ar
                ? 'لو ماكنتش أنت، يُنصح بتغيير كلمة السر فورًا من الزر أدناه (الرابط صالح لمدة ساعة).'
                : "If this wasn't you, we recommend changing your password right away using the button below (valid for one hour).";
        }
        $lines[] = $ar
            ? "للمساعدة تواصل معنا عبر {$supportEmail} أو {$supportPhone}."
            : "For help, contact us at {$supportEmail} or {$supportPhone}.";

        $this->deliver(
            $toEmail,
            $ar ? 'تم قفل حسابك' : 'Your account was locked',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            $lines,
            $resetUrl ? ['label' => $ar ? 'تغيير كلمة السر' : 'Change password', 'url' => $resetUrl] : null,
            $locale,
            [],
            'sendAccountLockedNotice',
            'security'
        );

        return true;
    }

    /** رمز OTP لاسترجاع حساب محذوف (restore) أو دمج بياناته في الحساب الحالي (merge). */
    public function sendAccountRecoveryOtp(string $toEmail, string $fullName, string $otp, int $minutes, string $purpose, ?string $ip, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $merge = $purpose === 'merge';
        $delete = $purpose === 'delete';
        $from = $ip ? ($ar ? " (من IP: {$ip})" : " (from IP: {$ip})") : '';

        $subject = $delete
            ? ($ar ? 'رمز تأكيد حذف حسابك' : 'Code to confirm deleting your account')
            : ($merge ? ($ar ? 'رمز استرجاع بيانات حسابك القديم' : 'Code to recover your old account data') : ($ar ? 'رمز استرجاع حسابك المحذوف' : 'Code to restore your deleted account'));

        if ($delete) {
            $lead = $ar ? "طُلب حذف حسابك على المنصة من إعدادات الحساب{$from}. استخدم الرمز التالي لتأكيد الحذف."
                        : "A request was made to delete your account from the account settings{$from}. Use the code below to confirm.";
            $ignore = $ar ? 'لو ماكنتش أنت، تجاهل الرسالة وغيّر كلمة السر فورًا — مفيش حاجة هتتحذف.' : "If this wasn't you, ignore this email and change your password right away — nothing will be deleted.";
        } else {
            $lead = $merge
                ? ($ar ? "طُلب نقل بيانات حسابك القديم (المحذوف) إلى حسابك الحالي{$from}. استخدم الرمز التالي لتأكيد إنك صاحب الإيميل."
                       : "A request was made to move the data of your old (deleted) account into your current account{$from}. Use the code below to confirm you own this email.")
                : ($ar ? "طُلب استرجاع حسابك المحذوف على المنصة{$from}. استخدم الرمز التالي لتأكيد إنك صاحب الإيميل وتحديد كلمة سر جديدة."
                       : "A request was made to restore your deleted account on the platform{$from}. Use the code below to confirm you own this email and set a new password.");
            $ignore = $ar ? 'لو ماكنتش أنت، تجاهل الرسالة — مفيش حاجة هتتغير.' : "If this wasn't you, ignore this email — nothing will change.";
        }

        return $this->deliver(
            $toEmail,
            $subject,
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $lead,
                $ar ? "الرمز صالح لمدة {$minutes} دقايق ويُستخدم مرة واحدة. متشاركوش مع حد." : "The code is valid for {$minutes} minutes and works once. Never share it with anyone.",
                $ignore,
            ],
            null,
            $locale,
            [['label' => $ar ? 'رمز التحقق' : 'Verification code', 'value' => $otp, 'code' => true, 'copyUrl' => $this->copyCodeUrl($otp)]],
            'sendAccountRecoveryOtp',
        );
    }

    /** رمز OTP لاسترجاع حساب مفتوح على جهاز تاني ("مش أنا") — بيرجّع نتيجة الإرسال الفعلية. */
    public function sendSessionTakeoverOtp(string $toEmail, string $fullName, string $otp, int $minutes, ?string $ip, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'رمز استرجاع حسابك' : 'Your account recovery code',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'حد حاول يدخل حسابك وهو مفتوح على جهاز تاني، وطلب استرجاع الحساب' . ($ip ? " (من IP: {$ip})" : '') . '. استخدم الرمز التالي لتغيير كلمة السر وقفل الجلسة التانية فورًا.'
                    : 'Someone tried to sign in while your account is open on another device and asked to recover it' . ($ip ? " (from IP: {$ip})" : '') . '. Use the code below to set a new password and sign the other session out right away.',
                $ar ? "الرمز صالح لمدة {$minutes} دقايق ويُستخدم مرة واحدة. متشاركوش مع حد." : "The code is valid for {$minutes} minutes and works once. Never share it with anyone.",
                $ar ? 'لو ماكنتش أنت، تجاهل الرسالة، ويُفضّل تغيّر كلمة السر.' : "If this wasn't you, ignore this email, and consider changing your password.",
            ],
            null,
            $locale,
            [['label' => $ar ? 'رمز التحقق' : 'Verification code', 'value' => $otp, 'code' => true, 'copyUrl' => $this->copyCodeUrl($otp)]],
            'sendSessionTakeoverOtp',
            'security'
        );
    }

    /** رمز OTP لتأكيد الإيميل بعد التسجيل — الحساب مبيشتغلش قبل إدخاله. */
    public function sendEmailVerificationOtp(string $toEmail, string $fullName, string $otp, int $minutes, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'رمز تأكيد بريدك الإلكتروني' : 'Your email confirmation code',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'شكرًا لتسجيلك. اكتب الرمز التالي في صفحة التأكيد لإثبات أن هذا البريد بريدك وتفعيل حسابك.'
                    : 'Thanks for signing up. Enter the code below on the confirmation page to prove this email is yours and activate your account.',
                $ar ? "الرمز صالح لمدة {$minutes} دقايق ويُستخدم مرة واحدة. متشاركوش مع حد." : "The code is valid for {$minutes} minutes and works once. Never share it with anyone.",
                $ar
                    ? 'لو ماكنتش أنت اللي سجّل بهذا البريد، تجاهل هذه الرسالة وماحدش هيقدر يستخدم الحساب.'
                    : "If you didn't sign up with this email, ignore this message — the account can't be used without this code.",
            ],
            null,
            $locale,
            [['label' => $ar ? 'رمز التحقق' : 'Verification code', 'value' => $otp, 'code' => true, 'copyUrl' => $this->copyCodeUrl($otp)]],
            'sendEmailVerificationOtp',
            'info'
        );
    }

    /** رمز OTP لإعادة تعيين كلمة السر. */
    public function sendPasswordResetOtp(string $toEmail, string $fullName, string $otp, int $minutes, ?string $ip, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $from = $ip ? ($ar ? " (من IP: {$ip})" : " (from IP: {$ip})") : '';

        return $this->deliver(
            $toEmail,
            $ar ? 'رمز إعادة تعيين كلمة السر' : 'Your password reset code',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "وصلنا طلب لإعادة تعيين كلمة السر الخاصة بحسابك{$from}. اكتب الرمز التالي في صفحة إعادة التعيين ثم اختر كلمة سر جديدة."
                    : "We received a request to reset your password{$from}. Enter the code below on the reset page, then choose a new password.",
                $ar ? "الرمز صالح لمدة {$minutes} دقايق ويُستخدم مرة واحدة. متشاركوش مع حد." : "The code is valid for {$minutes} minutes and works once. Never share it with anyone.",
                $ar ? 'لو ماكنتش أنت اللي طلبت ده، تجاهل هذه الرسالة — كلمة السر مش هتتغير.' : "If you didn't request this, ignore this email — your password won't change.",
            ],
            ['label' => $ar ? 'فتح صفحة إعادة التعيين' : 'Open the reset page', 'url' => rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/') . '/auth/reset-password?email=' . rawurlencode($toEmail) . '#code=' . rawurlencode($otp)],
            $locale,
            [['label' => $ar ? 'رمز التحقق' : 'Verification code', 'value' => $otp, 'code' => true, 'copyUrl' => $this->copyCodeUrl($otp, '/auth/reset-password?email=' . rawurlencode($toEmail))]],
            'sendPasswordResetOtp',
            'reset'
        );
    }

    /** رمز OTP لاسترجاع باسورد شاشة القفل بتاعة سطح مكتب "جهازي". */
    public function sendDesktopLockOtp(string $toEmail, string $fullName, string $otp, int $minutes, ?string $ip, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $from = $ip ? ($ar ? " (من IP: {$ip})" : " (from IP: {$ip})") : '';

        return $this->deliver(
            $toEmail,
            $ar ? 'رمز استرجاع باسورد شاشة القفل' : 'Your lock screen password reset code',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "وصلنا طلب لاسترجاع باسورد شاشة القفل في سطح مكتب \"جهازي\"{$from}. اكتب الرمز التالي في شاشة القفل ثم اختر باسورد جديد."
                    : "We received a request to reset your desktop lock screen password{$from}. Enter the code below on the lock screen, then choose a new password.",
                $ar ? "الرمز صالح لمدة {$minutes} دقايق ويُستخدم مرة واحدة. متشاركوش مع حد." : "The code is valid for {$minutes} minutes and works once. Never share it with anyone.",
                $ar ? 'لو ماكنتش أنت اللي طلبت ده، تجاهل هذه الرسالة — الباسورد مش هيتغير.' : "If you didn't request this, ignore this email — your lock password won't change.",
            ],
            null,
            $locale,
            [['label' => $ar ? 'رمز التحقق' : 'Verification code', 'value' => $otp, 'code' => true, 'copyUrl' => $this->copyCodeUrl($otp, '/student/my-pc')]],
            'sendDesktopLockOtp',
            'reset'
        );
    }

    /** لينك تأكيد تغيير الإيميل — بيتبعت للإيميل الجديد، والتغيير ما بيحصلش قبل الضغط عليه. */
    public function sendEmailChangeConfirmation(string $toEmail, string $fullName, string $confirmUrl, int $hours, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'أكّد بريدك الإلكتروني الجديد' : 'Confirm your new email address',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "طلبت تغيير بريد حسابك إلى هذا العنوان. اضغط على الزر أدناه لتأكيده. الرابط صالح لمدة {$hours} ساعة."
                    : "You asked to change your account email to this address. Click the button below to confirm it. The link is valid for {$hours} hours.",
                $ar
                    ? 'لو ماكنتش أنت اللي طلب ده، تجاهل هذه الرسالة — بريد الحساب مش هيتغير.'
                    : "If you didn't request this, ignore this message — your account email won't change.",
            ],
            ['label' => $ar ? 'تأكيد البريد الجديد' : 'Confirm new email', 'url' => $confirmUrl],
            $locale,
            [],
            'sendEmailChangeConfirmation',
            'info'
        );
    }

    /** لينك تأكيد الإيميل بعد التسجيل — الحساب مبيشتغلش قبل الضغط عليه. */
    public function sendEmailVerification(string $toEmail, string $fullName, string $verifyUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'أكّد بريدك الإلكتروني' : 'Confirm your email address',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'شكرًا لتسجيلك. اضغط على الزر أدناه لتأكيد أن هذا البريد بريدك وتفعيل حسابك. الرابط صالح لمدة 24 ساعة.'
                    : 'Thanks for signing up. Click the button below to confirm this email address is yours and activate your account. The link is valid for 24 hours.',
                $ar
                    ? 'لو ماكنتش أنت اللي سجّل بهذا البريد، تجاهل هذه الرسالة وماحدش هيقدر يستخدم الحساب.'
                    : "If you didn't sign up with this email, ignore this message — the account can't be used without this confirmation.",
            ],
            ['label' => $ar ? 'تأكيد البريد' : 'Confirm email', 'url' => $verifyUrl],
            $locale,
            [],
            'sendEmailVerification',
            'info'
        );
    }

    public function sendPasswordReset(string $toEmail, string $fullName, string $resetUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'إعادة تعيين كلمة السر' : 'Reset your password',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'وصلنا طلب لإعادة تعيين كلمة السر الخاصة بحسابك. اضغط على الزر أدناه لاختيار كلمة سر جديدة. الرابط صالح لمدة ساعة واحدة.'
                    : 'We received a request to reset your password. Click the button below to choose a new one. This link is valid for one hour.',
                $ar
                    ? 'لو ماكنتش أنت اللي طلبت ده، تجاهل هذه الرسالة.'
                    : "If you didn't request this, you can safely ignore this email.",
            ],
            ['label' => $ar ? 'إعادة تعيين كلمة السر' : 'Reset password', 'url' => $resetUrl],
            $locale,
            [],
            'sendPasswordReset',
            'reset'
        );
    }

    /**
     * "Send Test Email to Myself" — على عكس الاستَب القديم، دي لازم ترجع
     * نتيجة الإرسال الفعلية (مش true دايمًا) لأن الغرض الوحيد منها إن
     * الأدمن يتأكد إن إعدادات SMTP شغالة فعلاً.
     */
    public function sendTestEmail(string $toEmail, string $fullName, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'رسالة اختبار' : 'Test email',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'دي رسالة اختبار للتأكد إن إعدادات البريد الإلكتروني شغالة صح.'
                    : 'This is a test email to confirm your mail settings are working correctly.',
            ],
            null,
            $locale,
            [],
            'sendTestEmail',
            'test'
        );
    }

}
