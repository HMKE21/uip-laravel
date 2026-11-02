<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Posts Control: كتالوج إعدادات المنشورات اللي الأدمن يغيّرها وقت التشغيل (من غير deploy).
 * بتتخزن في جدول settings (scope=global) بمفتاح posts_<key> — نفس نمط MeetingAdminSettings.
 * الأنواع: bool | int | float. لو مفيش override بنرجع للافتراضي المكتوب هنا.
 *
 * الأوزان (w_*) هي قلب خوارزمية ترتيب الفيد (PostFeedService) — تعديلها بيغيّر الترتيب فورًا (كاش القوايم 90 ثانية).
 */
class PostSettings
{
    public const PREFIX = 'posts_';
    public const GROUPS = ['system', 'limits', 'moderation', 'ranking'];

    /** @var array<string,string>|null */
    private static ?array $cache = null;
    private static int $cacheAt = 0;

    /** @return array<string,array{type:string,default:mixed,group:string,min?:float,max?:float}> */
    public static function catalog(): array
    {
        return [
            // system
            'enabled'               => ['type' => 'bool', 'default' => true, 'group' => 'system'],
            'allow_public'          => ['type' => 'bool', 'default' => true, 'group' => 'system'],
            'allow_scheduling'      => ['type' => 'bool', 'default' => true, 'group' => 'system'],
            'allow_reposts'         => ['type' => 'bool', 'default' => true, 'group' => 'system'],
            'convert_office_to_pdf' => ['type' => 'bool', 'default' => false, 'group' => 'system'], // يحتاج LibreOffice على السيرفر

            // limits
            'daily_post_limit'      => ['type' => 'int', 'default' => 30, 'group' => 'limits', 'min' => 1, 'max' => 500],
            'max_media_per_post'    => ['type' => 'int', 'default' => 20, 'group' => 'limits', 'min' => 1, 'max' => 50],
            'max_file_mb'           => ['type' => 'int', 'default' => 200, 'group' => 'limits', 'min' => 1, 'max' => 2048],
            'max_orphan_hours'      => ['type' => 'int', 'default' => 24, 'group' => 'limits', 'min' => 1, 'max' => 168],

            // moderation
            'require_approval'      => ['type' => 'bool', 'default' => false, 'group' => 'moderation'], // منشورات الطلاب تستنى موافقة
            'auto_remove_reports'   => ['type' => 'int', 'default' => 0, 'group' => 'moderation', 'min' => 0, 'max' => 100], // 0 = معطّل

            // ranking (للفيد "For you")
            'candidate_days'        => ['type' => 'int', 'default' => 21, 'group' => 'ranking', 'min' => 1, 'max' => 120],
            'candidate_limit'       => ['type' => 'int', 'default' => 400, 'group' => 'ranking', 'min' => 50, 'max' => 2000],
            'half_life_hours'       => ['type' => 'float', 'default' => 36.0, 'group' => 'ranking', 'min' => 1, 'max' => 720],
            'w_recency'             => ['type' => 'float', 'default' => 1.0, 'group' => 'ranking', 'min' => 0, 'max' => 5],
            'w_affinity'            => ['type' => 'float', 'default' => 1.4, 'group' => 'ranking', 'min' => 0, 'max' => 5],
            'w_engagement'          => ['type' => 'float', 'default' => 1.0, 'group' => 'ranking', 'min' => 0, 'max' => 5],
            'w_interest'            => ['type' => 'float', 'default' => 0.8, 'group' => 'ranking', 'min' => 0, 'max' => 5],
            'w_network'             => ['type' => 'float', 'default' => 0.6, 'group' => 'ranking', 'min' => 0, 'max' => 5],
            'w_media'               => ['type' => 'float', 'default' => 0.15, 'group' => 'ranking', 'min' => 0, 'max' => 2],
            'seen_penalty'          => ['type' => 'float', 'default' => 0.5, 'group' => 'ranking', 'min' => 0, 'max' => 0.95],
            'diversity_penalty'     => ['type' => 'float', 'default' => 0.35, 'group' => 'ranking', 'min' => 0, 'max' => 0.9],
            'own_post_boost'        => ['type' => 'float', 'default' => 0.2, 'group' => 'ranking', 'min' => 0, 'max' => 2],
        ];
    }

    /** @return bool|int|float */
    public static function get(string $key)
    {
        $def = self::catalog()[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Unknown posts setting: ' . $key);
        }
        $stored = self::stored();
        if (!isset($stored[$key]) || $stored[$key] === '') {
            return $def['default'];
        }
        return self::cast($def['type'], $stored[$key], $def['default']);
    }

    /** كل الإعدادات (للوحة الأدمن): key => [value, default, type, group, min, max] */
    public static function all(): array
    {
        $out = [];
        foreach (self::catalog() as $key => $def) {
            $out[$key] = [
                'value'   => self::get($key),
                'default' => $def['default'],
                'type'    => $def['type'],
                'group'   => $def['group'],
                'min'     => $def['min'] ?? null,
                'max'     => $def['max'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> القيم اللي اتحفظت فعلًا
     * @throws \RuntimeException لو مفتاح مجهول أو قيمة خارج الحدود
     */
    public function update(array $input): array
    {
        $catalog = self::catalog();
        $repo = app(SettingRepository::class);
        $saved = [];
        foreach ($input as $key => $raw) {
            if (!isset($catalog[$key])) {
                throw new \RuntimeException('Unknown setting: ' . $key);
            }
            $def = $catalog[$key];
            $value = self::cast($def['type'], $raw, $def['default']);
            if ($def['type'] !== 'bool') {
                if (isset($def['min']) && $value < $def['min']) {
                    throw new \RuntimeException("{$key} must be at least {$def['min']}.");
                }
                if (isset($def['max']) && $value > $def['max']) {
                    throw new \RuntimeException("{$key} must be at most {$def['max']}.");
                }
            }
            $repo->set(self::PREFIX . $key, $def['type'] === 'bool' ? ($value ? '1' : '0') : (string) $value);
            $saved[$key] = $value;
        }
        self::$cache = null;
        return $saved;
    }

    /** يرجّع الأوزان كمصفوفة جاهزة لـ PostFeedService. */
    public static function rankingWeights(): array
    {
        $out = [];
        foreach (self::catalog() as $key => $def) {
            if ($def['group'] === 'ranking') {
                $out[$key] = (float) self::get($key);
            }
        }
        return $out;
    }

    private static function cast(string $type, $raw, $default)
    {
        return match ($type) {
            'bool'  => in_array(strtolower((string) (is_bool($raw) ? ($raw ? '1' : '0') : $raw)), ['1', 'true', 'on', 'yes'], true),
            'int'   => (int) $raw,
            'float' => (float) $raw,
            default => $default,
        };
    }

    /** @return array<string,string> */
    private static function stored(): array
    {
        if (self::$cache !== null && (time() - self::$cacheAt) < 3) {
            return self::$cache;
        }
        $out = [];
        try {
            $rows = DB::table('settings')
                ->where('scope', 'global')
                ->whereNull('user_id')
                ->where('key', 'like', self::PREFIX . '%')
                ->get(['key', 'value']);
            foreach ($rows as $row) {
                $out[substr($row->key, strlen(self::PREFIX))] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            // جدول settings مش موجود لسه -> الافتراضي.
        }
        self::$cache = $out;
        self::$cacheAt = time();
        return $out;
    }
}
