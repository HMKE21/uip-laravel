<?php

namespace App\Services;

use App\Repositories\SettingRepository;

/**
 * خصوصية الصورة الشخصية — مصدر واحد لكل الأدوار.
 *
 * كل مستخدم (طالب/جامعة/كلية/أدمن/سيكيورتي/محلل بيانات/هيئة تدريس...) يقدر يقرر
 * هل صورته تظهر للناس (الشات، البحث عن الأشخاص، الصفحات العامة، الفيد، الاجتماعات)
 * ولا لا. الإعداد محفوظ في settings (scope=user, key=profile_photo_public)،
 * والافتراضي "ظاهرة". صاحب الصورة بيشوف صورته دايمًا.
 */
class AvatarPrivacyService
{
    public const KEY = 'profile_photo_public';

    /** @var array<int,bool> كاش per-request: user_id => مخفية؟ */
    private array $hiddenCache = [];

    public function __construct(private SettingRepository $settings)
    {
    }

    public function isPublic(int $userId): bool
    {
        return !$this->isHidden($userId);
    }

    public function setPublic(int $userId, bool $public): void
    {
        $this->settings->set(self::KEY, $public ? '1' : '0', 'user', $userId);
        unset($this->hiddenCache[$userId]);
    }

    /** هل صورة $ownerId مخفية عن $viewerId؟ (صاحب الصورة مش بتتخفى عنه). */
    public function isHidden(int $ownerId, $viewerId = null): bool
    {
        if ($viewerId !== null && (string) $ownerId === (string) $viewerId) {
            return false;
        }
        if (!array_key_exists($ownerId, $this->hiddenCache)) {
            $this->hiddenCache[$ownerId] = $this->settings->get(self::KEY, 'user', $ownerId, '1') === '0';
        }
        return $this->hiddenCache[$ownerId];
    }

    /** يرجّع المسار أو null لو صاحبه مخفيه — الفرونت بيرجع للحروف الأولى لما يبقى null. */
    public function apply(?string $path, int $ownerId, $viewerId = null): ?string
    {
        if (!$path) {
            return null;
        }
        return $this->isHidden($ownerId, $viewerId) ? null : $path;
    }
}
