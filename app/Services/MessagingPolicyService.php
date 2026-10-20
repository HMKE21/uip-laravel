<?php

namespace App\Services;

use App\Repositories\MessageRepository;
use App\Repositories\SettingRepository;

/**
 * منقولة من app/Services/MessagingPolicyService.php القديمة — بند 25
 * batch 5. الطبقة القابلة للتعديل من الأدمن فوق config/messaging.php:
 * القيم عايشة في جدول `settings` العام (scope=global، SettingRepository —
 * نفس الآلية اللي NotificationService::retentionPolicy() بتستخدمها)،
 * القيمة الغير متسطة بترجع لـ config/messaging.php أو default ثابت
 * منطقي، وكل تغيير بيتسجل في AuditLogService (من الكنترولر).
 *
 * - Upload limits: override خاص بالرسايل (أقصى حجم + امتدادات مسموحة)
 *   MessagingService::storeAttachment() (بند 18) بيمرره لـ
 *   FileUploadService::store() — أولوية فوق الـ default العام. غير
 *   متسط = استمرار استخدام الـ default العام (بدون تغيير سلوك).
 * - Retention: حذف تلقائي للرسايل الأقدم من X يوم على مستوى المنصة —
 *   بيتنفذ عبر MessageRepository::purgeOlderThan() (applyRetentionPolicy()
 *   هنا هو نقطة الدخول اللي أي مجدول/scheduler لاحق هيستخدمها، مرآة
 *   NotificationService::applyRetentionPolicies() بالظبط).
 * - Portal toggles: تفعيل/تعطيل الرسايل لكل بورتال — خريطة JSON واحدة
 *   من portal-key => enabled.
 */
class MessagingPolicyService
{
    /** مفاتيح البورتالات اللي الشاشة دي تقدر تبدّلها، مربوطة بالـ role اللي البورتال بيسجّل دخول بيه. رسايل الأدمن نفسه مينفعش تتعطّل — هي كمان مكان عيش الكونترولز دي. */
    public const PORTALS = [
        'student'      => ['student'],
        'university'   => ['university'],
        'security'     => ['security_admin', 'security_officer'],
        'data_analyst' => ['data_analyst'],
        'supervisor'   => ['supervisor'],
        'faculty'      => ['faculty'],
    ];

    public function __construct(
        private SettingRepository $settings,
        private AuditLogService $auditLog,
        private MessageRepository $messages
    ) {
    }

    // -- Upload limits (override خاص بالرسايل) ---------------------------

    /** @return array{max_kb:int,restrict_extensions:bool,allowed_extensions:string} 0/فاضي = مفيش override، الـ caller بيرجع للـ default العام */
    public function getUploadPolicy(): array
    {
        $configDefault = (int) config('messaging.max_attachment_kb', config('upload.max_size_kb', 10240));

        return [
            'max_kb'              => (int) $this->settings->get('messaging_upload_max_kb', 'global', null, (string) $configDefault),
            'restrict_extensions' => $this->settings->get('messaging_upload_restrict_extensions', 'global', null, '0') === '1',
            'allowed_extensions'  => (string) $this->settings->get('messaging_upload_allowed_extensions', 'global', null, ''),
        ];
    }

    /** @throws \InvalidArgumentException لو الإدخال غلط */
    public function updateUploadPolicy(array $input, $adminUserId): array
    {
        $before = $this->getUploadPolicy();

        $maxKb = (int) ($input['max_kb'] ?? 0);
        if ($maxKb < 1 || $maxKb > 512000) {
            throw new \InvalidArgumentException('Maximum attachment size must be between 1 KB and 512000 KB (500 MB).');
        }

        $extensionsRaw = trim((string) ($input['allowed_extensions'] ?? ''));
        $extensions = array_values(array_unique(array_filter(array_map(
            static fn ($e) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim($e))),
            explode(',', $extensionsRaw)
        ))));

        $restrict = !empty($input['restrict_extensions']);
        if ($restrict && !$extensions) {
            throw new \InvalidArgumentException('Add at least one allowed extension before enabling the extension restriction.');
        }

        $this->settings->set('messaging_upload_max_kb', (string) $maxKb, 'global', null);
        $this->settings->set('messaging_upload_restrict_extensions', $restrict ? '1' : '0', 'global', null);
        $this->settings->set('messaging_upload_allowed_extensions', implode(',', $extensions), 'global', null);

        $after = ['max_kb' => $maxKb, 'restrict_extensions' => $restrict, 'allowed_extensions' => implode(',', $extensions)];
        $this->auditLog->record($adminUserId, 'admin.messaging_upload_policy_update', 'Setting', null, $before, $after);

        return $after;
    }

    /** Override فعّال لـ FileUploadService::store($file, 'messages', ..., $maxKb, $allowed) — null $allowed = default الفئة (مفيش تقييد متسط). */
    public function effectiveUploadOverride(): array
    {
        $p = $this->getUploadPolicy();
        $allowed = null;
        if ($p['restrict_extensions'] && $p['allowed_extensions'] !== '') {
            $allowed = array_values(array_filter(array_map('trim', explode(',', $p['allowed_extensions']))));
        }
        return [$p['max_kb'] > 0 ? $p['max_kb'] : null, $allowed];
    }

    // -- الاحتفاظ / الحذف التلقائي ----------------------------------------

    /** @return array{retention_days:int,auto_cleanup_enabled:bool} 0 يوم = احتفاظ للأبد */
    public function getRetentionPolicy(): array
    {
        return [
            'retention_days'       => (int) $this->settings->get('messaging_retention_days', 'global', null, '0'),
            'auto_cleanup_enabled' => $this->settings->get('messaging_auto_cleanup_enabled', 'global', null, '0') === '1',
        ];
    }

    /** @throws \InvalidArgumentException لو الإدخال غلط */
    public function updateRetentionPolicy(array $input, $adminUserId): array
    {
        $before = $this->getRetentionPolicy();

        $days = (int) ($input['retention_days'] ?? 0);
        if ($days < 0 || $days > 3650) {
            throw new \InvalidArgumentException('Retention period must be between 0 (keep forever) and 3650 days.');
        }
        $enabled = !empty($input['auto_cleanup_enabled']);
        if ($enabled && $days <= 0) {
            throw new \InvalidArgumentException('Set a retention period greater than 0 before enabling automatic cleanup.');
        }

        $this->settings->set('messaging_retention_days', (string) $days, 'global', null);
        $this->settings->set('messaging_auto_cleanup_enabled', $enabled ? '1' : '0', 'global', null);

        $after = ['retention_days' => $days, 'auto_cleanup_enabled' => $enabled];
        $this->auditLog->record($adminUserId, 'admin.messaging_retention_policy_update', 'Setting', null, $before, $after);

        return $after;
    }

    /**
     * نقطة دخول حقيقية لأي مجدول/cron لاحق يستدعيها — مرآة
     * NotificationService::applyRetentionPolicies() بالظبط: سياسة معطّلة
     * (auto_cleanup_enabled = false) أو نافذة 0-يوم = no-op، فآمن دايمًا
     * حتى لو الأدمن لسه ما ظبطش حاجة.
     * @return array{enabled:bool,days:int,deleted:int}
     */
    public function applyRetentionPolicy(): array
    {
        $policy = $this->getRetentionPolicy();

        if (!$policy['auto_cleanup_enabled'] || $policy['retention_days'] <= 0) {
            return ['enabled' => false, 'days' => $policy['retention_days'], 'deleted' => 0];
        }

        $deleted = $this->messages->purgeOlderThan($policy['retention_days']);

        return ['enabled' => true, 'days' => $policy['retention_days'], 'deleted' => $deleted];
    }

    // -- تفعيل/تعطيل لكل بورتال --------------------------------------------

    /** @return array<string,bool> كل مفتاح بورتال => مفعّل (default true — مفيش حاجة معطّلة من الأساس) */
    public function portalToggles(): array
    {
        $raw = $this->settings->get('messaging_portal_toggles', 'global', null, '');
        $decoded = $raw !== '' ? json_decode((string) $raw, true) : [];
        $decoded = is_array($decoded) ? $decoded : [];

        $result = [];
        foreach (array_keys(self::PORTALS) as $key) {
            $result[$key] = !array_key_exists($key, $decoded) || (bool) $decoded[$key];
        }
        return $result;
    }

    public function setPortalEnabled(string $portalKey, bool $enabled, $adminUserId): void
    {
        if (!array_key_exists($portalKey, self::PORTALS)) {
            throw new \InvalidArgumentException('Unknown portal.');
        }

        $before = $this->portalToggles();
        $toggles = $before;
        $toggles[$portalKey] = $enabled;

        $this->settings->set('messaging_portal_toggles', json_encode($toggles, JSON_UNESCAPED_UNICODE), 'global', null);

        $this->auditLog->record(
            $adminUserId,
            $enabled ? 'admin.messaging_portal_enabled' : 'admin.messaging_portal_disabled',
            'Setting',
            null,
            ['portal' => $portalKey, 'enabled' => $before[$portalKey]],
            ['portal' => $portalKey, 'enabled' => $enabled]
        );
    }

    /** رسايل الأدمن نفسه مينفعش تتعطّل أبدًا — هنا مكان عيش الكونترولز دي، وهي قناة الإشراف نفسها. */
    public function isEnabledForRole(?string $role): bool
    {
        if ($role === null || $role === 'admin') {
            return true;
        }
        $toggles = $this->portalToggles();
        foreach (self::PORTALS as $key => $roles) {
            if (in_array($role, $roles, true)) {
                return $toggles[$key] ?? true;
            }
        }
        return true;
    }

    /** مفتاح البورتال لـ role الجلسة، أو null لو الـ role مش من البورتالات القابلة للتبديل (مثلًا 'admin'). */
    public function portalKeyForRole(?string $role): ?string
    {
        foreach (self::PORTALS as $key => $roles) {
            if ($role !== null && in_array($role, $roles, true)) {
                return $key;
            }
        }
        return null;
    }
}
