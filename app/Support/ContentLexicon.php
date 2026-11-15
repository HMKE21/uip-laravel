<?php

namespace App\Support;

/**
 * قاموس + مطابِق نصوص لفحص المحتوى الإباحي / المخلّ / الحسّاس (عربي + إنجليزي + عربيزي).
 *
 * ليه مش بسيط (str_contains)؟
 *  - التحايل: "p.o.r.n" / "p o r n" / "p0rn" / "pornnnn" / حروف كيريلية شبه اللاتيني / تشكيل وتطويل عربي (سـكـس) / حروف عربية مختلفة (سكص مش ممسوحة — الأدمن يضيفها من extra).
 *  - الـ Scunthorpe problem: المطابقة بالكلمة الكاملة (token) مش substring، إلا للمصطلحات الطويلة الواضحة (porn, xnxx…).
 *  - العربي: بنشيل السوابق (و ف ب ل ك ال) وبنطابق اللواحق بس على الكلمات الطويلة (عشان "خولة" و"نيكي" أسماء مش تتحجب).
 *
 * طبقتين:
 *  tier 1 = ممنوع في أي نص في المنصة.
 *  tier 2 = أخف (sex, sexy, nude, شتايم…) — بتتحجب بس في بحث/عناوين الـ GIF والملصقات والأسماء والـ usernames (strict)،
 *           مش في الرسائل/المنشورات العادية، عشان نصوص أكاديمية ("sex differences") متتكسرش.
 */
class ContentLexicon
{
    // ───────────── tier 1 ─────────────
    /** كلمات كاملة (token). */
    private const T1_TOKENS = [
        // إنجليزي
        'porn', 'porno', 'pornos', 'pornography', 'pornographic', 'pornstar', 'pornstars', 'xxx', 'xnxx', 'xvideos', 'xvideo', 'xhamster',
        'pornhub', 'redtube', 'youporn', 'brazzers', 'hentai', 'blowjob', 'blowjobs', 'handjob', 'rimjob', 'cumshot', 'gangbang', 'bukkake',
        'deepthroat', 'cunnilingus', 'fellatio', 'dildo', 'pussy', 'nudes', 'jailbait', 'pthc', 'lolicon', 'shotacon', 'snuff', 'bestgore',
        'camgirl', 'camgirls', 'sexcam', 'sexcams', 'sexvideo', 'sexvideos', 'sextape', 'nsfw',
        // مواقع/منصات إباحية
        'spankbang', 'tube8', 'xtube', 'xxnx', 'chaturbate', 'stripchat', 'bongacams', 'livejasmin', 'motherless', 'eporner', 'nhentai', 'rule34',
        'fapello', 'erome', 'thothub', 'hqporner', 'porntrex', 'xxxvideos', 'pornhat', 'hclips', 'txxx',
        // عربيزي
        'sharmota', 'sharmouta', 'sharmoota', 'sharmoot', 'sharmout', 'metnak', 'metnaka', 'mitnak', 'mitnaka', 'kosomak', 'kosomek', 'kosmak',
    ];

    /** مصطلحات طويلة واضحة بتتدوّر عليها جوه الكلمة (freeporn, xnxxvideos…). */
    private const T1_SUBSTR = [
        'porn', 'xnxx', 'xvideo', 'xhamster', 'redtube', 'youporn', 'brazzers', 'hentai', 'blowjob', 'bukkake', 'gangbang', 'cumshot', 'camgirl', 'bestgore',
        'spankbang', 'chaturbate', 'stripchat', 'bongacams', 'livejasmin', 'nhentai',
    ];

    /** عبارات (بعد الـ normalize). */
    private const T1_PHRASES = [
        'sex video', 'sex videos', 'sex tape', 'sex tapes', 'sex chat', 'sex cam', 'sex cams', 'sex story', 'sex stories', 'sex pic', 'sex pics', 'sex photos',
        'adult video', 'adult videos', 'adult film', 'adult films', 'adult movie', 'adult movies', 'nude photo', 'nude photos', 'nude pic', 'nude pics',
        'nude video', 'nude videos', 'naked girl', 'naked girls', 'naked woman', 'naked women', 'naked man', 'naked men', 'hot sex', 'live sex', 'free sex',
        'free porn', 'leaked nudes', 'onlyfans leak', 'onlyfans leaks', 'blue film', 'blue films', 'child porn', 'gore video', 'gore videos', 'beheading video',
        'beheading videos', 'execution video', 'real death video', 'snuff film', 'rape porn', 'kos omak', 'kos ommak', 'nik omak', 'nik ommak', 'ibn el sharmota',
        // عربي
        'نيك امك', 'نيك اختك', 'ابن المتناكه', 'ابن الشرموطه', 'ابن العرص', 'كس امك', 'كس اختك', 'كس ام', 'افلام سكس', 'فيديو سكس', 'فيديوهات سكس',
        'صور سكس', 'قصص سكس', 'قصص جنسيه', 'قصص نيك', 'فيديوهات اباحيه', 'فيديو اباحي', 'مواقع اباحيه', 'مقاطع اباحيه', 'مقاطع سكس', 'سكس مصري', 'سكس عربي',
        'نيك مصري', 'افلام اباحيه', 'افلام للكبار', 'بورن هاب', 'صور عاريه', 'فيديو عاري', 'فيديوهات عاريه', 'بنات عاريات', 'ممارسه الجنس', 'جروب سكس',
    ];

    /** كلمات عربية (بعد الـ normalize: ة→ه، ى→ي، أإآ→ا) — بتتطابق مع سوابق، ولواحق بس لو الكلمة ≥ 5 حروف. */
    private const T1_AR = [
        'سكس', 'سكسي', 'بورنو', 'اباحي', 'اباحيه', 'اباحيات', 'متناك', 'متناكه', 'منيوك', 'منيوكه', 'شرموط', 'شرموطه', 'شراميط', 'عرص', 'معرص',
        'معرصه', 'قحبه', 'قحاب', 'كس', 'كسم', 'كسمك', 'كسخت', 'كسها', 'طيز', 'طيزها', 'بظر', 'ممحون', 'ممحونه',
        'ينيك', 'هنيك', 'هنيكك', 'انيك', 'انيكك', 'نيكك', 'نيكتك', 'نيكتها', 'نيكها', 'نيكني', 'بنيك', 'نودز',
        'بورنهاب', 'اكسفيديو', 'اكسفيديوز',
    ];

    // ───────────── tier 2 ─────────────
    private const T2_TOKENS = [
        'sex', 'sexy', 'sexier', 'sexiest', 'sexting', 'sext', 'sexual', 'nude', 'naked', 'nudity', 'erotic', 'erotica', 'fetish', 'kinky', 'horny', 'boob', 'boobs',
        'boobies', 'tit', 'tits', 'titty', 'titties', 'ass', 'asses', 'butt', 'booty', 'twerk', 'twerking', 'stripper', 'strippers', 'striptease', 'lingerie', 'thong',
        'topless', 'onlyfans', 'cum', 'cumming', 'orgasm', 'masturbate', 'masturbation', 'anal', 'dick', 'dicks', 'cock', 'cocks', 'penis', 'vagina', 'nipple',
        'nipples', 'fuck', 'fucking', 'fucked', 'fucker', 'motherfucker', 'shit', 'bitch', 'bitches', 'slut', 'whore', 'cunt', 'milf', 'escort', 'hookup', 'threesome',
        'orgy', 'bdsm', 'dominatrix', 'playboy', 'naughty', 'creampie', 'pedo', 'pedophile', 'loli', 'rape', 'raped', 'gore', 'beheading', 'decapitated', 'corpse',
        'liveleak', '69', 'bikini', 'bikinis', 'underboob', 'underwear', 'cleavage', 'bra', 'panties', 'nsfw18',
        // عربي (tier2)
        'نيك', 'عاريه', 'عاري', 'عاريات', 'جنس', 'جنسي', 'جنسيه', 'عاهره', 'زنا', 'فاجره', 'استمناء', 'جماع', 'مضاجعه', 'هايجه', 'هايج', 'شهوه', 'شهوانيه', 'زب', 'خول',
        'بزازي', 'مؤخره', 'مؤخرتها', 'صدرها', 'ردف',
    ];

    /**
     * عبارات tier 2: أسماء ممثلين/مواقع إباحية معروفة — بتتحجب بس في السياقات الصارمة (بحث/عناوين الـ GIF والملصقات والأسماء)،
     * مش في الدردشة العادية. (أسماء شائعة جدًا زي "lisa ann" / "angela white" متضافتش عمدًا عشان مفيش أبرياء يتحجبوا.)
     */
    private const T2_PHRASES = [
        'mia khalifa', 'mia khalifah', 'johnny sins', 'johnny sinsmith', 'lana rhoades', 'eva elfie', 'abella danger', 'kendra lust', 'asa akira',
        'jenna jameson', 'sasha grey', 'adriana chechik', 'mia malkova', 'riley reid', 'elsa jean', 'jordi el nino polla', 'belle delphine',
        'only fans', 'x videos', 'x nxx', 'xx nx', 'sex scene', 'sex scenes', 'sex position', 'sex positions', 'hot girl bikini', 'bikini girl', 'bikini girls',
        'ميا خليفه', 'ميا خليفة', 'جوني سينز', 'جوني سنز', 'سني ليون', 'مشاهد جنسيه', 'مقاطع جنسيه', 'وضعيات جنسيه',
    ];

    /** مصطلحات جريئة → تصنيفها (غير 'sexual'). */
    private const CATEGORY = [
        'gore' => ['gore', 'beheading', 'decapitated', 'corpse', 'liveleak', 'bestgore', 'snuff', 'gore video', 'gore videos', 'beheading video', 'beheading videos', 'execution video', 'real death video', 'snuff film'],
        'child_safety' => ['jailbait', 'pthc', 'lolicon', 'shotacon', 'loli', 'pedo', 'pedophile', 'child porn'],
        'profanity' => ['fuck', 'fucking', 'fucked', 'fucker', 'motherfucker', 'shit', 'bitch', 'bitches', 'slut', 'whore', 'cunt', 'خول'],
    ];

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
    private static function tokens(string $norm): array
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
    private static function variants(string $tok): array
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
        $ar1 = self::norm(self::T1_AR);
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
        foreach (self::CATEGORY as $c => $words) {
            foreach (self::norm($words) as $w) {
                $cat[$w] = $c;
            }
        }

        return self::$built = [
            't1_tokens' => array_flip(self::norm(self::T1_TOKENS)),
            't1_substr' => self::norm(self::T1_SUBSTR),
            't1_phrases' => self::norm(self::T1_PHRASES),
            't1_ar_re' => $reParts ? '/^(?:' . implode('|', $reParts) . ')$/u' : null,
            't2_tokens' => array_flip(self::norm(self::T2_TOKENS)),
            't2_phrases' => self::norm(self::T2_PHRASES),
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
            // عربي tier2 مع سوابق (الـ GIF بتتبحث بـ "عاريه" / "والجنس")
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

        return null;
    }
}
