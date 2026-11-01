<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Support\PlatformPolicy;

/**
 * Admin → Chat Media: full control of GIFs and stickers in messaging, no .env edit / redeploy.
 *
 *  - Giphy API key (stored ENCRYPTED through PlatformPolicy `giphy_api_key`; falls back to GIPHY_API_KEY in .env)
 *  - GIFs: master switch, search / trending switches, content rating, results per page, blocked words,
 *    and WHICH PORTALS (roles) may send them
 *  - Stickers: master switch, who may send, who may create their own, max size, video stickers, per-user limit,
 *    sticker packs on/off
 *
 * Everything is one JSON document in the `settings` table (global scope, key `chat_media_policy`).
 * Admin accounts are never locked out of the controls they manage, but they obey the master switches like everyone.
 */
class ChatMediaPolicyService
{
    public const KEY = 'chat_media_policy';
    public const RATINGS = ['g', 'pg', 'pg-13', 'r'];

    /** Portal keys (same as MessagingPolicyService::PORTALS) that can be switched per feature. */
    public const ROLE_KEYS = ['student', 'university', 'security', 'data_analyst', 'supervisor', 'faculty'];

    private static ?array $memo = null;
    private static int $memoAt = 0;

    public function __construct(private SettingRepository $settings, private MessagingPolicyService $policy)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $all = array_fill_keys(self::ROLE_KEYS, true);

        return [
            'gifs_enabled'          => true,
            'gif_search_enabled'    => true,
            'gif_trending_enabled'  => true,
            'gif_rating'            => 'pg-13',
            'gif_per_page'          => 24,
            'gif_blocked_words'     => '',
            'gif_send_roles'        => $all,
            'stickers_enabled'      => true,
            'sticker_send_roles'    => $all,
            'sticker_create_enabled' => true,
            'sticker_create_roles'  => $all,
            'sticker_max_kb'        => 4096,
            'sticker_max_per_user'  => 200,
            'sticker_allow_video'   => true,
            'sticker_packs_enabled' => true,
        ];
    }

    /** @return array<string,mixed> effective policy (defaults + whatever the admin saved) */
    public function get(): array
    {
        if (self::$memo !== null && (time() - self::$memoAt) < 10) {
            return self::$memo;
        }
        $out = self::defaults();
        try {
            $raw = (string) $this->settings->get(self::KEY, 'global', null, '');
            $saved = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($saved)) {
                foreach ($out as $k => $default) {
                    if (!array_key_exists($k, $saved)) {
                        continue;
                    }
                    $out[$k] = is_array($default) ? array_merge($default, array_intersect_key((array) $saved[$k], $default)) : $saved[$k];
                }
            }
        } catch (\Throwable $e) {
            // settings outage: keep the permissive defaults so chat keeps working
        }
        self::$memo = $out;
        self::$memoAt = time();

        return $out;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$memoAt = 0;
    }

    /**
     * Validates what the admin sent and merges it over the current policy.
     * @param array<string,mixed> $input
     * @return array<string,mixed> saved policy
     * @throws \InvalidArgumentException
     */
    public function update(array $input): array
    {
        $cur = $this->get();
        $bools = ['gifs_enabled', 'gif_search_enabled', 'gif_trending_enabled', 'stickers_enabled', 'sticker_create_enabled', 'sticker_allow_video', 'sticker_packs_enabled'];
        foreach ($bools as $k) {
            if (array_key_exists($k, $input)) {
                $cur[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['gif_send_roles', 'sticker_send_roles', 'sticker_create_roles'] as $k) {
            if (array_key_exists($k, $input) && is_array($input[$k])) {
                foreach (self::ROLE_KEYS as $r) {
                    if (array_key_exists($r, $input[$k])) {
                        $cur[$k][$r] = filter_var($input[$k][$r], FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        }
        if (array_key_exists('gif_rating', $input)) {
            $r = strtolower(trim((string) $input['gif_rating']));
            if (!in_array($r, self::RATINGS, true)) {
                throw new \InvalidArgumentException('GIF rating must be one of: ' . implode(', ', self::RATINGS) . '.');
            }
            $cur['gif_rating'] = $r;
        }
        $ints = ['gif_per_page' => [6, 50], 'sticker_max_kb' => [64, 10240], 'sticker_max_per_user' => [0, 1000]];
        foreach ($ints as $k => [$min, $max]) {
            if (array_key_exists($k, $input)) {
                if (!is_numeric($input[$k]) || (int) $input[$k] < $min || (int) $input[$k] > $max) {
                    throw new \InvalidArgumentException("{$k} must be a number between {$min} and {$max}.");
                }
                $cur[$k] = (int) $input[$k];
            }
        }
        if (array_key_exists('gif_blocked_words', $input)) {
            $words = array_values(array_unique(array_filter(array_map(
                fn ($w) => mb_strtolower(trim($w)),
                preg_split('/[\n,;،]+/u', (string) $input['gif_blocked_words']) ?: []
            ), fn ($w) => $w !== '')));
            if (count($words) > 500) {
                throw new \InvalidArgumentException('Too many blocked words (max 500).');
            }
            $cur['gif_blocked_words'] = implode(', ', array_map(fn ($w) => mb_substr($w, 0, 60), $words));
        }
        $this->settings->set(self::KEY, json_encode($cur, JSON_UNESCAPED_UNICODE), 'global', null);
        self::flush();

        return $this->get();
    }

    public function reset(): array
    {
        $this->settings->set(self::KEY, json_encode(self::defaults()), 'global', null);
        self::flush();

        return $this->get();
    }

    // ───────────────────────── who may do what ─────────────────────────

    private function roleAllowed(array $map, ?string $role): bool
    {
        if ($role === null || $role === '' || $role === 'admin') {
            return true;
        }
        $key = $this->policy->portalKeyForRole($role);

        return $key === null ? true : (bool) ($map[$key] ?? true);
    }

    public function canSendGif(?string $role): bool
    {
        $p = $this->get();

        return $p['gifs_enabled'] && $this->roleAllowed($p['gif_send_roles'], $role);
    }

    /** Browsing / searching Giphy follows the same switches as sending, plus the search / trending ones. */
    public function canBrowseGifs(?string $role, bool $searching): bool
    {
        $p = $this->get();
        if (!$this->canSendGif($role)) {
            return false;
        }

        return $searching ? $p['gif_search_enabled'] : $p['gif_trending_enabled'];
    }

    public function canSendSticker(?string $role): bool
    {
        $p = $this->get();

        return $p['stickers_enabled'] && $this->roleAllowed($p['sticker_send_roles'], $role);
    }

    public function canCreateSticker(?string $role): bool
    {
        $p = $this->get();

        return $p['stickers_enabled'] && $p['sticker_create_enabled'] && $this->roleAllowed($p['sticker_create_roles'], $role);
    }

    public function packsEnabled(?string $role): bool
    {
        $p = $this->get();

        return $p['stickers_enabled'] && $p['sticker_packs_enabled'] && $this->canCreateSticker($role);
    }

    /** @return string[] lower-cased blocked words */
    public function blockedWords(): array
    {
        $w = (string) $this->get()['gif_blocked_words'];

        return $w === '' ? [] : array_map('trim', explode(',', $w));
    }

    public function containsBlockedWord(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach ($this->blockedWords() as $w) {
            if ($w !== '' && mb_strpos($text, $w) !== false) {
                return true;
            }
        }

        return false;
    }

    public function giphyKey(): string
    {
        return trim((string) PlatformPolicy::value('giphy_api_key'));
    }

    /** What the chat UI needs to show / hide the GIF + sticker tabs for this person's portal. */
    public function forClient(?string $role): array
    {
        $p = $this->get();

        return [
            'gifs' => [
                'enabled'  => $this->canSendGif($role),
                'search'   => $this->canBrowseGifs($role, true),
                'trending' => $this->canBrowseGifs($role, false),
                'configured' => $this->giphyKey() !== '',
            ],
            'stickers' => [
                'enabled'      => $this->canSendSticker($role),
                'can_create'   => $this->canCreateSticker($role),
                'packs'        => $this->packsEnabled($role),
                'allow_video'  => (bool) $p['sticker_allow_video'],
                'max_kb'       => (int) $p['sticker_max_kb'],
                'max_per_user' => (int) $p['sticker_max_per_user'],
            ],
        ];
    }
}
