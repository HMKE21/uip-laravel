<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * ميزات العميل: كتالوج (config/tenant_features.php) + حالة كل ميزة لكل عميل (جدول tenant_feature_grants في قاعدة المنصة الأم).
 * القراءة والكتابة دايمًا على قاعدة المنصة الأم حتى لو إحنا جوه عميل.
 */
class TenantFeatures
{
    public const STATUSES = ['pending', 'enabled', 'rejected', 'disabled'];

    /** @var array<int,string[]> مفاتيح الميزات المفعّلة لكل عميل (ذاكرة الـ request بس). */
    private static array $memo = [];

    /** @var array<int,array{0:string,1:string}>|null [prefix, owner] مرتبة من الأطول للأقصر */
    private static ?array $index = null;

    private static function central(): Connection
    {
        return DB::connection(TenantContext::centralConnection());
    }

    /** @return array<string,array<string,mixed>> */
    public static function catalog(): array
    {
        return (array) config('tenant_features.features', []);
    }

    public static function resetCaches(): void
    {
        self::$memo = [];
        self::$index = null;
    }

    /** يشيل بادئة api/ و v1/. null لو المسار مش تحت api. */
    public static function normalizePath(string $path): ?string
    {
        $p = trim($path, '/');
        if ($p !== 'api' && !str_starts_with($p, 'api/')) {
            return null;
        }
        $p = ltrim(substr($p, 3), '/');
        if ($p === 'v1') {
            return '';
        }
        if (str_starts_with($p, 'v1/')) {
            $p = substr($p, 3);
        }
        return $p;
    }

    /** 'core' | مفتاح ميزة | null (مش متصنّف). $p بعد normalizePath. */
    public static function classify(string $p): ?string
    {
        if (self::$index === null) {
            $rows = [];
            foreach ((array) config('tenant_features.core', []) as $pre) {
                $rows[] = [trim((string) $pre, '/'), 'core'];
            }
            foreach (self::catalog() as $key => $f) {
                foreach ((array) ($f['prefixes'] ?? []) as $pre) {
                    $rows[] = [trim((string) $pre, '/'), (string) $key];
                }
            }
            usort($rows, static fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
            self::$index = $rows;
        }
        $p = trim($p, '/');
        foreach (self::$index as [$pre, $owner]) {
            if ($p === $pre || str_starts_with($p, $pre . '/')) {
                return $owner;
            }
        }
        return null;
    }

    /** @return string[] */
    public static function enabledKeys(int $tenantId): array
    {
        if (isset(self::$memo[$tenantId])) {
            return self::$memo[$tenantId];
        }
        try {
            $keys = self::central()->table('tenant_feature_grants')->where('tenant_id', $tenantId)->where('status', 'enabled')->pluck('feature')->all();
        } catch (\Throwable $e) {
            $keys = []; // الجدول مش موجود/القاعدة وقعت: نقفل (fail closed) بدل ما نفتح
        }
        return self::$memo[$tenantId] = array_values(array_intersect($keys, array_keys(self::catalog())));
    }

    public static function isEnabled(int $tenantId, string $feature): bool
    {
        return in_array($feature, self::enabledKeys($tenantId), true);
    }

    private static function assertFeature(string $feature): void
    {
        if (!array_key_exists($feature, self::catalog())) {
            throw new \InvalidArgumentException("Unknown feature '{$feature}'.");
        }
    }

    private static function row(int $tenantId, string $feature): ?object
    {
        return self::central()->table('tenant_feature_grants')->where('tenant_id', $tenantId)->where('feature', $feature)->first();
    }

    private static function note(?string $note): ?string
    {
        return $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null;
    }

    /** قرار أدمن المنصة (أو أمر الـ CLI): enabled | rejected | disabled | pending. */
    public static function decide(int $tenantId, string $feature, string $status, ?int $adminId = null, ?string $note = null): void
    {
        self::assertFeature($feature);
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}'.");
        }
        $now = now();
        $exists = self::row($tenantId, $feature);
        $data = ['status' => $status, 'decided_by' => $adminId, 'decision_note' => self::note($note), 'decided_at' => $now, 'updated_at' => $now];
        if ($exists) {
            self::central()->table('tenant_feature_grants')->where('id', $exists->id)->update($data);
        } else {
            self::central()->table('tenant_feature_grants')->insert($data + ['tenant_id' => $tenantId, 'feature' => $feature, 'created_at' => $now]);
        }
        unset(self::$memo[$tenantId]);
    }

    /**
     * طلب من العميل. المفعّلة أو المنتظرة بالفعل بتتخطى؛ المرفوضة/المقفولة بتتحول تاني لـ pending.
     * @param string[] $features
     * @return array{requested:string[],skipped:string[]}
     */
    public static function request(int $tenantId, array $features, ?int $userId, ?string $note = null): array
    {
        $features = array_values(array_unique(array_map('strval', $features)));
        foreach ($features as $f) {
            self::assertFeature($f); // نتحقق من الكل الأول: يا الكل يتسجّل يا ولا واحدة
        }
        $requested = [];
        $skipped = [];
        foreach ($features as $f) {
            $cur = self::row($tenantId, $f);
            if ($cur && in_array($cur->status, ['enabled', 'pending'], true)) {
                $skipped[] = $f;
                continue;
            }
            $now = now();
            $data = [
                'status' => 'pending', 'requested_by' => $userId, 'request_note' => self::note($note),
                'requested_at' => $now, 'decided_by' => null, 'decision_note' => null, 'decided_at' => null, 'updated_at' => $now,
            ];
            if ($cur) {
                self::central()->table('tenant_feature_grants')->where('id', $cur->id)->update($data);
            } else {
                self::central()->table('tenant_feature_grants')->insert($data + ['tenant_id' => $tenantId, 'feature' => $f, 'created_at' => $now]);
            }
            $requested[] = $f;
        }
        unset(self::$memo[$tenantId]);
        return ['requested' => $requested, 'skipped' => $skipped];
    }

    /** العميل يلغي طلب لسه pending. @return bool اتلغى فعلًا */
    public static function cancelRequest(int $tenantId, string $feature): bool
    {
        self::assertFeature($feature);
        $n = self::central()->table('tenant_feature_grants')->where('tenant_id', $tenantId)->where('feature', $feature)->where('status', 'pending')->delete();
        unset(self::$memo[$tenantId]);
        return $n > 0;
    }

    /** حالة كل ميزات الكتالوج لعميل (للوحة العميل وللوحة الأدمن). */
    public static function stateFor(int $tenantId): array
    {
        $rows = self::central()->table('tenant_feature_grants')->where('tenant_id', $tenantId)->get()->keyBy('feature');
        $out = [];
        foreach (self::catalog() as $key => $f) {
            $r = $rows->get($key);
            $out[] = [
                'feature' => $key,
                'label_ar' => $f['label_ar'] ?? $key,
                'label_en' => $f['label_en'] ?? $key,
                'status' => $r->status ?? 'none',          // none | pending | enabled | rejected | disabled
                'request_note' => $r->request_note ?? null,
                'decision_note' => $r->decision_note ?? null,
                'requested_at' => $r->requested_at ?? null,
                'decided_at' => $r->decided_at ?? null,
            ];
        }
        return $out;
    }

    /** طلبات كل العملاء (للوحة أدمن المنصة). */
    public static function listRequests(?string $status = 'pending'): array
    {
        $q = self::central()->table('tenant_feature_grants as g')
            ->join('tenants as t', 't.id', '=', 'g.tenant_id')
            ->select('g.id', 'g.tenant_id', 't.name as tenant_name', 't.slug as tenant_slug', 'g.feature', 'g.status', 'g.request_note', 'g.requested_at', 'g.requested_by', 'g.decision_note', 'g.decided_at')
            ->orderByDesc('g.requested_at')->orderByDesc('g.id');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $q->where('g.status', $status);
        }
        $out = [];
        foreach ($q->limit(500)->get() as $r) {
            $arr = (array) $r;
            $arr['label_ar'] = self::catalog()[$r->feature]['label_ar'] ?? $r->feature;
            $arr['label_en'] = self::catalog()[$r->feature]['label_en'] ?? $r->feature;
            $out[] = $arr;
        }
        return $out;
    }
}
