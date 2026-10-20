<?php

namespace App\Services;

use App\Repositories\SettingRepository;

/**
 * ⚠️ فرق متعمّد — دي شريحة من app/Services/NotificationService.php القديمة
 * (اسم مختلف عمدًا: NotificationPreferencesService بدل NotificationService،
 * عشان الاسم يعكس النطاق الفعلي المنقول). UniversitiesApiController
 * القديمة كانت بتستخدم من NotificationService بس الـ 7 متودز الخاصة
 * بتفضيلات الإشعارات (categories/digest/quiet-hours) — مش الإرسال الفعلي
 * (notify()/الـ dedup/الـ email queue...)، ده لسه بند 19. لو بند 19 محتاج
 * الشكل ده تاني، الأسهل حقنه هنا زي ما هو بدل تكراره.
 */
class NotificationPreferencesService
{
    /** نفس CATEGORY_LABELS القديمة بالظبط — شاشة الإعدادات/تفضيلات الإشعارات. */
    private const CATEGORY_LABELS = [
        'messages'    => ['en' => 'Messages & mentions',        'ar' => 'الرسائل والإشارات'],
        'university'  => ['en' => 'University feed & announcements', 'ar' => 'فيد الجامعة والإعلانات'],
        'projects'    => ['en' => 'Project approvals',          'ar' => 'اعتماد المشاريع'],
        'ai'          => ['en' => 'AI analysis',                'ar' => 'تحليل الذكاء الاصطناعي'],
        'reports'     => ['en' => 'Reports',                    'ar' => 'التقارير'],
        'invitations' => ['en' => 'Invitations & teams',        'ar' => 'الدعوات والفرق'],
        'files'       => ['en' => 'Files & sharing',            'ar' => 'الملفات والمشاركة'],
        'security'    => ['en' => 'Security & login',           'ar' => 'الأمان وتسجيل الدخول'],
        'system'      => ['en' => 'System',                     'ar' => 'النظام'],
        'general'     => ['en' => 'General',                    'ar' => 'عام'],
    ];

    private const VALID_DIGEST_FREQUENCIES = ['immediate', 'daily', 'weekly'];

    public function __construct(private SettingRepository $settings)
    {
    }

    /** @return array<string,array{en:string,ar:string}> */
    public function categoryLabels(): array
    {
        return self::CATEGORY_LABELS;
    }

    /** @return array<int,string> */
    public function mutedCategoriesFor(int $userId): array
    {
        $raw = $this->settings->get('notification_muted_categories', 'user', $userId, '[]');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int,string> $categories */
    public function setMutedCategories(int $userId, array $categories): void
    {
        $valid = array_keys(self::CATEGORY_LABELS);
        $categories = array_values(array_intersect(array_unique(array_map('strval', $categories)), $valid));
        $this->settings->set('notification_muted_categories', json_encode($categories), 'user', $userId);
    }

    public function digestFrequencyFor(int $userId): string
    {
        $value = (string) $this->settings->get('notification_digest_frequency', 'user', $userId, 'immediate');
        return in_array($value, self::VALID_DIGEST_FREQUENCIES, true) ? $value : 'immediate';
    }

    public function setDigestFrequency(int $userId, string $frequency): void
    {
        if (!in_array($frequency, self::VALID_DIGEST_FREQUENCIES, true)) {
            $frequency = 'immediate';
        }
        $this->settings->set('notification_digest_frequency', $frequency, 'user', $userId);
    }

    /** @return array{start:?string,end:?string} 'HH:MM' 24h، NULL/NULL = متوقف. */
    public function quietHoursFor(int $userId): array
    {
        $start = $this->settings->get('notification_quiet_hours_start', 'user', $userId, '');
        $end = $this->settings->get('notification_quiet_hours_end', 'user', $userId, '');
        return [
            'start' => $this->normalizeTime((string) $start),
            'end'   => $this->normalizeTime((string) $end),
        ];
    }

    public function setQuietHours(int $userId, ?string $start, ?string $end): void
    {
        $start = $this->normalizeTime((string) $start);
        $end = $this->normalizeTime((string) $end);
        // واحد بس بدون التاني معناهاش حاجة (مفيش نافذة يرجع عندها).
        if ($start === null || $end === null) {
            $start = $end = null;
        }
        $this->settings->set('notification_quiet_hours_start', $start ?? '', 'user', $userId);
        $this->settings->set('notification_quiet_hours_end', $end ?? '', 'user', $userId);
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return null;
        }
        return $value;
    }
}
