<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Storage;

/**
 * عزل الملفات لكل عميل (الخطوة 5). بيتنادى من TenantContext::activate()/deactivate().
 *
 * 1) مسارات الرفع (config upload.paths.*): `uploads/avatars` => `uploads/avatars/t5`. كل الكود بيبني مساراته من الـ config،
 *    فالمسار المتخزّن في قاعدة العميل نفسه فيه الـ segment (`uploads/avatars/t5/<file>`) — يعني:
 *      - الملفات العامة (avatars/logos…) رابطها شغّال من غير هيدر (المتصفح مبيبعتش X-Tenant-Domain على <img>).
 *      - نسخة S3 (UploadMirror) مفتاحها فيه t5 تلقائيًا.
 *      - فحوص "الملف جوه مجلد الفئة" (realpath) لسه بتعدّي لأن المسار بيبدأ بنفس بادئة الفئة.
 * 2) الـ disks (local/public/drive/...): local => `<root>/tenants/5`، s3 => `root = tenants/5`، وurl الـ public disk بنفس الشكل.
 *    UploadMirror بيستخدم إعداد الـ disk الأصلي (original()) عشان مفتاحه فيه الـ segment أصلًا ومسار الـ fallback العام بيشتغل من غير tenant.
 */
class TenantStorage
{
    /** @var array<string,string>|null upload.paths الأصلية */
    private static ?array $paths = null;
    /** @var array<string,array<string,mixed>>|null filesystems.disks الأصلية */
    private static ?array $disks = null;

    public static function reset(): void
    {
        self::$paths = null;
        self::$disks = null;
    }

    /** إعداد disk كما هو في config/filesystems.php (قبل أي عزل). */
    public static function original(string $disk): ?array
    {
        self::snapshot();
        $cfg = self::$disks[$disk] ?? null;
        return is_array($cfg) ? $cfg : null;
    }

    private static function snapshot(): void
    {
        if (self::$paths === null) {
            self::$paths = array_map('strval', (array) config('upload.paths', []));
        }
        if (self::$disks === null) {
            self::$disks = (array) config('filesystems.disks', []);
        }
    }

    /** segment الملفات للعميل الحالي: 't5' أو '' على المنصة. */
    public static function segment(): string
    {
        $id = TenantContext::id();
        return $id ? 't' . $id : '';
    }

    /** مسار تحت storage/ معزول: path('app/chunked') => storage/app/chunked/t5 (على المنصة من غير segment). */
    public static function storagePath(string $relative): string
    {
        $base = storage_path(trim($relative, '/\\'));
        $seg = self::segment();
        return $seg === '' ? $base : $base . DIRECTORY_SEPARATOR . $seg;
    }

    public static function apply(?int $id): void
    {
        self::snapshot();

        // 1) مسارات الرفع
        $paths = [];
        foreach (self::$paths as $key => $path) {
            $paths[$key] = $id === null ? $path : rtrim($path, '/') . '/t' . $id;
        }
        config(['upload.paths' => $paths]);

        // 2) الـ disks
        $names = [];
        foreach (self::$disks as $name => $cfg) {
            $driver = $cfg['driver'] ?? null;
            $new = $cfg;
            if ($id !== null) {
                if ($driver === 'local') {
                    $new['root'] = rtrim((string) ($cfg['root'] ?? storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $id;
                    if (!empty($cfg['url'])) {
                        $new['url'] = rtrim((string) $cfg['url'], '/') . '/tenants/' . $id;
                    }
                } elseif ($driver === 's3') {
                    $root = trim((string) ($cfg['root'] ?? ''), '/');
                    $new['root'] = ($root !== '' ? $root . '/' : '') . 'tenants/' . $id;
                }
            }
            config(["filesystems.disks.{$name}" => $new]);
            $names[] = $name;
        }
        Storage::forgetDisk($names);
    }
}
