<?php

namespace App\Support;

/**
 * المحلل الوحيد لروابط مستودعات GitHub. قبل كده كان فيه 4 regex مختلفة من غير ^ في أولها
 * (فـ "https://evil.com/github.com/a/b" كان بيعدّي) وبتقبل owner/repo = ".." (فكان ممكن
 * يتبني https://api.github.com/repos/../../user ويتبعت بتوكن المنصة لـ endpoint تاني).
 */
final class GithubRepoUrl
{
    /** @return array{0:string,1:string}|null [owner, repo] */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('#^(?:https?://(?:www\.)?github\.com/|git@github\.com:)([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+?)(?:\.git)?/?$#i', $url, $m)) {
            return null;
        }
        if (!self::safeSegment($m[1]) || !self::safeSegment($m[2])) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /** مقطع مسار آمن: مش "." ولا ".." ولا بيبدأ/ينتهي بنقطة بشكل يخلّيه traversal. */
    public static function safeSegment(string $s): bool
    {
        return $s !== '' && $s !== '.' && $s !== '..' && strlen($s) <= 100 && preg_match('/^[A-Za-z0-9_.\-]+$/', $s) === 1;
    }
}
