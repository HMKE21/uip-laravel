<?php

namespace App\Support;

/**
 * قاموس + مطابِق نصوص لفحص المحتوى المحظور (عربي + إنجليزي + عربيزي).
 *
 * القوائم محفوظة مشفّرة (base64 + JSON) عمدًا، فمفيش أي كلمة محظورة بتظهر كنص صريح في الكود.
 * بتتفك وقت التشغيل بس جوه words().
 *
 * أسلوب المطابقة:
 *  - مقاومة التحايل: فواصل/مسافات بين الحروف، استبدال أرقام/رموز بحروف، تكرار الحروف، حروف كيريلية شبيهة،
 *    تشكيل وتطويل عربي. أي صيغة إضافية الأدمن يضيفها من extra.
 *  - المطابقة بالكلمة الكاملة (token) مش substring، إلا للمصطلحات الطويلة الواضحة، لتفادي الإيجابيات الكاذبة.
 *  - العربي: بنشيل السوابق وبنطابق اللواحق بس على الكلمات الطويلة، عشان الأسماء العادية متتحجبش.
 *
 * طبقتين:
 *  tier 1 = ممنوع في أي نص في المنصة.
 *  tier 2 = أخف — بتتحجب بس في السياقات الصارمة (strict): بحث/عناوين الـ GIF والملصقات والأسماء والـ usernames،
 *           مش في الرسائل/المنشورات العادية، عشان النصوص الأكاديمية تفضل شغالة.
 *
 * لتعديل القوائم: base64_encode(json_encode([...], JSON_UNESCAPED_UNICODE)) ثم حطّ الناتج في DATA.
 */
class ContentLexicon
{
    // ───────────── data (مشفّرة) ─────────────
    /** @var array<string,string> base64(JSON) */
    private const DATA = [
        'T1_TOKENS' =>
            'WyJwb3JuIiwicG9ybm8iLCJwb3Jub3MiLCJwb3Jub2dyYXBoeSIsInBvcm5vZ3JhcGhpYyIsInBv' .
            'cm5zdGFyIiwicG9ybnN0YXJzIiwieHh4IiwieG54eCIsInh2aWRlb3MiLCJ4dmlkZW8iLCJ4aGFt' .
            'c3RlciIsInBvcm5odWIiLCJyZWR0dWJlIiwieW91cG9ybiIsImJyYXp6ZXJzIiwiaGVudGFpIiwi' .
            'Ymxvd2pvYiIsImJsb3dqb2JzIiwiaGFuZGpvYiIsInJpbWpvYiIsImN1bXNob3QiLCJnYW5nYmFu' .
            'ZyIsImJ1a2tha2UiLCJkZWVwdGhyb2F0IiwiY3VubmlsaW5ndXMiLCJmZWxsYXRpbyIsImRpbGRv' .
            'IiwicHVzc3kiLCJudWRlcyIsImphaWxiYWl0IiwicHRoYyIsImxvbGljb24iLCJzaG90YWNvbiIs' .
            'InNudWZmIiwiYmVzdGdvcmUiLCJjYW1naXJsIiwiY2FtZ2lybHMiLCJzZXhjYW0iLCJzZXhjYW1z' .
            'Iiwic2V4dmlkZW8iLCJzZXh2aWRlb3MiLCJzZXh0YXBlIiwibnNmdyIsInNwYW5rYmFuZyIsInR1' .
            'YmU4IiwieHR1YmUiLCJ4eG54IiwiY2hhdHVyYmF0ZSIsInN0cmlwY2hhdCIsImJvbmdhY2FtcyIs' .
            'ImxpdmVqYXNtaW4iLCJtb3RoZXJsZXNzIiwiZXBvcm5lciIsIm5oZW50YWkiLCJydWxlMzQiLCJm' .
            'YXBlbGxvIiwiZXJvbWUiLCJ0aG90aHViIiwiaHFwb3JuZXIiLCJwb3JudHJleCIsInh4eHZpZGVv' .
            'cyIsInBvcm5oYXQiLCJoY2xpcHMiLCJ0eHh4Iiwic2hhcm1vdGEiLCJzaGFybW91dGEiLCJzaGFy' .
            'bW9vdGEiLCJzaGFybW9vdCIsInNoYXJtb3V0IiwibWV0bmFrIiwibWV0bmFrYSIsIm1pdG5hayIs' .
            'Im1pdG5ha2EiLCJrb3NvbWFrIiwia29zb21layIsImtvc21hayJd',
        'T1_SUBSTR' =>
            'WyJwb3JuIiwieG54eCIsInh2aWRlbyIsInhoYW1zdGVyIiwicmVkdHViZSIsInlvdXBvcm4iLCJi' .
            'cmF6emVycyIsImhlbnRhaSIsImJsb3dqb2IiLCJidWtrYWtlIiwiZ2FuZ2JhbmciLCJjdW1zaG90' .
            'IiwiY2FtZ2lybCIsImJlc3Rnb3JlIiwic3BhbmtiYW5nIiwiY2hhdHVyYmF0ZSIsInN0cmlwY2hh' .
            'dCIsImJvbmdhY2FtcyIsImxpdmVqYXNtaW4iLCJuaGVudGFpIl0=',
        'T1_PHRASES' =>
            'WyJzZXggdmlkZW8iLCJzZXggdmlkZW9zIiwic2V4IHRhcGUiLCJzZXggdGFwZXMiLCJzZXggY2hh' .
            'dCIsInNleCBjYW0iLCJzZXggY2FtcyIsInNleCBzdG9yeSIsInNleCBzdG9yaWVzIiwic2V4IHBp' .
            'YyIsInNleCBwaWNzIiwic2V4IHBob3RvcyIsImFkdWx0IHZpZGVvIiwiYWR1bHQgdmlkZW9zIiwi' .
            'YWR1bHQgZmlsbSIsImFkdWx0IGZpbG1zIiwiYWR1bHQgbW92aWUiLCJhZHVsdCBtb3ZpZXMiLCJu' .
            'dWRlIHBob3RvIiwibnVkZSBwaG90b3MiLCJudWRlIHBpYyIsIm51ZGUgcGljcyIsIm51ZGUgdmlk' .
            'ZW8iLCJudWRlIHZpZGVvcyIsIm5ha2VkIGdpcmwiLCJuYWtlZCBnaXJscyIsIm5ha2VkIHdvbWFu' .
            'IiwibmFrZWQgd29tZW4iLCJuYWtlZCBtYW4iLCJuYWtlZCBtZW4iLCJob3Qgc2V4IiwibGl2ZSBz' .
            'ZXgiLCJmcmVlIHNleCIsImZyZWUgcG9ybiIsImxlYWtlZCBudWRlcyIsIm9ubHlmYW5zIGxlYWsi' .
            'LCJvbmx5ZmFucyBsZWFrcyIsImJsdWUgZmlsbSIsImJsdWUgZmlsbXMiLCJjaGlsZCBwb3JuIiwi' .
            'Z29yZSB2aWRlbyIsImdvcmUgdmlkZW9zIiwiYmVoZWFkaW5nIHZpZGVvIiwiYmVoZWFkaW5nIHZp' .
            'ZGVvcyIsImV4ZWN1dGlvbiB2aWRlbyIsInJlYWwgZGVhdGggdmlkZW8iLCJzbnVmZiBmaWxtIiwi' .
            'cmFwZSBwb3JuIiwia29zIG9tYWsiLCJrb3Mgb21tYWsiLCJuaWsgb21hayIsIm5payBvbW1hayIs' .
            'ImlibiBlbCBzaGFybW90YSIsItmG2YrZgyDYp9mF2YMiLCLZhtmK2YMg2KfYrtiq2YMiLCLYp9io' .
            '2YYg2KfZhNmF2KrZhtin2YPZhyIsItin2KjZhiDYp9mE2LTYsdmF2YjYt9mHIiwi2KfYqNmGINin' .
            '2YTYudix2LUiLCLZg9izINin2YXZgyIsItmD2LMg2KfYrtiq2YMiLCLZg9izINin2YUiLCLYp9mB' .
            '2YTYp9mFINiz2YPYsyIsItmB2YrYr9mK2Ygg2LPZg9izIiwi2YHZitiv2YrZiNmH2KfYqiDYs9mD' .
            '2LMiLCLYtdmI2LEg2LPZg9izIiwi2YLYtdi1INiz2YPYsyIsItmC2LXYtSDYrNmG2LPZitmHIiwi' .
            '2YLYtdi1INmG2YrZgyIsItmB2YrYr9mK2YjZh9in2Kog2KfYqNin2K3ZitmHIiwi2YHZitiv2YrZ' .
            'iCDYp9io2KfYrdmKIiwi2YXZiNin2YLYuSDYp9io2KfYrdmK2YciLCLZhdmC2KfYt9i5INin2KjY' .
            'p9it2YrZhyIsItmF2YLYp9i32Lkg2LPZg9izIiwi2LPZg9izINmF2LXYsdmKIiwi2LPZg9izINi5' .
            '2LHYqNmKIiwi2YbZitmDINmF2LXYsdmKIiwi2KfZgdmE2KfZhSDYp9io2KfYrdmK2YciLCLYp9mB' .
            '2YTYp9mFINmE2YTZg9io2KfYsSIsItio2YjYsdmGINmH2KfYqCIsIti12YjYsSDYudin2LHZitmH' .
            'Iiwi2YHZitiv2YrZiCDYudin2LHZiiIsItmB2YrYr9mK2YjZh9in2Kog2LnYp9ix2YrZhyIsItio' .
            '2YbYp9iqINi52KfYsdmK2KfYqiIsItmF2YXYp9ix2LPZhyDYp9mE2KzZhtizIiwi2KzYsdmI2Kgg' .
            '2LPZg9izIl0=',
        'T1_AR' =>
            'WyLYs9mD2LMiLCLYs9mD2LPZiiIsItio2YjYsdmG2YgiLCLYp9io2KfYrdmKIiwi2KfYqNin2K3Z' .
            'itmHIiwi2KfYqNin2K3Zitin2KoiLCLZhdiq2YbYp9mDIiwi2YXYqtmG2KfZg9mHIiwi2YXZhtmK' .
            '2YjZgyIsItmF2YbZitmI2YPZhyIsIti02LHZhdmI2LciLCLYtNix2YXZiNi32YciLCLYtNix2KfZ' .
            'hdmK2LciLCLYudix2LUiLCLZhdi52LHYtSIsItmF2LnYsdi12YciLCLZgtit2KjZhyIsItmC2K3Y' .
            'p9ioIiwi2YPYsyIsItmD2LPZhSIsItmD2LPZhdmDIiwi2YPYs9iu2KoiLCLZg9iz2YfYpyIsIti3' .
            '2YrYsiIsIti32YrYstmH2KciLCLYqNi42LEiLCLZhdmF2K3ZiNmGIiwi2YXZhdit2YjZhtmHIiwi' .
            '2YrZhtmK2YMiLCLZh9mG2YrZgyIsItmH2YbZitmD2YMiLCLYp9mG2YrZgyIsItin2YbZitmD2YMi' .
            'LCLZhtmK2YPZgyIsItmG2YrZg9iq2YMiLCLZhtmK2YPYqtmH2KciLCLZhtmK2YPZh9inIiwi2YbZ' .
            'itmD2YbZiiIsItio2YbZitmDIiwi2YbZiNiv2LIiLCLYqNmI2LHZhtmH2KfYqCIsItin2YPYs9mB' .
            '2YrYr9mK2YgiLCLYp9mD2LPZgdmK2K/ZitmI2LIiXQ==',
        'T2_TOKENS' =>
            'WyJzZXgiLCJzZXh5Iiwic2V4aWVyIiwic2V4aWVzdCIsInNleHRpbmciLCJzZXh0Iiwic2V4dWFs' .
            'IiwibnVkZSIsIm5ha2VkIiwibnVkaXR5IiwiZXJvdGljIiwiZXJvdGljYSIsImZldGlzaCIsImtp' .
            'bmt5IiwiaG9ybnkiLCJib29iIiwiYm9vYnMiLCJib29iaWVzIiwidGl0IiwidGl0cyIsInRpdHR5' .
            'IiwidGl0dGllcyIsImFzcyIsImFzc2VzIiwiYnV0dCIsImJvb3R5IiwidHdlcmsiLCJ0d2Vya2lu' .
            'ZyIsInN0cmlwcGVyIiwic3RyaXBwZXJzIiwic3RyaXB0ZWFzZSIsImxpbmdlcmllIiwidGhvbmci' .
            'LCJ0b3BsZXNzIiwib25seWZhbnMiLCJjdW0iLCJjdW1taW5nIiwib3JnYXNtIiwibWFzdHVyYmF0' .
            'ZSIsIm1hc3R1cmJhdGlvbiIsImFuYWwiLCJkaWNrIiwiZGlja3MiLCJjb2NrIiwiY29ja3MiLCJw' .
            'ZW5pcyIsInZhZ2luYSIsIm5pcHBsZSIsIm5pcHBsZXMiLCJmdWNrIiwiZnVja2luZyIsImZ1Y2tl' .
            'ZCIsImZ1Y2tlciIsIm1vdGhlcmZ1Y2tlciIsInNoaXQiLCJiaXRjaCIsImJpdGNoZXMiLCJzbHV0' .
            'Iiwid2hvcmUiLCJjdW50IiwibWlsZiIsImVzY29ydCIsImhvb2t1cCIsInRocmVlc29tZSIsIm9y' .
            'Z3kiLCJiZHNtIiwiZG9taW5hdHJpeCIsInBsYXlib3kiLCJuYXVnaHR5IiwiY3JlYW1waWUiLCJw' .
            'ZWRvIiwicGVkb3BoaWxlIiwibG9saSIsInJhcGUiLCJyYXBlZCIsImdvcmUiLCJiZWhlYWRpbmci' .
            'LCJkZWNhcGl0YXRlZCIsImNvcnBzZSIsImxpdmVsZWFrIiwiNjkiLCJiaWtpbmkiLCJiaWtpbmlz' .
            'IiwidW5kZXJib29iIiwidW5kZXJ3ZWFyIiwiY2xlYXZhZ2UiLCJicmEiLCJwYW50aWVzIiwibnNm' .
            'dzE4Iiwi2YbZitmDIiwi2LnYp9ix2YrZhyIsIti52KfYsdmKIiwi2LnYp9ix2YrYp9iqIiwi2KzZ' .
            'htizIiwi2KzZhtiz2YoiLCLYrNmG2LPZitmHIiwi2LnYp9mH2LHZhyIsItiy2YbYpyIsItmB2KfY' .
            'rNix2YciLCLYp9iz2KrZhdmG2KfYoSIsItis2YXYp9i5Iiwi2YXYttin2KzYudmHIiwi2YfYp9mK' .
            '2KzZhyIsItmH2KfZitisIiwi2LTZh9mI2YciLCLYtNmH2YjYp9mG2YrZhyIsItiy2KgiLCLYrtmI' .
            '2YQiLCLYqNiy2KfYstmKIiwi2YXYpNiu2LHZhyIsItmF2KTYrtix2KrZh9inIiwi2LXYr9ix2YfY' .
            'pyIsItix2K/ZgSJd',
        'T2_PHRASES' =>
            'WyJtaWEga2hhbGlmYSIsIm1pYSBraGFsaWZhaCIsImpvaG5ueSBzaW5zIiwiam9obm55IHNpbnNt' .
            'aXRoIiwibGFuYSByaG9hZGVzIiwiZXZhIGVsZmllIiwiYWJlbGxhIGRhbmdlciIsImtlbmRyYSBs' .
            'dXN0IiwiYXNhIGFraXJhIiwiamVubmEgamFtZXNvbiIsInNhc2hhIGdyZXkiLCJhZHJpYW5hIGNo' .
            'ZWNoaWsiLCJtaWEgbWFsa292YSIsInJpbGV5IHJlaWQiLCJlbHNhIGplYW4iLCJqb3JkaSBlbCBu' .
            'aW5vIHBvbGxhIiwiYmVsbGUgZGVscGhpbmUiLCJvbmx5IGZhbnMiLCJ4IHZpZGVvcyIsInggbnh4' .
            'IiwieHggbngiLCJzZXggc2NlbmUiLCJzZXggc2NlbmVzIiwic2V4IHBvc2l0aW9uIiwic2V4IHBv' .
            'c2l0aW9ucyIsImhvdCBnaXJsIGJpa2luaSIsImJpa2luaSBnaXJsIiwiYmlraW5pIGdpcmxzIiwi' .
            '2YXZitinINiu2YTZitmB2YciLCLZhdmK2Kcg2K7ZhNmK2YHYqSIsItis2YjZhtmKINiz2YrZhtiy' .
            'Iiwi2KzZiNmG2Yog2LPZhtiyIiwi2LPZhtmKINmE2YrZiNmGIiwi2YXYtNin2YfYryDYrNmG2LPZ' .
            'itmHIiwi2YXZgtin2LfYuSDYrNmG2LPZitmHIiwi2YjYtti52YrYp9iqINis2YbYs9mK2YciXQ==',
        'CATEGORY' =>
            'eyJnb3JlIjpbImdvcmUiLCJiZWhlYWRpbmciLCJkZWNhcGl0YXRlZCIsImNvcnBzZSIsImxpdmVs' .
            'ZWFrIiwiYmVzdGdvcmUiLCJzbnVmZiIsImdvcmUgdmlkZW8iLCJnb3JlIHZpZGVvcyIsImJlaGVh' .
            'ZGluZyB2aWRlbyIsImJlaGVhZGluZyB2aWRlb3MiLCJleGVjdXRpb24gdmlkZW8iLCJyZWFsIGRl' .
            'YXRoIHZpZGVvIiwic251ZmYgZmlsbSJdLCJjaGlsZF9zYWZldHkiOlsiamFpbGJhaXQiLCJwdGhj' .
            'IiwibG9saWNvbiIsInNob3RhY29uIiwibG9saSIsInBlZG8iLCJwZWRvcGhpbGUiLCJjaGlsZCBw' .
            'b3JuIl0sInByb2Zhbml0eSI6WyJmdWNrIiwiZnVja2luZyIsImZ1Y2tlZCIsImZ1Y2tlciIsIm1v' .
            'dGhlcmZ1Y2tlciIsInNoaXQiLCJiaXRjaCIsImJpdGNoZXMiLCJzbHV0Iiwid2hvcmUiLCJjdW50' .
            'Iiwi2K7ZiNmEIl19',
    ];

    /** يفك قائمة مشفّرة من DATA. */
    private static function words(string $key): array
    {
        $d = json_decode((string) base64_decode(self::DATA[$key] ?? '', true), true);

        return is_array($d) ? $d : [];
    }

    private static ?array $built = null;

    // ───────────── normalization ─────────────

    public static function normalize(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);
            if (is_string($n)) {
                $s = $n;
            }
        }
        $s = mb_strtolower($s, 'UTF-8');
        // zero-width + bidi controls + تشكيل + تطويل
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s) ?? $s;
        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ک' => 'ك', 'ی' => 'ي', 'ھ' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            // حروف كيريلية/يونانية شبه اللاتيني
            'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x', 'у' => 'y', 'і' => 'i', 'ѕ' => 's', 'ο' => 'o', 'ρ' => 'p', 'α' => 'a', 'ν' => 'v', 'ε' => 'e',
        ]);

        return $s;
    }

    /** @return string[] الـ tokens (بعد دمج الحروف المفردة المتتالية "p o r n"). */
    public static function tokens(string $norm): array
    {
        $parts = preg_split('/[^\p{L}\p{N}@$!]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $plain = [];
        foreach ($parts as $p) {
            $q = str_replace(['@', '$', '!'], '', $p);
            if ($q === '') {
                $q = $p;
            }
            $plain[] = ['raw' => $p, 'plain' => $q];
        }
        $out = [];
        $run = '';
        $runRaw = '';
        $runLen = 0;
        $flush = function () use (&$out, &$run, &$runRaw, &$runLen) {
            if ($runLen >= 2) {
                $out[] = $runRaw;
            }
            $run = '';
            $runRaw = '';
            $runLen = 0;
        };
        foreach ($plain as $t) {
            $out[] = $t['raw'];
            if ($t['plain'] !== $t['raw']) {
                $out[] = $t['plain'];
            }
            if (mb_strlen($t['plain']) === 1) {
                $runRaw .= $t['raw'];
                $runLen++;
            } else {
                $flush();
            }
        }
        $flush();

        return $out;
    }

    /** كل صيغ التحايل للـ token الواحد. @return string[] */
    public static function variants(string $tok): array
    {
        $v = [$tok];
        $leet = strtr($tok, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '@' => 'a', '$' => 's', '!' => 'i']);
        if ($leet !== $tok) {
            $v[] = $leet;
        }
        foreach ([$tok, $leet] as $base) {
            $c2 = preg_replace('/(.)\1{2,}/us', '$1$1', $base);
            $c1 = preg_replace('/(.)\1+/us', '$1', $base);
            if ($c2 !== null && $c2 !== $base) {
                $v[] = $c2;
            }
            if ($c1 !== null && $c1 !== $base) {
                $v[] = $c1;
            }
        }

        return array_values(array_unique($v));
    }

    // ───────────── build ─────────────

    private static function norm(array $words): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($w) => trim(self::normalize((string) $w)), $words), fn ($w) => $w !== '')));
    }

    private static function build(): array
    {
        if (self::$built !== null) {
            return self::$built;
        }
        $ar1 = self::norm(self::words('T1_AR'));
        $arPrefix = '(?:و|ف|ب|ل|ال|وال|بال|لل|فال)?';
        $tiny = array_filter($ar1, fn ($w) => mb_strlen($w) <= 2);
        $short = array_filter($ar1, fn ($w) => mb_strlen($w) > 2 && mb_strlen($w) <= 4);
        $long = array_filter($ar1, fn ($w) => mb_strlen($w) > 4);
        $reParts = [];
        if ($tiny) {
            $reParts[] = '(?:ال)?(?:' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $tiny)) . ')';
        }
        if ($short) {
            $reParts[] = $arPrefix . '(?:' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $short)) . ')';
        }
        if ($long) {
            $reParts[] = $arPrefix . '(?:' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $long)) . ')(?:ي|ه|ها|ات|ين|ون|هم|ك)?';
        }
        $cat = [];
        foreach (self::words('CATEGORY') as $c => $words) {
            foreach (self::norm($words) as $w) {
                $cat[$w] = $c;
            }
        }

        return self::$built = [
            't1_tokens' => array_flip(self::norm(self::words('T1_TOKENS'))),
            't1_substr' => self::norm(self::words('T1_SUBSTR')),
            't1_phrases' => self::norm(self::words('T1_PHRASES')),
            't1_ar_re' => $reParts ? '/^(?:' . implode('|', $reParts) . ')$/u' : null,
            't2_tokens' => array_flip(self::norm(self::words('T2_TOKENS'))),
            't2_phrases' => self::norm(self::words('T2_PHRASES')),
            'cat' => $cat,
        ];
    }

    private static function categoryOf(string $term): string
    {
        return self::build()['cat'][$term] ?? 'sexual';
    }

    // ───────────── API ─────────────

    /**
     * @param string[] $extra  كلمات/عبارات إضافية ممنوعة (من الأدمن/.env)
     * @param string[] $allow  استثناءات (كلمات متتحجبش حتى لو في القاموس)
     * @return array{tier:int,category:string,term:string}|null  أول مخالفة، أو null لو النص نضيف
     */
    public static function check(string $text, bool $strict = false, array $extra = [], array $allow = []): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 2) {
            return null;
        }
        if (mb_strlen($text) > 300000) {
            $text = mb_substr($text, 0, 300000);
        }
        $b = self::build();
        $norm = self::normalize($text);
        $allowSet = $allow ? array_flip(self::norm($allow)) : [];
        $extraN = $extra ? self::norm($extra) : [];

        $tokens = self::tokens($norm);
        $seen = [];
        foreach ($tokens as $tok) {
            if ($tok === '' || isset($seen[$tok])) {
                continue;
            }
            $seen[$tok] = true;
            foreach (self::variants($tok) as $v) {
                if (isset($allowSet[$v])) {
                    continue 2;
                }
                if (isset($b['t1_tokens'][$v])) {
                    return ['tier' => 1, 'category' => self::categoryOf($v), 'term' => $v];
                }
                if ($b['t1_ar_re'] && preg_match('/\p{Arabic}/u', $v) && preg_match($b['t1_ar_re'], $v)) {
                    return ['tier' => 1, 'category' => 'sexual', 'term' => $v];
                }
                if (mb_strlen($v) >= 4) {
                    foreach ($b['t1_substr'] as $s) {
                        if (str_contains($v, $s)) {
                            return ['tier' => 1, 'category' => 'sexual', 'term' => $s];
                        }
                    }
                }
                foreach ($extraN as $e) {
                    if ($e !== '' && !str_contains($e, ' ') && ($v === $e || (mb_strlen($e) >= 5 && str_contains($v, $e)))) {
                        return ['tier' => 1, 'category' => 'custom', 'term' => $e];
                    }
                }
                if ($strict && isset($b['t2_tokens'][$v])) {
                    return ['tier' => 2, 'category' => self::categoryOf($v), 'term' => $v];
                }
            }
            // عربي tier2 مع سوابق (بحث الـ GIF بيجي بسوابق زي و/ف/ب/ال)
            if ($strict && preg_match('/\p{Arabic}/u', $tok)) {
                foreach (['و', 'ف', 'ب', 'ل', 'ال', 'وال', 'بال', 'لل', 'فال'] as $pre) {
                    if (str_starts_with($tok, $pre) && isset($b['t2_tokens'][mb_substr($tok, mb_strlen($pre))])) {
                        $w = mb_substr($tok, mb_strlen($pre));
                        if (!isset($allowSet[$w])) {
                            return ['tier' => 2, 'category' => self::categoryOf($w), 'term' => $w];
                        }
                    }
                }
            }
        }

        $joined = ' ' . implode(' ', array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: []))) . ' ';
        foreach ($b['t1_phrases'] as $ph) {
            if (str_contains($joined, ' ' . $ph . ' ')) {
                return ['tier' => 1, 'category' => self::categoryOf($ph), 'term' => $ph];
            }
        }
        foreach ($extraN as $e) {
            if ($e !== '' && str_contains($e, ' ') && str_contains($joined, ' ' . $e . ' ')) {
                return ['tier' => 1, 'category' => 'custom', 'term' => $e];
            }
        }
        if ($strict) {
            foreach ($b['t2_phrases'] as $ph) {
                if (isset($allowSet[$ph])) {
                    continue;
                }
                if (str_contains($joined, ' ' . $ph . ' ')) {
                    return ['tier' => 2, 'category' => self::categoryOf($ph), 'term' => $ph];
                }
            }
        }

        // قوائم الـ Lexicon Hub المحمّلة محليًا (php artisan lexicon:sync) — هاشات: دومينات + كلمات/عبارات. من غير نسخة محمّلة بترجّع null.
        return LexiconStore::check($norm, $strict, $allowSet);
    }
}
