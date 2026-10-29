<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\DB;

/**
 * إعدادات الشات الاحترافية للمستخدم — مصدر واحد لكل الأدوار.
 *
 * كل إعداد هنا بيتطبّق فعليًا على السيرفر (مش مجرد شكل في الواجهة):
 *   avatar    مين يشوف صورتي            → MessagingService::isAvatarHidden
 *   bio       مين يشوف البايو بتاعي      → بيانات المشاركين في الـ inbox/thread
 *   last_seen مين يشوف آخر ظهور/أونلاين  → MessagingService::presenceFor
 *   stories   مين يشوف الاستوري بتاعتي    → StoryService (الفيد + المشاهدة/الرد)
 *   messages  مين يقدر يبدأ معايا محادثة  → MessagingService (startConversation / openOrStartDirect)
 *   calls     مين يقدر يتصل بيا          → CallService::start
 *   groups    مين يقدر يضيفني لمجموعة     → MessagingService (createGroup / addMember)
 *
 * الجمهور (audience) واحد من: everyone | contacts | contacts_except | only | nobody
 *   contacts        = الناس اللي بيني وبينهم محادثة مباشرة
 *   contacts_except = جهات اتصالي ما عدا قايمة
 *   only            = ناس محددين بس
 *
 * التخزين: جدول settings (scope=user): مفتاح JSON واحد `chat_settings_v2` + `chat_bio`، ومعاهم المفاتيح القديمة
 * (messaging_show_avatar / messaging_read_receipts_enabled) للتوافق مع الشاشات والخدمات اللي لسه بتقراها.
 */
class ChatSettingsService
{
    public const KEY = 'chat_settings_v2';
    public const BIO_KEY = 'chat_bio';
    public const BIO_MAX = 190;
    public const LIST_MAX = 300;
    public const AUDIENCES = ['everyone', 'contacts', 'contacts_except', 'only', 'nobody'];
    public const FIELDS = ['avatar', 'bio', 'last_seen', 'stories', 'messages', 'calls', 'groups'];
    public const FONT_SIZES = ['sm', 'md', 'lg'];
    public const STORY_EXPIRY = [6, 12, 24, 48];
    /** خلفيات الشات الجاهزة — لازم تطابق WALLPAPERS في الفرونت؛ الصور الشخصية بتبقى `img:<id>`. */
    public const WALLPAPERS = ['dots', 'aurora', 'lagoon', 'forest', 'rose', 'amber', 'graphite', 'midnight'];
    public const PER_CHAT_MAX = 500;

    /** @var array<int,array<string,mixed>> كاش per-request */
    private array $cache = [];
    /** @var array<string,bool> */
    private array $contactCache = [];

    public function __construct(private SettingRepository $settings)
    {
    }

    // ------------------------------------------------------------------ Defaults / read

    public static function defaults(): array
    {
        $open = ['audience' => 'everyone', 'ids' => []];
        return [
            'privacy' => [
                'avatar'    => $open,
                'bio'       => $open,
                'last_seen' => $open,
                'stories'   => ['audience' => 'contacts', 'ids' => []],
                'messages'  => $open,
                'calls'     => $open,
                'groups'    => $open,
            ],
            'read_receipts_enabled' => true,
            'typing_indicator'      => true,
            'discoverable'          => true,
            'notify_messages'       => true,
            'notify_preview'        => true,
            'stories_allow_replies' => true,
            'stories_expiry_hours'  => 24,
            'enter_to_send'         => true,
            'font_size'             => 'md',
            'sounds'                => true,
            'wallpaper'             => ['default' => 'dots', 'per_chat' => (object) [], 'anim' => false],
        ];
    }

    /** الإعدادات الكاملة (مدموجة مع الافتراضيات) بعد ما نطبّق المفاتيح القديمة. */
    public function get(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        $d = self::defaults();
        $raw = $this->settings->get(self::KEY, 'user', $userId);
        $saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $saved = is_array($saved) ? $saved : [];

        $out = $d;
        $out['initialized'] = is_string($raw) && $raw !== ''; // false = المستخدم لسه ماحفظش حاجة (الفرونت بيرحّل خلفيته القديمة مرة واحدة)
        foreach (self::FIELDS as $f) {
            $p = $saved['privacy'][$f] ?? null;
            if (is_array($p)) {
                $out['privacy'][$f] = $this->cleanPrivacy($p, $d['privacy'][$f]);
            }
        }
        // الصورة: لو مفيش اختيار جديد، نفهم الإعداد القديم (messaging_show_avatar = 0 → محدش).
        if (!isset($saved['privacy']['avatar']) && $this->settings->get('messaging_show_avatar', 'user', $userId, '1') === '0') {
            $out['privacy']['avatar'] = ['audience' => 'nobody', 'ids' => []];
        }
        foreach (['typing_indicator', 'discoverable', 'notify_messages', 'notify_preview', 'stories_allow_replies', 'enter_to_send', 'sounds'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = (bool) $saved[$k];
            }
        }
        $out['read_receipts_enabled'] = $this->settings->get('messaging_read_receipts_enabled', 'user', $userId, '1') === '1';
        if (isset($saved['stories_expiry_hours']) && in_array((int) $saved['stories_expiry_hours'], self::STORY_EXPIRY, true)) {
            $out['stories_expiry_hours'] = (int) $saved['stories_expiry_hours'];
        }
        if (isset($saved['font_size']) && in_array($saved['font_size'], self::FONT_SIZES, true)) {
            $out['font_size'] = $saved['font_size'];
        }
        $w = $saved['wallpaper'] ?? [];
        if (is_array($w)) {
            if (isset($w['default']) && $this->validWall($w['default'])) {
                $out['wallpaper']['default'] = $w['default'];
            }
            $out['wallpaper']['anim'] = (bool) ($w['anim'] ?? false);
            $per = [];
            foreach ((array) ($w['per_chat'] ?? []) as $cid => $id) {
                if ((int) $cid > 0 && $this->validWall($id)) {
                    $per[(string) (int) $cid] = $id;
                }
            }
            $out['wallpaper']['per_chat'] = (object) $per;
        }
        return $this->cache[$userId] = $out;
    }

    public function bio(int $userId): string
    {
        return (string) $this->settings->get(self::BIO_KEY, 'user', $userId, '');
    }

    /** للشاشة: كل الإعدادات + البايو + الأسماء/الصور للناس في القوايم. */
    public function forScreen(int $userId): array
    {
        $s = $this->get($userId);
        $ids = [];
        foreach (self::FIELDS as $f) {
            foreach ($s['privacy'][$f]['ids'] as $i) {
                $ids[$i] = true;
            }
        }
        $people = [];
        if ($ids) {
            foreach (DB::table('users')->whereIn('id', array_keys($ids))->whereNull('deleted_at')->get(['id', 'full_name', 'avatar_path']) as $r) {
                $people[(int) $r->id] = ['id' => (int) $r->id, 'full_name' => $r->full_name, 'avatar_path' => $r->avatar_path];
            }
        }
        $s['bio'] = $this->bio($userId);
        $s['bio_max'] = self::BIO_MAX;
        $s['people'] = (object) $people;
        $s['options'] = ['audiences' => self::AUDIENCES, 'wallpapers' => self::WALLPAPERS, 'story_expiry' => self::STORY_EXPIRY, 'font_sizes' => self::FONT_SIZES];
        return $s;
    }

    // ------------------------------------------------------------------ Write

    /**
     * تحديث جزئي: أي مفتاح مش مبعوت بيفضل زي ما هو.
     * @param array<string,mixed> $in
     * @throws \InvalidArgumentException لو قيمة مش صالحة
     */
    public function update(int $userId, array $in): array
    {
        $cur = $this->get($userId);
        $store = [
            'privacy' => $cur['privacy'],
            'typing_indicator' => $cur['typing_indicator'], 'discoverable' => $cur['discoverable'],
            'notify_messages' => $cur['notify_messages'], 'notify_preview' => $cur['notify_preview'],
            'stories_allow_replies' => $cur['stories_allow_replies'], 'stories_expiry_hours' => $cur['stories_expiry_hours'],
            'enter_to_send' => $cur['enter_to_send'], 'font_size' => $cur['font_size'], 'sounds' => $cur['sounds'],
            'wallpaper' => ['default' => $cur['wallpaper']['default'], 'per_chat' => (array) $cur['wallpaper']['per_chat'], 'anim' => $cur['wallpaper']['anim']],
        ];

        foreach ((array) ($in['privacy'] ?? []) as $field => $p) {
            if (!in_array($field, self::FIELDS, true) || !is_array($p)) {
                continue;
            }
            $audience = (string) ($p['audience'] ?? $store['privacy'][$field]['audience']);
            if (!in_array($audience, self::AUDIENCES, true)) {
                throw new \InvalidArgumentException('Invalid audience.');
            }
            $ids = array_key_exists('ids', $p) ? $this->cleanIds($p['ids'], $userId) : $store['privacy'][$field]['ids'];
            if (count($ids) > self::LIST_MAX) {
                throw new \InvalidArgumentException('Too many people in the list.');
            }
            if ($audience === 'only' && !$ids) {
                throw new \InvalidArgumentException('Pick at least one person.');
            }
            $store['privacy'][$field] = ['audience' => $audience, 'ids' => $ids];
        }

        foreach (['typing_indicator', 'discoverable', 'notify_messages', 'notify_preview', 'stories_allow_replies', 'enter_to_send', 'sounds'] as $k) {
            if (array_key_exists($k, $in)) {
                $store[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('stories_expiry_hours', $in)) {
            $h = (int) $in['stories_expiry_hours'];
            if (!in_array($h, self::STORY_EXPIRY, true)) {
                throw new \InvalidArgumentException('Invalid story duration.');
            }
            $store['stories_expiry_hours'] = $h;
        }
        if (array_key_exists('font_size', $in)) {
            if (!in_array($in['font_size'], self::FONT_SIZES, true)) {
                throw new \InvalidArgumentException('Invalid font size.');
            }
            $store['font_size'] = $in['font_size'];
        }
        if (isset($in['wallpaper']) && is_array($in['wallpaper'])) {
            $w = $in['wallpaper'];
            if (array_key_exists('default', $w)) {
                if (!$this->validWall($w['default'])) {
                    throw new \InvalidArgumentException('Invalid background.');
                }
                $store['wallpaper']['default'] = $w['default'];
            }
            if (array_key_exists('anim', $w)) {
                $store['wallpaper']['anim'] = filter_var($w['anim'], FILTER_VALIDATE_BOOLEAN);
            }
            // per_chat: { "<conversationId>": "<wall>" | null } — null بيشيل الاستثناء ويرجّع المحادثة للخلفية العامة.
            foreach ((array) ($w['per_chat'] ?? []) as $cid => $id) {
                $cid = (int) $cid;
                if ($cid <= 0) {
                    continue;
                }
                if ($id === null || $id === '' || $id === 'default') {
                    unset($store['wallpaper']['per_chat'][(string) $cid]);
                    continue;
                }
                if (!$this->validWall($id)) {
                    throw new \InvalidArgumentException('Invalid background.');
                }
                if (!isset($store['wallpaper']['per_chat'][(string) $cid]) && count($store['wallpaper']['per_chat']) >= self::PER_CHAT_MAX) {
                    throw new \InvalidArgumentException('Too many chats with their own background.');
                }
                // مسموح بس لمحادثة المستخدم عضو فيها
                if (!DB::table('conversation_participants')->where('conversation_id', $cid)->where('user_id', $userId)->exists()) {
                    continue;
                }
                $store['wallpaper']['per_chat'][(string) $cid] = $id;
            }
        }

        $this->settings->set(self::KEY, json_encode($store, JSON_UNESCAPED_UNICODE), 'user', $userId);

        // المفاتيح القديمة (الشاشات/الخدمات اللي لسه بتقراها).
        if (array_key_exists('read_receipts_enabled', $in)) {
            $this->settings->set('messaging_read_receipts_enabled', filter_var($in['read_receipts_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'user', $userId);
        }
        if (isset($in['privacy']['avatar'])) {
            $this->settings->set('messaging_show_avatar', $store['privacy']['avatar']['audience'] === 'nobody' ? '0' : '1', 'user', $userId);
        }

        if (array_key_exists('bio', $in)) {
            $bio = trim(preg_replace('/\s+/u', ' ', (string) $in['bio']));
            if (mb_strlen($bio) > self::BIO_MAX) {
                throw new \InvalidArgumentException('Bio is too long.');
            }
            $this->settings->set(self::BIO_KEY, $bio, 'user', $userId);
        }

        unset($this->cache[$userId]);
        $this->contactCache = [];
        return $this->forScreen($userId);
    }

    /** بيشيل خلفية محادثة اتحذفت/خرج منها (تنضيف) — بيستعمله الـ controller لو احتاج. */
    public function forgetChat(int $userId, int $conversationId): void
    {
        $this->update($userId, ['wallpaper' => ['per_chat' => [(string) $conversationId => null]]]);
    }

    // ------------------------------------------------------------------ Enforcement

    /** هل $viewerId مسموحله يتعامل مع $ownerId في الخاصية $field؟ (صاحب الإعداد مسموحله دايمًا). */
    public function allows(int $ownerId, ?int $viewerId, string $field): bool
    {
        if ($viewerId !== null && $ownerId === $viewerId) {
            return true;
        }
        $p = $this->get($ownerId)['privacy'][$field] ?? ['audience' => 'everyone', 'ids' => []];
        if ($viewerId === null) {
            // مفيش مشاهد معروف (سياق عام): بس "الكل" هو اللي يعدّي.
            return $p['audience'] === 'everyone';
        }
        switch ($p['audience']) {
            case 'everyone':
                return true;
            case 'nobody':
                return false;
            case 'contacts':
                return $this->isContact($ownerId, $viewerId);
            case 'contacts_except':
                return $this->isContact($ownerId, $viewerId) && !in_array($viewerId, $p['ids'], true);
            case 'only':
                return in_array($viewerId, $p['ids'], true);
        }
        return true;
    }

    /** جهات الاتصال = بينهم محادثة مباشرة (مش مجموعة ولا "الرسائل المحفوظة"). */
    public function isContact(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $key = min($a, $b) . ':' . max($a, $b);
        if (!array_key_exists($key, $this->contactCache)) {
            $this->contactCache[$key] = DB::table('conversations as c')
                ->join('conversation_participants as pa', function ($j) use ($a) {
                    $j->on('pa.conversation_id', '=', 'c.id')->where('pa.user_id', '=', $a);
                })
                ->join('conversation_participants as pb', function ($j) use ($b) {
                    $j->on('pb.conversation_id', '=', 'c.id')->where('pb.user_id', '=', $b);
                })
                ->where('c.is_group', 0)
                ->whereNull('c.saved_for_user_id')
                ->exists();
        }
        return $this->contactCache[$key];
    }

    public function readReceiptsEnabled(int $userId): bool
    {
        return $this->get($userId)['read_receipts_enabled'];
    }

    public function typingVisible(int $userId): bool
    {
        return $this->get($userId)['typing_indicator'];
    }

    public function discoverable(int $userId): bool
    {
        return $this->get($userId)['discoverable'];
    }

    /** @return array{notify:bool,preview:bool} */
    public function notifyPrefs(int $userId): array
    {
        $s = $this->get($userId);
        return ['notify' => $s['notify_messages'], 'preview' => $s['notify_preview']];
    }

    /**
     * قيمة افتراضية للاستوري الجديدة من إعدادات المستخدم: audience (للـ StoryService) + ids + مدة + الردود.
     * @return array{audience:string,audience_ids:int[],expires_hours:int,allow_replies:bool}
     */
    public function storyDefaults(int $userId): array
    {
        $s = $this->get($userId);
        $p = $s['privacy']['stories'];
        $map = ['everyone' => 'everyone', 'contacts' => 'contacts', 'contacts_except' => 'except', 'only' => 'selected', 'nobody' => 'selected'];
        return [
            'audience'      => $map[$p['audience']] ?? 'contacts',
            'audience_ids'  => $p['audience'] === 'nobody' ? [] : $p['ids'],
            'expires_hours' => $s['stories_expiry_hours'],
            'allow_replies' => $s['stories_allow_replies'],
        ];
    }

    // ------------------------------------------------------------------ Helpers

    private function validWall($v): bool
    {
        if (!is_string($v)) {
            return false;
        }
        return in_array($v, self::WALLPAPERS, true) || (bool) preg_match('/^img:[A-Za-z0-9_-]{1,64}$/', $v);
    }

    private function cleanPrivacy(array $p, array $fallback): array
    {
        $aud = in_array($p['audience'] ?? null, self::AUDIENCES, true) ? $p['audience'] : $fallback['audience'];
        return ['audience' => $aud, 'ids' => $this->cleanIds($p['ids'] ?? [], 0)];
    }

    /** @return int[] */
    private function cleanIds($ids, int $self): array
    {
        $out = [];
        foreach ((array) $ids as $i) {
            $i = (int) $i;
            if ($i > 0 && $i !== $self) {
                $out[$i] = $i;
            }
        }
        return array_values($out);
    }
}
