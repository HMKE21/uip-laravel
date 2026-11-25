<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * تشغيل برامج خارجية (ffmpeg / ffprobe / clamscan / pdftotext / pdftoppm) من غير shell.
 *
 * بدل exec('timeout 20 ffmpeg ' . escapeshellarg($x) . ' 2>&1') بنبعت الأوامر كمصفوفة arguments:
 * مفيش shell بيفسّر أي حاجة (مفيش ; | ` $() ولا مشكلة escaping)، والـ timeout جوه الـ Process نفسه، فمفيش حقن أوامر ممكن
 * مهما كان اسم الملف أو محتواه. كل الدوال بتعدّي من غير ما ترمي: الفشل = code -1.
 */
final class Cmd
{
    /** proc_open متاحة (مش في disable_functions)؟ */
    public static function available(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * مسار الـ binary لو موجود وقابل للتنفيذ: اسم عادي (بيتدوّر عليه في PATH) أو مسار مطلق.
     */
    public static function find(string $bin): ?string
    {
        $bin = trim($bin);
        if ($bin === '' || str_contains($bin, "\0") || !self::available()) {
            return null;
        }
        if (str_contains($bin, '/') || str_contains($bin, '\\')) {
            return is_file($bin) && is_executable($bin) ? $bin : null;
        }

        return (new ExecutableFinder())->find($bin);
    }

    public static function exists(string $bin): bool
    {
        return self::find($bin) !== null;
    }

    /**
     * @param  list<string> $argv        [البرنامج, arg1, arg2, ...] — كل عنصر arg مستقل، مش سطر shell.
     * @param  bool         $mergeStderr بديل 2>&1 (stdout ثم stderr)
     * @return array{code:int,out:string,err:string,lines:list<string>,timeout:bool}
     */
    public static function run(array $argv, int $timeout = 30, bool $mergeStderr = false, ?string $cwd = null): array
    {
        $fail = ['code' => -1, 'out' => '', 'err' => '', 'lines' => [], 'timeout' => false];
        if (!$argv || !self::available()) {
            return $fail;
        }
        foreach ($argv as $a) {
            if (!is_string($a) || str_contains($a, "\0")) {
                return $fail;
            }
        }
        try {
            $p = new Process(array_values($argv), $cwd, null, null, (float) max(1, $timeout));
            $p->run();
            $out = $p->getOutput();
            $err = $p->getErrorOutput();
            $all = $mergeStderr ? $out . $err : $out;

            return [
                'code'  => (int) ($p->getExitCode() ?? -1),
                'out'   => $all,
                'err'   => $err,
                'lines' => self::lines($all),
                'timeout' => false,
            ];
        } catch (ProcessTimedOutException $e) {
            $fail['err'] = $e->getMessage();
            $fail['timeout'] = true;

            return $fail;
        } catch (\Throwable $e) { // timeout / binary missing / proc_open refused
            $fail['err'] = $e->getMessage();

            return $fail;
        }
    }

    /** @return list<string> */
    public static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n"));

        return $lines === false ? [] : array_values($lines);
    }
}
