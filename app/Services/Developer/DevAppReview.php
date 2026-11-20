<?php

namespace App\Services\Developer;

/**
 * App Review — المنطق النقي (من غير DB) عشان يتختبر لوحده.
 *
 * الفكرة: التطبيق اللي بيطلب scopes حساسة (أو أي تطبيق لو الوضع "all") ميقدرش مستخدمين تانيين غير صاحبه يوافقوا عليه
 * ولا يستخدموا توكناته إلا بعد ما الجامعة/المنصة توافق. صاحب التطبيق نفسه دايمًا يقدر يجرّب تطبيقه.
 * الموافقة بتتثبّت على scopes + redirect URIs بالظبط: أي توسّع بعد كده (scope جديد، redirect مختلف) بيبطل الموافقة تلقائيًا.
 *
 * review_mode:  off = مفيش مراجعة | sensitive (الافتراضي) = للتطبيقات اللي بتطلب scope حساس | all = كل التطبيقات.
 * الأشد بين المنصة والجامعة هو اللي بيتطبّق (المنصة سقف، الجامعة تقدر تشدّد بس).
 *
 * الحالات (state): not_required · approved · pending · rejected · needs_review.
 */
final class DevAppReview
{
    public const MODES = ['off', 'sensitive', 'all'];
    public const DEFAULT_MODE = 'sensitive';
    public const STATUSES = ['none', 'pending', 'approved', 'rejected'];

    private const RANK = ['off' => 0, 'sensitive' => 1, 'all' => 2];

    /** null/قيمة غلط = "مش متحدد". الاتنين مش متحددين → الافتراضي. غير كده الأشد منهم. */
    public static function mode(?string $platform, ?string $university): string
    {
        $p = in_array($platform, self::MODES, true) ? $platform : null;
        $u = in_array($university, self::MODES, true) ? $university : null;
        if ($p === null && $u === null) {
            return self::DEFAULT_MODE;
        }
        $best = null;
        foreach ([$p, $u] as $m) {
            if ($m !== null && ($best === null || self::RANK[$m] > self::RANK[$best])) {
                $best = $m;
            }
        }
        return $best;
    }

    /** @return string[] الـ scopes الحساسة من اللي اتدّى. */
    public static function sensitiveScopes(array $scopes): array
    {
        $all = DevScopes::all();
        return array_values(array_filter($scopes, fn ($s) => !empty($all[$s]['sensitive'])));
    }

    public static function required(string $mode, array $scopes): bool
    {
        if ($mode === 'all') {
            return true;
        }
        return $mode === 'sensitive' && self::sensitiveScopes($scopes) !== [];
    }

    public static function redirectsHash(array $redirects): string
    {
        $r = array_values(array_unique(array_map(fn ($u) => trim((string) $u), $redirects)));
        sort($r, SORT_STRING);
        return hash('sha256', json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** بصمة اللي المراجع شافه: بتتبعت مع قرار الموافقة، ولو التطبيق اتغيّر بعدها القرار بيترفض (409). */
    public static function fingerprint(array $scopes, array $redirects): string
    {
        $s = array_values(array_unique(array_map('strval', $scopes)));
        sort($s, SORT_STRING);
        return hash('sha256', json_encode([$s, self::redirectsHash($redirects)], JSON_UNESCAPED_SLASHES));
    }

    /** الموافقة المحفوظة لسه بتغطي الوضع الحالي؟ (scopes الحالية ⊆ اللي اتوافق عليها + نفس الـ redirects بالظبط). */
    public static function covers(?array $approvedScopes, ?string $approvedHash, array $scopes, array $redirects): bool
    {
        if ($approvedScopes === null || $approvedHash === null) {
            return false;
        }
        return array_diff($scopes, $approvedScopes) === [] && hash_equals($approvedHash, self::redirectsHash($redirects));
    }

    /**
     * @param array{review_status?:?string,scopes?:array,redirects?:array,review_scopes?:?array,review_redirects_hash?:?string} $app
     * @return array{state:string,required:bool,allows:bool,covered:bool}
     */
    public static function evaluate(array $app, string $mode): array
    {
        $status = in_array($app['review_status'] ?? 'none', self::STATUSES, true) ? $app['review_status'] : 'none';
        $scopes = (array) ($app['scopes'] ?? []);
        $redirects = (array) ($app['redirects'] ?? []);
        $required = self::required($mode, $scopes);
        $covered = $status === 'approved' && self::covers($app['review_scopes'] ?? null, $app['review_redirects_hash'] ?? null, $scopes, $redirects);

        if ($covered) {
            $state = 'approved';
        } elseif (!$required) {
            $state = 'not_required';
        } elseif ($status === 'pending') {
            $state = 'pending';
        } elseif ($status === 'rejected') {
            $state = 'rejected';
        } else {
            $state = 'needs_review';
        }
        return ['state' => $state, 'required' => $required, 'allows' => in_array($state, ['approved', 'not_required'], true), 'covered' => $covered];
    }
}
