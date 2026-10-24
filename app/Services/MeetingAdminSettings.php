<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin → Meetings Control: كتالوج واحد لكل إعداد في نظام الاجتماعات يقدر الأدمن يغيّره
 * وقت التشغيل (من غير deploy). القيمة بتتخزن في جدول `settings` (scope=global) بمفتاح
 * `meeting_<key>` — نفس المفاتيح اللي MeetingPolicyService كانت بتقراها قبل كده
 * (meeting_default_duration_minutes ...) فأي قيمة متخزنة قديمة بتفضل شغالة.
 * لو مفيش override بنرجع للقيمة الافتراضية (config/meetings.php أو الافتراضي المكتوب هنا).
 *
 * الأنواع: bool | int | string | list_int | list_str
 * القوائم بتتخزن JSON.
 */
class MeetingAdminSettings
{
    public const PREFIX = 'meeting_';

    /** @var array<string,string>|null */
    private static ?array $cache = null;
    private static int $cacheAt = 0;

    /** مجموعات العرض في لوحة الأدمن (بالترتيب). */
    public const GROUPS = ['system', 'creation', 'limits', 'access', 'features', 'defaults', 'recording'];

    /**
     * @return array<string, array{group:string,type:string,default:mixed,min?:int,max?:int,max_length?:int}>
     */
    public static function catalog(): array
    {
        return [
            // -- النظام ----------------------------------------------------
            'feature_meetings'     => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'maintenance_message'  => ['group' => 'system', 'type' => 'string', 'default' => '', 'max_length' => 300],

            // -- الإنشاء ---------------------------------------------------
            'allow_instant'                 => ['group' => 'creation', 'type' => 'bool', 'default' => true],
            'allow_scheduled'               => ['group' => 'creation', 'type' => 'bool', 'default' => true],
            'creator_roles'                 => ['group' => 'creation', 'type' => 'list_str', 'default' => []],
            'blocked_user_ids'              => ['group' => 'creation', 'type' => 'list_int', 'default' => []],
            'max_active_meetings_per_user'  => ['group' => 'creation', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1000],
            'max_scheduled_meetings_per_user' => ['group' => 'creation', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 5000],
            'max_schedule_ahead_days'       => ['group' => 'creation', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 3650],
            'require_password'              => ['group' => 'creation', 'type' => 'bool', 'default' => false],

            // -- الحدود ----------------------------------------------------
            'default_duration_minutes' => ['group' => 'limits', 'type' => 'int', 'default' => (int) config('meetings.default_duration_minutes', 60), 'min' => 5, 'max' => 1440],
            'max_duration_minutes'     => ['group' => 'limits', 'type' => 'int', 'default' => (int) config('meetings.max_duration_minutes', 480), 'min' => 5, 'max' => 1440],
            'max_mesh_participants'     => ['group' => 'limits', 'type' => 'int', 'default' => (int) config('meetings.max_mesh_participants', 8), 'min' => 2, 'max' => 500],
            'presence_stale_seconds'   => ['group' => 'limits', 'type' => 'int', 'default' => (int) config('meetings.presence_stale_seconds', 90), 'min' => 30, 'max' => 900],

            // -- الدخول والدعوات -------------------------------------------
            'waiting_room_default'       => ['group' => 'access', 'type' => 'bool', 'default' => (bool) config('meetings.waiting_room_default_enabled', true)],
            'force_waiting_room'         => ['group' => 'access', 'type' => 'bool', 'default' => false],
            'allow_guests_default'       => ['group' => 'access', 'type' => 'bool', 'default' => (bool) config('meetings.allow_guests_default', true)],
            'guest_session_ttl_minutes'  => ['group' => 'access', 'type' => 'int', 'default' => (int) config('meetings.guest_session_ttl_minutes', 480), 'min' => 5, 'max' => 1440],
            'invitation_expiry_hours'    => ['group' => 'access', 'type' => 'int', 'default' => (int) config('meetings.invitation_expiry_hours', 72), 'min' => 1, 'max' => 720],
            'reminder_minutes_before'    => ['group' => 'access', 'type' => 'int', 'default' => (int) config('meetings.reminder_minutes_before', 10), 'min' => 1, 'max' => 1440],

            // -- مفاتيح الميزات (kill switches) ----------------------------
            'feature_guests'         => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_invitations'    => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_chat'           => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_private_chat'   => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_files'          => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_recording'      => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_polls'          => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_notes'          => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_screen_share'   => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_remote_control' => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_reactions'      => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_hand_raise'     => ['group' => 'features', 'type' => 'bool', 'default' => true],

            // -- افتراضيات الاجتماع الجديد ---------------------------------
            'default_mute_on_entry'      => ['group' => 'defaults', 'type' => 'bool', 'default' => (bool) config('meetings.default_settings.mute_on_entry', false)],
            'default_camera_on_entry'    => ['group' => 'defaults', 'type' => 'bool', 'default' => (bool) config('meetings.default_settings.camera_on_entry', true)],
            'default_allow_chat'         => ['group' => 'defaults', 'type' => 'bool', 'default' => (bool) config('meetings.default_settings.allow_chat', true)],
            'default_allow_file_sharing' => ['group' => 'defaults', 'type' => 'bool', 'default' => (bool) config('meetings.default_settings.allow_file_sharing', true)],
            'default_recording_enabled'  => ['group' => 'defaults', 'type' => 'bool', 'default' => (bool) config('meetings.default_settings.recording_enabled', true)],

            // -- التسجيل ---------------------------------------------------
            'recording_max_kb'         => ['group' => 'recording', 'type' => 'int', 'default' => (int) config('meetings.recording_max_kb', 512000), 'min' => 1024, 'max' => 5242880],
            'recording_process_inline' => ['group' => 'recording', 'type' => 'bool', 'default' => (bool) config('meetings.recording_process_inline', true)],
        ];
    }

    /** مفاتيح الميزات اللي الفرونت بيستخدمها لإخفاء الأزرار (اسم قصير => مفتاح الكتالوج). */
    public const FEATURES = [
        'meetings'       => 'feature_meetings',
        'guests'         => 'feature_guests',
        'invitations'    => 'feature_invitations',
        'chat'           => 'feature_chat',
        'private_chat'   => 'feature_private_chat',
        'files'          => 'feature_files',
        'recording'      => 'feature_recording',
        'polls'          => 'feature_polls',
        'notes'          => 'feature_notes',
        'screen_share'   => 'feature_screen_share',
        'remote_control' => 'feature_remote_control',
        'reactions'      => 'feature_reactions',
        'hand_raise'     => 'feature_hand_raise',
    ];

    // ------------------------------------------------------------------
    // القراءة
    // ------------------------------------------------------------------

    /** كل الـ overrides المتخزنة (key بدون البادئة => string). كاش 3 ثواني عشان الـ workers الطويلة. */
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
                $out[substr($row->key, strlen(self::PREFIX))] = $row->value;
            }
        } catch (\Throwable $e) {
            // جدول settings مش موجود لسه (أول migration مثلًا) -> الافتراضي.
        }

        self::$cache = $out;
        self::$cacheAt = time();

        return $out;
    }

    public static function flush(): void
    {
        self::$cache = null;
        self::$cacheAt = 0;
    }

    public static function isOverridden(string $key): bool
    {
        $stored = self::stored();

        return isset($stored[$key]) && $stored[$key] !== '';
    }

    /** @return bool|int|string|array القيمة الحالية (override لو موجود، وإلا الافتراضي). */
    public static function get(string $key)
    {
        $def = self::catalog()[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Unknown meeting setting: ' . $key);
        }

        $stored = self::stored();
        if (!isset($stored[$key]) || ($stored[$key] === '' && $def['type'] !== 'string')) {
            return $def['default'];
        }

        return self::decode($def['type'], (string) $stored[$key], $def['default']);
    }

    public static function on(string $feature): bool
    {
        $key = self::FEATURES[$feature] ?? ('feature_' . $feature);

        return (bool) self::get($key);
    }

    /** @return array<string,bool> */
    public static function featureMap(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $name) {
            $out[$name] = self::on($name);
        }

        return $out;
    }

    /** الإعدادات اللي بتترص جوه meetings.settings وقت الإنشاء. */
    public static function defaultMeetingSettings(): array
    {
        return array_merge((array) config('meetings.default_settings', []), [
            'mute_on_entry'      => (bool) self::get('default_mute_on_entry'),
            'camera_on_entry'    => (bool) self::get('default_camera_on_entry'),
            'allow_chat'         => (bool) self::get('default_allow_chat'),
            'allow_file_sharing' => (bool) self::get('default_allow_file_sharing'),
            'recording_enabled'  => (bool) self::get('default_recording_enabled'),
        ]);
    }

    // ------------------------------------------------------------------
    // الكتابة
    // ------------------------------------------------------------------

    /**
     * يحفظ مجموعة قيم. null = رجّع الافتراضي (يمسح الـ override).
     *
     * @param array<string,mixed> $values
     * @return array{changed:array<string,array{old:mixed,new:mixed}>, errors:array<string,string>}
     */
    public static function setMany(array $values): array
    {
        $catalog = self::catalog();
        $errors = [];
        $clean = [];

        foreach ($values as $key => $raw) {
            if (!isset($catalog[$key])) {
                $errors[$key] = 'Unknown setting.';
                continue;
            }
            if ($raw === null) {
                $clean[$key] = null;
                continue;
            }
            [$ok, $val, $err] = self::coerce($catalog[$key], $raw);
            if (!$ok) {
                $errors[$key] = $err;
                continue;
            }
            $clean[$key] = $val;
        }

        // قيود تقاطعية (على القيم النهائية بعد الدمج).
        $final = [];
        foreach (['default_duration_minutes', 'max_duration_minutes'] as $k) {
            $final[$k] = array_key_exists($k, $clean) ? ($clean[$k] ?? $catalog[$k]['default']) : self::get($k);
        }
        if ($final['default_duration_minutes'] > $final['max_duration_minutes']) {
            $errors['default_duration_minutes'] = 'Default duration cannot exceed the maximum duration.';
        }

        if ($errors) {
            return ['changed' => [], 'errors' => $errors];
        }

        $changed = [];
        foreach ($clean as $key => $val) {
            $old = self::get($key);
            if ($val === null) {
                DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', self::PREFIX . $key)->delete();
            } else {
                app(\App\Repositories\SettingRepository::class)->set(self::PREFIX . $key, self::encode($catalog[$key]['type'], $val));
            }
            self::flush();
            $new = self::get($key);
            if ($old !== $new) {
                $changed[$key] = ['old' => $old, 'new' => $new];
            }
        }
        self::flush();

        return ['changed' => $changed, 'errors' => []];
    }

    /** يمسح كل الـ overrides (يرجّع كل حاجة للافتراضي). @return int عدد الصفوف اللي اتمسحت */
    public static function resetAll(): int
    {
        $n = DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', 'like', self::PREFIX . '%')->delete();
        self::flush();

        return (int) $n;
    }

    // ------------------------------------------------------------------
    // Snapshot للوحة الأدمن
    // ------------------------------------------------------------------

    public static function snapshot(): array
    {
        $values = [];
        foreach (self::catalog() as $key => $def) {
            $values[$key] = [
                'group'      => $def['group'],
                'type'       => $def['type'],
                'value'      => self::get($key),
                'default'    => $def['default'],
                'overridden' => self::isOverridden($key),
                'min'        => $def['min'] ?? null,
                'max'        => $def['max'] ?? null,
                'max_length' => $def['max_length'] ?? null,
            ];
        }

        return [
            'groups'          => self::GROUPS,
            'values'          => $values,
            'available_roles' => array_values((array) config('roles.available', [])),
        ];
    }

    // ------------------------------------------------------------------
    // ترميز/فك ترميز/تحقق
    // ------------------------------------------------------------------

    private static function encode(string $type, $val): string
    {
        return match ($type) {
            'bool'      => $val ? '1' : '0',
            'int'       => (string) (int) $val,
            'string'    => (string) $val,
            default     => json_encode(array_values((array) $val), JSON_UNESCAPED_UNICODE),
        };
    }

    private static function decode(string $type, string $raw, $default)
    {
        switch ($type) {
            case 'bool':
                return in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true);
            case 'int':
                return is_numeric($raw) ? (int) $raw : $default;
            case 'string':
                return $raw;
            case 'list_int':
                $arr = json_decode($raw, true);

                return is_array($arr) ? array_values(array_unique(array_map('intval', $arr))) : $default;
            default:
                $arr = json_decode($raw, true);

                return is_array($arr) ? array_values(array_unique(array_map('strval', $arr))) : $default;
        }
    }

    /** @return array{0:bool,1:mixed,2:string} */
    private static function coerce(array $def, $raw): array
    {
        switch ($def['type']) {
            case 'bool':
                $b = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $b === null ? [false, null, 'Must be true or false.'] : [true, $b, ''];
            case 'int':
                if (!is_numeric($raw) || (string) (int) $raw !== (string) (int) $raw) {
                    return [false, null, 'Must be a whole number.'];
                }
                $n = (int) $raw;
                if (isset($def['min']) && $n < $def['min']) {
                    return [false, null, 'Minimum is ' . $def['min'] . '.'];
                }
                if (isset($def['max']) && $n > $def['max']) {
                    return [false, null, 'Maximum is ' . $def['max'] . '.'];
                }

                return [true, $n, ''];
            case 'string':
                $s = trim((string) $raw);
                if (isset($def['max_length']) && mb_strlen($s) > $def['max_length']) {
                    return [false, null, 'Maximum length is ' . $def['max_length'] . '.'];
                }

                return [true, $s, ''];
            case 'list_int':
                if (!is_array($raw)) {
                    return [false, null, 'Must be a list.'];
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn ($v) => $v > 0)));
                if (count($ids) > 5000) {
                    return [false, null, 'Too many entries.'];
                }

                return [true, $ids, ''];
            default: // list_str (حاليًا creator_roles بس)
                if (!is_array($raw)) {
                    return [false, null, 'Must be a list.'];
                }
                $allowed = (array) config('roles.available', []);
                $vals = array_values(array_unique(array_map('strval', $raw)));
                foreach ($vals as $v) {
                    if ($allowed && !in_array($v, $allowed, true)) {
                        return [false, null, 'Unknown role: ' . $v];
                    }
                }

                return [true, $vals, ''];
        }
    }
}
