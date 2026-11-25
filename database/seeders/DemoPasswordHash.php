<?php

namespace Database\Seeders;

use Illuminate\Console\Command;

/**
 * هاش باسورد حسابات الـ demo بيتولّد وقت التشغيل — مفيش bcrypt hash ولا باسورد ثابت متخزّن في الكود.
 *
 * - DEMO_ACCOUNTS_PASSWORD متظبط => بيتستخدم (مفروض يكون قوي).
 * - مش متظبط => باسورد عشوائي: بيتطبع مرة واحدة في الـ console، ومش بيتطبع أبدًا في production
 *   (الحسابات وقتها غير قابلة لتسجيل الدخول لحد ما حد يضبط باسورد حقيقي).
 */
final class DemoPasswordHash
{
    public const PLACEHOLDER = '__DEMO_PASSWORD_HASH__';

    private static ?string $hash = null;

    public static function make(?Command $console = null): string
    {
        if (self::$hash !== null) {
            return self::$hash;
        }
        $password = trim((string) env('DEMO_ACCOUNTS_PASSWORD', ''));
        if ($password === '') {
            $password = bin2hex(random_bytes(12));
            if ($console !== null && !app()->isProduction()) {
                $console->warn('DEMO_ACCOUNTS_PASSWORD not set — demo accounts password for this run: ' . $password);
            }
        }

        return self::$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /** بيستبدل الـ placeholder في SQL بالهاش (الهاش فيه [A-Za-z0-9./$] بس — آمن جوه single quotes). */
    public static function apply(string $sql, ?Command $console = null): string
    {
        return str_contains($sql, self::PLACEHOLDER)
            ? str_replace(self::PLACEHOLDER, self::make($console), $sql)
            : $sql;
    }
}
