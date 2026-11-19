<?php

namespace App\Services\Developer;

/** كتالوج الـ Scopes (config/developer.php) + أدوات التحقق. */
class DevScopes
{
    public static function all(): array
    {
        return (array) config('developer.scopes', []);
    }

    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** يقبل string "a b" أو array، يرجّع مصفوفة نظيفة فريدة. */
    public static function parse(string|array|null $in): array
    {
        if (is_string($in)) {
            $in = preg_split('/[\s,]+/', trim($in)) ?: [];
        }
        return array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), (array) $in), fn ($s) => $s !== '')));
    }

    /** @return string[] الأسماء غير المعروفة */
    public static function unknown(array $scopes): array
    {
        return array_values(array_diff($scopes, self::names()));
    }

    public static function has(array $granted, string $scope): bool
    {
        return in_array($scope, $granted, true);
    }

    public static function describe(array $scopes, string $lang = 'ar'): array
    {
        $all = self::all();
        $out = [];
        foreach ($scopes as $s) {
            if (isset($all[$s])) {
                $out[] = ['scope' => $s, 'label' => $all[$s][$lang] ?? $all[$s]['en'], 'sensitive' => (bool) $all[$s]['sensitive']];
            }
        }
        return $out;
    }

    public static function catalog(): array
    {
        $out = [];
        foreach (self::all() as $k => $v) {
            $out[] = ['scope' => $k, 'sensitive' => (bool) $v['sensitive'], 'en' => $v['en'], 'ar' => $v['ar']];
        }
        return $out;
    }
}
