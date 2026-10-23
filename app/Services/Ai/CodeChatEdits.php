<?php

namespace App\Services\Ai;

/**
 * بلوكات التعديل المتعددة الملفات في شات اللوحة الجانبية (وضع "المساعد" بس).
 *
 * نفس فكرة CodeEditBlocks بس السطر الأول من البلوك فيه مسار الملف:
 *
 *   <<<<<<< SEARCH src/app.py
 *   السطور الأصلية بالظبط
 *   =======
 *   السطور الجديدة
 *   >>>>>>> REPLACE
 *
 * وملف جديد = قسم SEARCH فاضي (سطر ======= مباشرة بعد السطر الأول):
 *
 *   <<<<<<< SEARCH src/new_file.py
 *   =======
 *   محتوى الملف
 *   >>>>>>> REPLACE
 *
 * السيرفر بيتحقق من كل بلوك قبل ما يوصل للطالب: التعديل لازم يشاور على ملف اتبعت للموديل كسياق، وSEARCH يظهر فيه
 * مرة واحدة بالظبط ومن غير تداخل؛ والملف الجديد لازم يكون مسار آمن ومش موجود أصلًا. اللي يفشل بيتشال.
 *
 * الـ parsing بـ state machine على السطور (مش regex واحد كبير) عشان الحالات الحدّية (قسم SEARCH فاضي، بلوك ناقص
 * في آخر الرد، بلوك جديد بيبدأ قبل ما اللي قبله يخلص) تبقى واضحة ومتوقعة. pure PHP (من غير Laravel/DB).
 */
class CodeChatEdits
{
    public const MAX_EDITS = 12;
    public const MAX_NEW_FILE_CHARS = 30000;
    public const MAX_PATH_CHARS = 200;

    /**
     * مسار نسبي آمن: من غير .. أو / في الأول أو \ أو حروف تحكم، وطوله معقول. بيرجّع المسار منضّف أو null.
     */
    public static function safePath($path): ?string
    {
        if (!is_string($path)) {
            return null;
        }
        $path = trim($path);
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || mb_strlen($path) > self::MAX_PATH_CHARS) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return null;
        }
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return null;
            }
        }
        return $path;
    }

    /**
     * بيقطّع رد الموديل لبلوكات خام [path, search(lines), replace(lines)] من غير أي تحقق من المحتوى.
     *
     * @return array<int,array{path:string,search:string,replace:string,has_search:bool}>
     */
    public static function rawBlocks(string $modelText): array
    {
        $lines = explode("\n", CodeEditBlocks::normalize($modelText));
        $blocks = [];
        $state = 0; // 0 = برّه بلوك، 1 = جوه SEARCH، 2 = جوه REPLACE
        $path = '';
        $search = [];
        $replace = [];

        foreach ($lines as $line) {
            if (preg_match('/^<{7} SEARCH[ \t]+(\S.*?)[ \t]*$/', $line, $m)) {
                // بلوك جديد بيبدأ: أي بلوك لسه مفتوح قبله ناقص ← بيتشال
                $state = 1;
                $path = $m[1];
                $search = [];
                $replace = [];
                continue;
            }
            if ($state === 1 && preg_match('/^={7}[ \t]*$/', $line)) {
                $state = 2;
                continue;
            }
            if ($state === 2 && preg_match('/^>{7} REPLACE[ \t]*$/', $line)) {
                $blocks[] = [
                    'path'       => $path,
                    'search'     => implode("\n", $search),
                    'replace'    => implode("\n", $replace),
                    'has_search' => count($search) > 0,
                ];
                $state = 0;
                continue;
            }
            if ($state === 1) {
                $search[] = $line;
            } elseif ($state === 2) {
                $replace[] = $line;
            }
        }

        return $blocks;
    }

    /**
     * بيطلّع البلوكات الصالحة بس.
     *
     * @param array<string,string> $files مسار → محتوى (الملفات اللي اتبعتت للموديل كسياق، بعد التطبيع)
     * @param array<int,string>    $tree  كل مسارات المشروع المعروفة (عشان الملف الجديد مايكتبش فوق ملف موجود)
     * @param array<int,string>    $noRewrite ملفات اتقصّ محتواها في السياق: ممنوع إعادة كتابتها بالكامل (هنخسر الباقي)
     * @return array<int,array{path:string,kind:string,search:string,replace:string,start_line:int,end_line:int}>
     */
    public static function parse(string $modelText, array $files, array $tree = [], array $noRewrite = []): array
    {
        $known = array_fill_keys(array_map('strval', array_merge(array_keys($files), $tree)), true);
        $order = [];   // ترتيب ظهور الملفات في رد الموديل
        $taken = [];   // path → [[start,end], …] لكل تعديل مقبول
        $created = []; // path → true
        $edits = [];

        foreach (self::rawBlocks($modelText) as $b) {
            if (count($edits) >= self::MAX_EDITS) {
                break;
            }
            $path = self::safePath($b['path']);
            if ($path === null) {
                continue;
            }

            if (!$b['has_search']) {
                // قسم SEARCH فاضي على ملف موجود اتبعت للموديل = إعادة كتابة الملف بالكامل (بتتعرض كـ diff عادي: القديم ← الجديد)
                if (array_key_exists($path, $files) && !isset($created[$path])) {
                    if (in_array($path, $noRewrite, true)) {
                        continue;
                    }
                    $old = CodeEditBlocks::normalize((string) $files[$path]);
                    $new = $b['replace'];
                    if (trim($new) === '' || $new === $old || mb_strlen($new) > self::MAX_NEW_FILE_CHARS || trim($old) === '' || !empty($taken[$path])) {
                        continue;
                    }
                    $taken[$path][] = [0, strlen($old)];
                    $order[$path] ??= count($order);
                    $edits[] = [
                        'path'       => $path,
                        'kind'       => 'edit',
                        'search'     => $old,
                        'replace'    => $new,
                        'start_line' => 1,
                        'end_line'   => 1 + substr_count($old, "\n"),
                        'rewrite'    => true,
                    ];
                    continue;
                }
                // ملف جديد (المجلدات بتتعمل لوحدها من المسار)
                if (isset($known[$path]) || isset($created[$path])) {
                    continue;
                }
                if (trim($b['replace']) === '' || mb_strlen($b['replace']) > self::MAX_NEW_FILE_CHARS) {
                    continue;
                }
                $created[$path] = true;
                $order[$path] ??= count($order);
                $edits[] = [
                    'path'       => $path,
                    'kind'       => 'create',
                    'search'     => '',
                    'replace'    => $b['replace'],
                    'start_line' => 1,
                    'end_line'   => 1,
                ];
                continue;
            }

            // تعديل ملف موجود
            if (!array_key_exists($path, $files)) {
                continue; // ملف ما اتبعتش للموديل (هلوسة أو ملف تاني)
            }
            $code = CodeEditBlocks::normalize((string) $files[$path]);
            $search = $b['search'];
            if (trim($search) === '' || $search === $b['replace']) {
                continue;
            }
            if (substr_count($code, $search) !== 1) {
                continue; // مش موجود، أو غامض (بيظهر أكتر من مرة)
            }
            $pos = (int) strpos($code, $search);
            $end = $pos + strlen($search);
            $overlap = false;
            foreach ($taken[$path] ?? [] as [$a, $z]) {
                if ($pos < $z && $end > $a) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) {
                continue;
            }
            $taken[$path][] = [$pos, $end];
            $order[$path] ??= count($order);

            $startLine = substr_count(substr($code, 0, $pos), "\n") + 1;
            $edits[] = [
                'path'       => $path,
                'kind'       => 'edit',
                'search'     => $search,
                'replace'    => $b['replace'],
                'start_line' => $startLine,
                'end_line'   => $startLine + substr_count($search, "\n"),
            ];
        }

        // ترتيب: حسب أول ظهور للملف في الرد، وجوه الملف الواحد حسب رقم السطر (الملف الجديد قبل تعديلاته مش موجود أصلًا)
        usort($edits, fn ($a, $b) => [$order[$a['path']], $a['start_line']] <=> [$order[$b['path']], $b['start_line']]);
        return $edits;
    }

    /**
     * بيطبّق التعديلات (اللي رجّعها parse) ويرجّع مسار → المحتوى الجديد (للملفات اللي اتغيّرت أو اتعملت بس).
     * للاختبارات ولأي عميل بيحتاجه.
     *
     * @param array<string,string> $files
     * @param array<int,array<string,mixed>> $edits
     * @return array<string,string>
     */
    public static function apply(array $files, array $edits): array
    {
        $out = [];
        foreach ($edits as $e) {
            $path = $e['path'];
            if ($e['kind'] === 'create') {
                $out[$path] = (string) $e['replace'];
                continue;
            }
            $cur = $out[$path] ?? CodeEditBlocks::normalize((string) ($files[$path] ?? ''));
            $pos = strpos($cur, (string) $e['search']);
            if ($pos === false) {
                continue;
            }
            $out[$path] = substr($cur, 0, $pos) . $e['replace'] . substr($cur, $pos + strlen((string) $e['search']));
        }
        return $out;
    }
}
