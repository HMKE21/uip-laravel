<?php

namespace Tests\Unit;

use App\Support\ContentLexicon;
use PHPUnit\Framework\TestCase;

/**
 * اختبارات ContentLexicon.
 * بيانات الاختبار محفوظة مشفّرة (base64 + JSON) عمدًا، فمفيش نص محظور بيظهر صريح في الملف.
 * القيم بتتفك وقت التشغيل بس عن طريق data().
 */
class ContentLexiconTest extends TestCase
{

    private const BLOCKED =
            'WyJ4bnh4IiwiWE5YWCB2aWRlbyIsInAuby5yLm4iLCJwIG8gciBuIiwicDBybiIsInBvcm5ubm4i' .
            'LCJmcmVlcG9ybiBzaXRlIiwi2LPZgNmD2YDYsyIsItmB2YrYr9mK2YjZh9in2Kog2LPZg9izINmF' .
            '2LXYsdmKIiwi2YbZitmDINin2YXZgyIsItmI2KfZhNiz2YPYsyIsIti02LHZhdmI2LfYqSIsInNo' .
            'YXJtb3RhIiwia29zIG9tYWsiLCJzZXggdmlkZW9zIiwi2YMg2LMg2KfZhdmDIiwic3BhbmtiYW5n' .
            'LmNvbSIsImNoQHR1cmJhdGUiLCJ4bnh4eCIsInggbiB4IHgiLCLYqNmI2LHZhtmH2KfYqCJd';

    private const CLEAN =
            'WyJoZWxsbyB3b3JsZCIsIlN1c3NleCBjb3VudHkiLCJFc3NleCIsInBvcGNvcm4iLCJ0b3Agb3Ig' .
            'bm9uZSIsInNleCBkaWZmZXJlbmNlcyBpbiBtaWNlIiwi2YPYs9mD2LMg2YTYsNmK2LAiLCLYp9mE' .
            '2YPYs9ixINin2YTYudi02LHZiiIsItis2YrYs9mI2YYg2KjZiNix2YYiLCLZhtmK2YMg2YLYp9io' .
            '2YTYqtmHINin2YXYsyIsItiu2YjZhNipINmF2K3ZhdivIiwi2YXYrdin2LbYsdipINin2YTYrNmG' .
            '2LMg2KfZhNio2LTYsdmKIiwiY2xhc3MgYXNzaWdubWVudCIsIm1hZ25hIGN1bSBsYXVkZSIsIkRp' .
            'Y2tlbnMgbm92ZWwiLCJhbmFseXNpcyBvZiBkYXRhIl0=';

    private const STRICT_BLOCKED =
            'WyJzZXh5IGRhbmNlIiwi2YjYp9mE2KzZhtizIl0=';

    private const STRICT_CLEAN =
            'WyJoYXBweSBiaXJ0aGRheSIsItiq2LXZgdmK2YIiXQ==';

    private const PERFORMERS =
            'WyJtaWEga2hhbGlmYSIsIk1pYSBLaGFsaWZhIGdpZiIsImpvaG5ueSBzaW5zIiwi2YXZitinINiu' .
            '2YTZitmB2KkiLCJiaWtpbmkgZ2lybCIsInNleCBzY2VuZSJd';

    /** @return string[] */
    private static function data(string $key): array
    {
        return json_decode((string) base64_decode(constant('self::' . $key), true), true) ?: [];
    }

    private static function rows(string $key): array
    {
        return array_map(fn ($t) => [$t], self::data($key));
    }

    /** @dataProvider blocked */
    public function test_blocks_restricted_text_and_evasions(string $text): void
    {
        $this->assertNotNull(ContentLexicon::check($text, false), $text);
    }

    public static function blocked(): array
    {
        return self::rows('BLOCKED');
    }

    /** @dataProvider clean */
    public function test_does_not_flag_normal_text(string $text): void
    {
        $this->assertNull(ContentLexicon::check($text, false), $text);
    }

    public static function clean(): array
    {
        return self::rows('CLEAN');
    }

    public function test_strict_layer_blocks_suggestive_gif_searches_but_not_normal_ones(): void
    {
        [$a, $b] = self::data('STRICT_BLOCKED');
        [$c, $d] = self::data('STRICT_CLEAN');
        $this->assertNotNull(ContentLexicon::check($a, true));
        $this->assertNotNull(ContentLexicon::check($b, true));
        $this->assertNull(ContentLexicon::check($c, true));
        $this->assertNull(ContentLexicon::check($d, true));
        $this->assertNull(ContentLexicon::check($a, false)); // tier 2 مش بتتحجب في النصوص العادية
    }

    public function test_performer_and_suggestive_terms_are_blocked_in_strict_contexts_only(): void
    {
        foreach (self::data('PERFORMERS') as $q) {
            $this->assertNotNull(ContentLexicon::check($q, true), $q);
            $this->assertNull(ContentLexicon::check($q, false), $q . ' (normal chat text must not be blocked)');
        }
        $this->assertNull(ContentLexicon::check('Jerome and Mia went to Essex', true));
    }

    public function test_allow_list_and_extra_words(): void
    {
        $w = explode(' ', self::data('STRICT_BLOCKED')[0])[0]; // كلمة tier 2 بتتسمح بالـ allow-list
        $this->assertNotNull(ContentLexicon::check($w, true));
        $this->assertNull(ContentLexicon::check($w, true, [], [$w]));
        $this->assertSame('badword', ContentLexicon::check('a badword here', false, ['badword'])['term']);
    }
}
