<?php

namespace App\Support;

/**
 * يترجم رسائل الـ API (message / errors) اللي بترجع إنجليزي من الـ controllers والـ services
 * لما اللغة تكون عربي (هيدر X-Locale: ar)، من غير ما نعدّل ~1400 مكان في الكود.
 *
 * الترتيب:
 *  1) مطابقة كاملة في lang/ar.json
 *  2) أنماط فيها متغيّرات (lang/ar/patterns.json) — {1},{2} في الترجمة بتاخد قيم المجموعات بالترتيب
 *  3) رسائل CRUD العامة "X created/updated/... successfully." (lang/ar/nouns.json للأسماء)
 *  4) بادئات الرسائل اللي بتتكمّل بقيمة ديناميكية (lang/ar/prefixes.json)
 * أي رسالة مالقيناش لها ترجمة بترجع زي ما هي (مفيش خطر إنها تتكسر).
 */
class MessageTranslator
{
    private static ?array $exact = null;
    private static ?array $patterns = null;
    private static ?array $prefixes = null;
    private static ?array $nouns = null;

    private const CRUD = [
        'created' => 'تم إنشاء %s بنجاح.',
        'updated' => 'تم تحديث %s بنجاح.',
        'deleted' => 'تم حذف %s بنجاح.',
        'saved'   => 'تم حفظ %s بنجاح.',
        'removed' => 'تمت إزالة %s بنجاح.',
        'added'   => 'تمت إضافة %s بنجاح.',
        'sent'    => 'تم إرسال %s بنجاح.',
    ];

    public static function translate(string $message): string
    {
        $m = trim($message);
        if ($m === '' || preg_match('/\p{Arabic}/u', $m)) {
            return $message;
        }
        self::load();

        if (isset(self::$exact[$m])) {
            return self::$exact[$m];
        }

        foreach (self::$patterns as $p) {
            if (preg_match('/' . str_replace('/', '\/', $p['regex']) . '/su', $m, $mm)) {
                return preg_replace_callback('/\{(\d+)\}/', fn ($x) => $mm[(int) $x[1]] ?? '', $p['ar']);
            }
        }

        if (preg_match('/^(.+?) (retrieved|created|updated|deleted|saved|removed|added|sent) successfully\.?$/su', $m, $mm)) {
            $noun = self::$nouns[$mm[1]] ?? null;
            if ($mm[2] === 'retrieved') {
                return $noun ? "تم جلب {$noun} بنجاح." : 'تم جلب البيانات بنجاح.';
            }
            if ($noun) {
                return sprintf(self::CRUD[$mm[2]], $noun);
            }
            return ['created' => 'تم الإنشاء بنجاح.', 'updated' => 'تم التحديث بنجاح.', 'deleted' => 'تم الحذف بنجاح.',
                'saved' => 'تم الحفظ بنجاح.', 'removed' => 'تمت الإزالة بنجاح.', 'added' => 'تمت الإضافة بنجاح.',
                'sent' => 'تم الإرسال بنجاح.'][$mm[2]];
        }

        foreach (self::$prefixes as $en => $ar) {
            if (str_starts_with($m, $en)) {
                return $ar . substr($m, strlen($en));
            }
        }

        return $message;
    }

    /** يترجم قيم errors (نص، أو مصفوفة/كائن من النصوص أو من مصفوفات نصوص). */
    public static function translateDeep(mixed $v): mixed
    {
        if (is_string($v)) {
            return self::translate($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $v[$k] = self::translateDeep($item);
            }
            return $v;
        }
        if (is_object($v)) {
            foreach (get_object_vars($v) as $k => $item) {
                $v->$k = self::translateDeep($item);
            }
        }
        return $v;
    }

    private static function load(): void
    {
        if (self::$exact !== null) {
            return;
        }
        $base = dirname(__DIR__, 2) . '/lang';
        $read = static function (string $f): array {
            return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        };
        self::$exact    = $read("$base/ar.json");
        self::$patterns = $read("$base/ar/patterns.json");
        self::$prefixes = $read("$base/ar/prefixes.json");
        self::$nouns    = $read("$base/ar/nouns.json");
    }
}
