<?php

namespace App\Services;

use App\Models\DriveUserLimit;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;

/**
 * Admin → My PC Control: كتالوج واحد لكل إعداد في "جهازي" يقدر الأدمن يغيّره وقت التشغيل (من غير .env ولا deploy).
 * القيمة بتتخزن في جدول `settings` (scope=global) بمفتاح `drive_<key>`؛ لو مفيش override بنرجع للقيمة الأصلية
 * (config/drive.php => .env). نفس فكرة MeetingAdminSettings.
 *
 * التطبيق: DriveService/DriveShareService بيندهوا applyToConfig() في الـ constructor، فكل كود "جهازي" القديم اللي بيقرا
 * config('drive.*') بياخد قيمة الأدمن تلقائيًا من غير تعديل.
 *
 * الحصص (الأولوية): تخصيص المستخدم (drive_user_limits.quota_mb) > حصة الدور (role_quota_mb) > الحصة العامة (quota_mb).
 * 0 = بلا حد. لو المستخدم عنده أكتر من دور (joker) بياخد أكبر حصة دور.
 *
 * الأنواع: bool | int | string | list_role | list_ext | map_int (role => MB).
 */
class DriveAdminSettings
{
    public const PREFIX = 'drive_';

    public const GROUPS = ['system', 'access', 'limits', 'versions', 'features', 'uploads', 'sharing', 'admin'];

    public const STATUSES = ['active', 'readonly', 'blocked'];

    /** @var array<string,string>|null */
    private static ?array $cache = null;
    private static int $cacheAt = 0;
    /** @var array<string,mixed>|null قيم config/drive.php الأصلية (قبل ما نطبّق override الأدمن). */
    private static ?array $base = null;
    /** @var array<int,array> */
    private static array $limitMemo = [];
    private static int $limitMemoAt = 0;

    private static function base(string $k)
    {
        if (self::$base === null) {
            self::$base = [];
            foreach (['quota_mb', 'max_file_mb', 'max_zip_mb', 'max_nodes', 'max_depth', 'max_text_edit_kb', 'trash_days', 'max_versions', 'version_max_mb', 'versions_mb'] as $key) {
                self::$base[$key] = (int) config('drive.' . $key);
            }
            self::$base['roles'] = array_values((array) config('drive.roles', []));
        }

        return self::$base[$k];
    }

    /**
     * @return array<string, array{group:string,type:string,default:mixed,min?:int,max?:int,max_length?:int}>
     */
    public static function catalog(): array
    {
        return [
            // -- النظام ----------------------------------------------------
            'feature_drive'       => ['group' => 'system', 'type' => 'bool', 'default' => true],
            'maintenance_message' => ['group' => 'system', 'type' => 'string', 'default' => '', 'max_length' => 300],

            // -- مين يستخدمه -----------------------------------------------
            'allowed_roles' => ['group' => 'access', 'type' => 'list_role', 'default' => self::base('roles')],

            // -- الحصة والحدود ---------------------------------------------
            'quota_mb'         => ['group' => 'limits', 'type' => 'int', 'default' => self::base('quota_mb'), 'min' => 1, 'max' => 10000000],
            'role_quota_mb'    => ['group' => 'limits', 'type' => 'map_int', 'default' => []],
            'max_file_mb'      => ['group' => 'limits', 'type' => 'int', 'default' => self::base('max_file_mb'), 'min' => 1, 'max' => 102400],
            'max_zip_mb'       => ['group' => 'limits', 'type' => 'int', 'default' => self::base('max_zip_mb'), 'min' => 1, 'max' => 102400],
            'max_nodes'        => ['group' => 'limits', 'type' => 'int', 'default' => self::base('max_nodes'), 'min' => 100, 'max' => 5000000],
            'max_depth'        => ['group' => 'limits', 'type' => 'int', 'default' => self::base('max_depth'), 'min' => 2, 'max' => 200],
            'max_text_edit_kb' => ['group' => 'limits', 'type' => 'int', 'default' => self::base('max_text_edit_kb'), 'min' => 16, 'max' => 102400],
            'trash_days'       => ['group' => 'limits', 'type' => 'int', 'default' => self::base('trash_days'), 'min' => 1, 'max' => 3650],

            // -- سجل الإصدارات ---------------------------------------------
            'max_versions'   => ['group' => 'versions', 'type' => 'int', 'default' => self::base('max_versions'), 'min' => 0, 'max' => 500],
            'version_max_mb' => ['group' => 'versions', 'type' => 'int', 'default' => self::base('version_max_mb'), 'min' => 1, 'max' => 10240],
            'versions_mb'    => ['group' => 'versions', 'type' => 'int', 'default' => self::base('versions_mb'), 'min' => 1, 'max' => 1000000],

            // -- مفاتيح الميزات (kill switches) ----------------------------
            'feature_uploads'  => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_sharing'  => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_archive'  => ['group' => 'features', 'type' => 'bool', 'default' => true],
            'feature_terminal' => ['group' => 'features', 'type' => 'bool', 'default' => true],

            // -- الرفع -----------------------------------------------------
            'blocked_extensions' => ['group' => 'uploads', 'type' => 'list_ext', 'default' => []],

            // -- المشاركة --------------------------------------------------
            'share_max_active_per_user' => ['group' => 'sharing', 'type' => 'int', 'default' => 300, 'min' => 1, 'max' => 5000],
            'share_max_expiry_days'     => ['group' => 'sharing', 'type' => 'int', 'default' => 365, 'min' => 1, 'max' => 3650],
            'share_allow_never'         => ['group' => 'sharing', 'type' => 'bool', 'default' => true],

            // -- صلاحيات الأدمن نفسه ---------------------------------------
            'admin_can_download' => ['group' => 'admin', 'type' => 'bool', 'default' => true],
            'admin_can_delete'   => ['group' => 'admin', 'type' => 'bool', 'default' => true],
        ];
    }

    // ------------------------------------------------------------------
    // القراءة
    // ------------------------------------------------------------------

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
            // جدول settings مش موجود لسه -> الافتراضي.
        }

        self::$cache = $out;
        self::$cacheAt = time();

        return $out;
    }

    public static function flush(): void
    {
        self::$cache = null;
        self::$cacheAt = 0;
        self::$limitMemo = [];
        self::$limitMemoAt = 0;
    }

    public static function isOverridden(string $key): bool
    {
        $stored = self::stored();

        return isset($stored[$key]) && $stored[$key] !== '';
    }

    /** @return bool|int|string|array */
    public static function get(string $key)
    {
        $def = self::catalog()[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Unknown My PC setting: ' . $key);
        }

        $stored = self::stored();
        if (!isset($stored[$key]) || ($stored[$key] === '' && $def['type'] !== 'string')) {
            return $def['default'];
        }

        return self::decode($def['type'], (string) $stored[$key], $def['default']);
    }

    public static function on(string $feature): bool
    {
        return (bool) self::get('feature_' . $feature);
    }

    /** @return array<string,bool> */
    public static function featureMap(): array
    {
        $out = [];
        foreach (['drive', 'uploads', 'sharing', 'archive', 'terminal'] as $f) {
            $out[$f] = self::on($f);
        }

        return $out;
    }

    /** الأدوار المسموح لها بـ"جهازي" دلوقتي (override الأدمن، وإلا config/.env). */
    public static function allowedRoles(): array
    {
        $r = (array) self::get('allowed_roles');

        return $r ?: (array) self::base('roles');
    }

    /** يطبّق قيم الأدمن على config('drive.*') عشان الكود القديم يلتقطها. آمن لو الجدول مش موجود. */
    public static function applyToConfig(): void
    {
        try {
            $set = [];
            foreach (['quota_mb', 'max_file_mb', 'max_zip_mb', 'max_nodes', 'max_depth', 'max_text_edit_kb', 'trash_days', 'max_versions', 'version_max_mb', 'versions_mb'] as $k) {
                $set['drive.' . $k] = (int) self::get($k);
            }
            $set['drive.roles'] = self::allowedRoles();
            config($set);
        } catch (\Throwable $e) {
            // نسيب القيم الأصلية.
        }
    }

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
            'disk'            => ['name' => (string) config('drive.disk'), 'driver' => (string) config('filesystems.disks.' . config('drive.disk') . '.driver')],
        ];
    }

    // ------------------------------------------------------------------
    // الحصص والحالة لكل مستخدم
    // ------------------------------------------------------------------

    /**
     * يحمّل تخصيص + أدوار مجموعة مستخدمين مرة واحدة (للقوائم) عشان مفيش N+1.
     *
     * @param int[] $uids
     */
    public static function prime(array $uids): void
    {
        if (self::$limitMemo && (time() - self::$limitMemoAt) >= 3) { // workers طويلة: متفضلش على قيمة قديمة
            self::$limitMemo = [];
        }
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn ($i) => $i > 0 && !isset(self::$limitMemo[$i]))));
        if (!$uids) {
            return;
        }
        $limits = [];
        $roles = [];
        try {
            $limits = DriveUserLimit::query()->whereIn('user_id', $uids)->get()->keyBy('user_id')->all();
            foreach (DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->whereIn('user_roles.user_id', $uids)
                ->get(['user_roles.user_id', 'roles.slug']) as $r) {
                $roles[(int) $r->user_id][] = (string) $r->slug;
            }
        } catch (\Throwable $e) {
            // الجدول لسه معمولهوش migrate -> بدون تخصيصات.
        }
        self::$limitMemoAt = self::$limitMemoAt ?: time();
        foreach ($uids as $uid) {
            self::$limitMemo[$uid] = self::resolve($limits[$uid] ?? null, $roles[$uid] ?? []);
        }
    }

    /** @return array{quota_mb:int,quota_source:string,max_file_mb:int,status:string,reason:?string,note:?string,sharing_blocked:bool,uploads_blocked:bool,has_override:bool,override:array} */
    public static function limitsFor(int $uid): array
    {
        if (!isset(self::$limitMemo[$uid])) {
            self::prime([$uid]);
        }

        return self::$limitMemo[$uid] ?? self::resolve(null, []);
    }

    private static function resolve(?DriveUserLimit $row, array $roleSlugs): array
    {
        $quota = (int) self::get('quota_mb');
        $source = 'global';
        $roleMap = (array) self::get('role_quota_mb');
        $best = null;
        foreach ($roleSlugs as $slug) {
            if (isset($roleMap[$slug])) {
                $v = (int) $roleMap[$slug];
                $best = ($best === null) ? $v : (($best === 0 || $v === 0) ? 0 : max($best, $v));
            }
        }
        if ($best !== null) {
            $quota = $best;
            $source = 'role';
        }
        if ($row && $row->quota_mb !== null) {
            $quota = (int) $row->quota_mb;
            $source = 'user';
        }
        $maxFile = ($row && $row->max_file_mb !== null) ? (int) $row->max_file_mb : (int) self::get('max_file_mb');

        return [
            'quota_mb'        => $quota,
            'quota_source'    => $source,
            'max_file_mb'     => $maxFile,
            'status'          => $row && in_array($row->status, self::STATUSES, true) ? (string) $row->status : 'active',
            'reason'          => $row?->reason,
            'note'            => $row?->note,
            'sharing_blocked' => (bool) ($row?->sharing_blocked),
            'uploads_blocked' => (bool) ($row?->uploads_blocked),
            'has_override'    => $row !== null,
            'override'        => $row ? [
                'quota_mb' => $row->quota_mb, 'max_file_mb' => $row->max_file_mb,
            ] : ['quota_mb' => null, 'max_file_mb' => null],
        ];
    }

    public static function quotaBytes(int $uid): int
    {
        $mb = self::limitsFor($uid)['quota_mb'];

        return $mb <= 0 ? PHP_INT_MAX : $mb * 1048576;
    }

    public static function maxFileBytes(int $uid): int
    {
        return max(1, self::limitsFor($uid)['max_file_mb']) * 1048576;
    }

    /**
     * فحص الدخول لـ"جهازي". null = مسموح. غير كده [رسالة, status code, سبب].
     * $write = الطلب بيغيّر حاجة (POST/PUT/PATCH/DELETE): الحساب القراءة-فقط بيتمنع.
     *
     * @return array{0:string,1:int,2:string}|null
     */
    public static function denial(int $uid, string $role, bool $write = false): ?array
    {
        if (!self::get('feature_drive')) {
            $m = trim((string) self::get('maintenance_message'));

            return [$m !== '' ? $m : 'My PC is temporarily unavailable.', 503, 'disabled'];
        }
        if (!in_array($role, self::allowedRoles(), true)) {
            return ['My PC is not available for your account type.', 403, 'role'];
        }
        $l = self::limitsFor($uid);
        if ($l['status'] === 'blocked') {
            return [trim((string) $l['reason']) !== '' ? (string) $l['reason'] : 'Your access to My PC has been disabled by an administrator.', 403, 'blocked'];
        }
        if ($write && $l['status'] === 'readonly') {
            return [trim((string) $l['reason']) !== '' ? (string) $l['reason'] : 'My PC is read-only for your account.', 403, 'readonly'];
        }

        return null;
    }

    public static function assertUploadsAllowed(int $uid): void
    {
        if (!self::on('uploads')) {
            throw new DriveException('Uploading and saving files is turned off by the administrator right now.', 403);
        }
        if (self::limitsFor($uid)['uploads_blocked']) {
            throw new DriveException('Uploading files is turned off for your account.', 403);
        }
    }

    public static function assertSharingAllowed(int $uid): void
    {
        if (!self::on('sharing')) {
            throw new DriveException('Sharing links is turned off by the administrator right now.', 403);
        }
        if (self::limitsFor($uid)['sharing_blocked']) {
            throw new DriveException('Sharing links is turned off for your account.', 403);
        }
    }

    public static function assertNameAllowed(string $name): void
    {
        $blocked = (array) self::get('blocked_extensions');
        if (!$blocked) {
            return;
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, $blocked, true)) {
            throw new DriveException('Files of this type are not allowed (.' . $ext . ').', 422);
        }
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

        // قيد تقاطعي: أكبر ملف لازم يتساع في الحصة.
        $final = [];
        foreach (['quota_mb', 'max_file_mb'] as $k) {
            $final[$k] = array_key_exists($k, $clean) ? ($clean[$k] ?? $catalog[$k]['default']) : self::get($k);
        }
        if ($final['max_file_mb'] > $final['quota_mb']) {
            $errors['max_file_mb'] = 'Maximum file size cannot exceed the storage quota.';
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

    /** يمسح كل الـ overrides. @return int عدد الصفوف */
    public static function resetAll(): int
    {
        $n = DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', 'like', self::PREFIX . '%')->delete();
        self::flush();

        return (int) $n;
    }

    // ------------------------------------------------------------------
    // ترميز/فك ترميز/تحقق
    // ------------------------------------------------------------------

    private static function encode(string $type, $val): string
    {
        return match ($type) {
            'bool'    => $val ? '1' : '0',
            'int'     => (string) (int) $val,
            'string'  => (string) $val,
            'map_int' => json_encode((object) (array) $val, JSON_UNESCAPED_UNICODE),
            default   => json_encode(array_values((array) $val), JSON_UNESCAPED_UNICODE),
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
            case 'map_int':
                $arr = json_decode($raw, true);

                return is_array($arr) ? array_map('intval', $arr) : $default;
            default: // list_role | list_ext
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
            case 'map_int':
                if (!is_array($raw)) {
                    return [false, null, 'Must be a map of role => MB.'];
                }
                $allowed = (array) config('roles.available', []);
                $out = [];
                foreach ($raw as $role => $mb) {
                    $role = (string) $role;
                    if ($allowed && !in_array($role, $allowed, true)) {
                        return [false, null, 'Unknown role: ' . $role];
                    }
                    if ($mb === null || $mb === '') {
                        continue; // فاضي = من غير حصة خاصة بالدور
                    }
                    if (!is_numeric($mb) || (int) $mb < 0 || (int) $mb > 10000000) {
                        return [false, null, 'Quota for ' . $role . ' must be 0 (unlimited) to 10000000 MB.'];
                    }
                    $out[$role] = (int) $mb;
                }

                return [true, $out, ''];
            case 'list_ext':
                if (!is_array($raw)) {
                    return [false, null, 'Must be a list.'];
                }
                $out = [];
                foreach ($raw as $e) {
                    $e = strtolower(ltrim(trim((string) $e), '.'));
                    if ($e === '') {
                        continue;
                    }
                    if (!preg_match('/^[a-z0-9_+-]{1,16}$/', $e)) {
                        return [false, null, 'Invalid extension: ' . $e];
                    }
                    $out[$e] = true;
                }
                if (count($out) > 200) {
                    return [false, null, 'Too many entries.'];
                }

                return [true, array_keys($out), ''];
            default: // list_role
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
