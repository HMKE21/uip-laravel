<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable عام واحد لكل إيميلات المشروع (دعوات، إعادة تعيين باسورد،
 * إشعارات، تقارير...). بديل حقيقي لـ MailService القديمة اللي كانت بتعمل
 * Log::info() بس (فجوة موثقة في README.md — بند 4 من "متعمّد إنه لسه على
 * النظام القديم"). كل ميثود في MailService بتبني subject/heading/lines/
 * button وتبعتها هنا بدل ما تسجّلها.
 *
 * $lines: مصفوفة أسطر نص عادي (كل سطر <p> منفصل في القالب) — من غير HTML
 * عشان مفيش مدخل XSS من بيانات المستخدم (اسم، إيميل...الخ) يوصل هنا.
 * $button: ['label' => string, 'url' => string]|null.
 *
 * ملحوظة مهمة: الاسم $mailLocale (مش $locale) عن قصد — Illuminate\Mail\
 * Mailable الأصلية معرّفة فيها property اسمها $locale (بتستخدمها
 * Mailable::locale()/withLocale() لتحديد لغة الرندر)، فلو عملنا override
 * ليها بـ type مختلف (string بدل نوعها الأصلي) PHP بيرمي Fatal Error:
 * "Type of App\Mail\GenericMail::$locale must not be defined (as in class
 * Illuminate\Mail\Mailable)". استخدمنا اسم مختلف تمامًا عشان نتجنب أي
 * تعارض مع أي property تانية في الكلاس الأب دلوقتي أو في نسخة Laravel
 * جاية.
 */
class GenericMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $heading,
        public array $lines,
        public ?array $button = null,
        public string $mailLocale = 'ar',
        public ?array $meta = null, // صفوف label/value إضافية (زي الباسورد المؤقت)
        // نوع الإيميل — بيحدد الألوان والأيقونة والبادج في القالب:
        // invite | reset | security | success | report | notification | test | info
        public string $variant = 'info',
        // Reply-To مخصّص (مثلاً: إيميل الزائر في فورم التواصل مع فريق المشروع)
        public ?string $replyToEmail = null,
        public ?string $replyToName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $replyTo = config('mail.reply_to_address');

        if ($this->replyToEmail) {
            $replyToList = [new Address($this->replyToEmail, (string) $this->replyToName)];
        } else {
            $replyToList = $replyTo ? [new Address($replyTo)] : [];
        }

        return new Envelope(
            subject: $this->mailSubject,
            replyTo: $replyToList,
        );
    }

    /**
     * ثيم كل نوع إيميل (ألوان + أيقونة + بادج). لو النوع مش معروف بيرجع لـ info.
     *
     * @return array<string, string|null>
     */
    private function theme(): array
    {
        $ar = $this->mailLocale === 'ar';

        $themes = [
            'invite' => [
                'from' => '#4f46e5', 'to' => '#7c3aed', 'accent' => '#4f46e5',
                'soft' => '#eef2ff', 'softBorder' => '#c7d2fe', 'shadow' => 'rgba(79,70,229,0.35)',
                'icon' => '🎉', 'metaIcon' => '🔑',
                'badge' => $ar ? 'دعوة انضمام' : 'Invitation',
                'metaTitle' => $ar ? 'بيانات الدخول المؤقتة' : 'Temporary login details',
                'note' => $ar
                    ? 'لأمان حسابك، غيّر كلمة السر المؤقتة بعد أول تسجيل دخول مباشرة.'
                    : 'For your security, change this temporary password right after your first login.',
            ],
            'reset' => [
                'from' => '#f59e0b', 'to' => '#ea580c', 'accent' => '#ea580c',
                'soft' => '#fff7ed', 'softBorder' => '#fed7aa', 'shadow' => 'rgba(234,88,12,0.35)',
                'icon' => '🔐', 'metaIcon' => '📋',
                'badge' => $ar ? 'أمان الحساب' : 'Account security',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
            'security' => [
                'from' => '#e11d48', 'to' => '#9f1239', 'accent' => '#e11d48',
                'soft' => '#fff1f2', 'softBorder' => '#fecdd3', 'shadow' => 'rgba(225,29,72,0.35)',
                'icon' => '🛡️', 'metaIcon' => '📋',
                'badge' => $ar ? 'تنبيه أمني' : 'Security alert',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
            'success' => [
                'from' => '#059669', 'to' => '#0d9488', 'accent' => '#059669',
                'soft' => '#ecfdf5', 'softBorder' => '#a7f3d0', 'shadow' => 'rgba(5,150,105,0.35)',
                'icon' => '✅', 'metaIcon' => '📋',
                'badge' => $ar ? 'تم بنجاح' : 'Completed',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
            'report' => [
                'from' => '#0284c7', 'to' => '#0891b2', 'accent' => '#0284c7',
                'soft' => '#f0f9ff', 'softBorder' => '#bae6fd', 'shadow' => 'rgba(2,132,199,0.35)',
                'icon' => '📊', 'metaIcon' => '📋',
                'badge' => $ar ? 'تقرير' : 'Report',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
            'notification' => [
                'from' => '#2563eb', 'to' => '#1e40af', 'accent' => '#2563eb',
                'soft' => '#eff6ff', 'softBorder' => '#bfdbfe', 'shadow' => 'rgba(37,99,235,0.35)',
                'icon' => '🔔', 'metaIcon' => '📋',
                'badge' => $ar ? 'إشعار جديد' : 'New notification',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
            'test' => [
                'from' => '#0d9488', 'to' => '#0891b2', 'accent' => '#0d9488',
                'soft' => '#f0fdfa', 'softBorder' => '#99f6e4', 'shadow' => 'rgba(13,148,136,0.35)',
                'icon' => '🧪', 'metaIcon' => '📋',
                'badge' => $ar ? 'رسالة اختبار' : 'Test email',
                'metaTitle' => $ar ? 'التفاصيل' : 'Details',
                'note' => null,
            ],
        ];

        $brand = (string) env('MAIL_BRAND_COLOR', '#1e3a8a');
        $themes['info'] = [
            'from' => $brand, 'to' => (string) env('MAIL_BRAND_COLOR_DARK', '#0f2557'), 'accent' => $brand,
            'soft' => '#f1f5f9', 'softBorder' => '#e2e8f0', 'shadow' => 'rgba(30,58,138,0.35)',
            'icon' => '📬', 'metaIcon' => '📋',
            'badge' => $ar ? 'رسالة من المنصة' : 'Platform message',
            'metaTitle' => $ar ? 'التفاصيل' : 'Details',
            'note' => null,
        ];

        $theme = $themes[$this->variant] ?? $themes['info'];
        $theme['ring'] = 'rgba(255,255,255,0.22)';

        return $theme;
    }

    public function content(): Content
    {
        $ar      = $this->mailLocale === 'ar';
        $appName = (string) \App\Support\PlatformPolicy::value('platform_name');

        return new Content(
            view: 'emails.generic',
            with: [
                'heading'   => $this->heading,
                'lines'     => $this->lines,
                'button'    => $this->button,
                'locale'    => $this->mailLocale,
                'meta'      => $this->meta ?? [],
                'dir'       => $ar ? 'rtl' : 'ltr',
                'align'     => $ar ? 'right' : 'left',
                'theme'     => $this->theme(),
                'preheader' => $this->lines[0] ?? $this->heading,
                // اسم المنصة من APP_NAME في .env (مش hardcoded).
                'appName'   => $appName,
                'initial'   => mb_substr($appName, 0, 1),
                'year'      => date('Y'),
                // لوجو اختياري (رابط https لصورة PNG/JPG، ارتفاع ~34px) — لو
                // MAIL_LOGO_URL مش معرّف بيعرض حرف أول الاسم جوه مربع أبيض.
                'logoUrl'   => env('MAIL_LOGO_URL'),
                't'         => [
                    'arrow'    => $ar ? '←' : '→',
                    'copyLink' => $ar ? 'لو الزرار مش شغال، انسخ الرابط ده وافتحه في المتصفح:' : 'If the button doesn\'t work, copy and paste this link into your browser:',
                    'auto'     => $ar ? 'هذه رسالة تلقائية، من فضلك لا ترد عليها.' : 'This is an automated message — please do not reply to it.',
                    'rights'   => $ar ? 'جميع الحقوق محفوظة.' : 'All rights reserved.',
                ],
            ],
        );
    }
}
