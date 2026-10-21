<?php

namespace App\Services\Ai;

/**
 * معالج بلوكات التعديل (SEARCH/REPLACE) اللي مساعد الكود بيرجّعها في وضع "المساعد".
 *
 * الموديل بيرد بشرح + بلوكات بالشكل ده:
 *
 *   <<<<<<< SEARCH
 *   السطور الأصلية بالظبط
 *   =======
 *   السطور الجديدة
 *   >>>>>>> REPLACE
 *
 * ليه مش "الكود كله بعد التصليح"؟ لأن SEARCH/REPLACE بيخلّي الفرونت يعرض diff صغير ودقيق،
 * وبيسمح للسيرفر يتحقق إن كل تعديل فعلًا بيشاور على جزء موجود مرة واحدة بس في ملف الطالب
 * (موديل بيهلوس سطر مش موجود = التعديل بيتشال، مش بيتطبّق غلط).
 *
 * الكلاس ده pure PHP (من غير Laravel) عشان يتختبر لوحده.
 */
class CodeEditBlocks
{
    public const MAX_EDITS = 8;

    private const PATTERN = '/^<{7} SEARCH[ \t]*\R(.*?)\R={7}[ \t]*\R(.*?)\R?^>{7} REPLACE[ \t]*$/ms';

    /** CRLF/CR → LF عشان المطابقة ماتتكسرش بسبب نهايات السطور. */
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * النص اللي الطالب يشوفه: كل اللي قبل أول بلوك تعديل (بدون علامات البلوكات).
     * بنقص عند أول "<<<<<<< SEARCH" في بداية سطر.
     */
    public static function visibleText(string $text): string
    {
        $text = self::normalize($text);
        if (preg_match('/^<{7} SEARCH/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, 0, $m[0][1]);
        }
        return rtrim($text);
    }

    /**
     * بيطلّع البلوكات الصالحة بس: SEARCH غير فاضي، وبيظهر مرة واحدة بالظبط في $code،
     * ومفيش تداخل مع بلوك قبله، وSEARCH مختلف عن REPLACE. باقي البلوكات بتتشال.
     *
     * @return array<int,array{search:string,replace:string,start_line:int,end_line:int}>
     */
    public static function parse(string $modelText, string $code): array
    {
        $modelText = self::normalize($modelText);
        $code = self::normalize($code);

        if (!preg_match_all(self::PATTERN, $modelText, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $edits = [];
        $taken = []; // [startOffset, endOffset] لكل تعديل مقبول
        foreach ($matches as $m) {
            $search = $m[1];
            $replace = $m[2];

            if (trim($search) === '' || $search === $replace) {
                continue;
            }
            if (substr_count($code, $search) !== 1) {
                continue; // مش موجود، أو غامض (بيظهر أكتر من مرة)
            }

            $pos = (int) strpos($code, $search);
            $end = $pos + strlen($search);
            foreach ($taken as [$a, $b]) {
                if ($pos < $b && $end > $a) {
                    continue 2; // بيتداخل مع تعديل سابق
                }
            }
            $taken[] = [$pos, $end];

            $startLine = substr_count(substr($code, 0, $pos), "\n") + 1;
            $edits[] = [
                'search'     => $search,
                'replace'    => $replace,
                'start_line' => $startLine,
                'end_line'   => $startLine + substr_count($search, "\n"),
            ];

            if (count($edits) >= self::MAX_EDITS) {
                break;
            }
        }

        usort($edits, fn ($a, $b) => $a['start_line'] <=> $b['start_line']);
        return $edits;
    }

    /** بيطبّق التعديلات (اللي رجّعها parse) على الكود — للاختبارات ولأي عميل بيحتاجه. */
    public static function apply(string $code, array $edits): string
    {
        $code = self::normalize($code);
        foreach ($edits as $e) {
            $pos = strpos($code, $e['search']);
            if ($pos !== false) {
                $code = substr_replace($code, $e['replace'], $pos, strlen($e['search']));
            }
        }
        return $code;
    }
}
