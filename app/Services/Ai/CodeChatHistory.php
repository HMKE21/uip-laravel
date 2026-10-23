<?php

namespace App\Services\Ai;

/**
 * محادثات شات الكود المحفوظة على حساب الطالب (ai_code_chats). كل المنطق هنا pure PHP (من غير Laravel/DB)
 * عشان يتختبر لوحده: تنضيف الرسائل اللي بتيجي من الفرونت قبل ما تتخزّن، والعنوان، وحدود الحجم.
 *
 * الرسالة المخزّنة: { id, role, content, phase?, mode?, error?, edits?: [{ id, path, kind, search, replace,
 * start_line, end_line, state, rewrite? }] }. حالة التعديل (pending/applied/rejected/stale) بتتخزّن عشان لما الطالب
 * يرجع للمحادثة يلاقي اللي طبّقه متعلّم؛ والتطبيق نفسه بيتحقق من الملف الحالي (stale لو الكود اتغيّر).
 */
class CodeChatHistory
{
    public const MAX_MESSAGES = 300;
    public const MAX_CONTENT = 30000;
    public const MAX_EDIT_TEXT = 60000;
    public const MAX_TOTAL_BYTES = 1500000;
    public const MAX_TITLE = 120;
    public const MAX_CHATS_PER_USER = 60;

    private const PHASES = ['done', 'error', 'stopped'];
    private const STATES = ['pending', 'applied', 'rejected', 'stale'];

    public static function isUuid($v): bool
    {
        return is_string($v) && (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $v);
    }

    private static function text($v, int $max): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = preg_replace('/[^\P{C}\n\t]/u', '', $v) ?? '';
        return mb_substr($v, 0, $max);
    }

    public static function cleanTitle($v): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', self::text($v, 400)) ?? '');
        return mb_substr($t, 0, self::MAX_TITLE);
    }

    /** عنوان تلقائي من أول سؤال. */
    public static function deriveTitle(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user' && trim((string) ($m['content'] ?? '')) !== '') {
                $t = self::cleanTitle($m['content']);
                return mb_strlen($t) > 60 ? rtrim(mb_substr($t, 0, 60)) . '…' : $t;
            }
        }
        return '';
    }

    /**
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException 422 (مفيش رسايل) أو 413 (أكبر من الحد)
     */
    public static function cleanMessages($raw): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $m) {
            if (!is_array($m) || !in_array($m['role'] ?? null, ['user', 'assistant'], true)) {
                continue;
            }
            $content = self::text($m['content'] ?? '', self::MAX_CONTENT);
            $id = is_string($m['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $m['id']) ? $m['id'] : 'm' . (count($out) + 1);
            if (isset($seen[$id])) {
                $id .= 'x' . count($out);
            }
            $seen[$id] = true;

            $msg = ['id' => $id, 'role' => $m['role'], 'content' => $content];
            if ($m['role'] === 'assistant') {
                $phase = $m['phase'] ?? 'done';
                $msg['phase'] = in_array($phase, self::PHASES, true) ? $phase : 'stopped'; // streaming اللي اتقفل في النص = stopped
                if (in_array($m['mode'] ?? null, ['tutor', 'assistant'], true)) {
                    $msg['mode'] = $m['mode'];
                }
                if ($msg['phase'] === 'error') {
                    $msg['error'] = self::text($m['error'] ?? '', 500);
                }
                $edits = self::cleanEdits($m['edits'] ?? []);
                if ($edits) {
                    $msg['edits'] = $edits;
                }
                if ($content === '' && !$edits && $msg['phase'] !== 'error') {
                    continue; // رد فاضي (اتوقف قبل أي نص): ملوش لازمة
                }
            } elseif (trim($content) === '') {
                continue;
            }
            $out[] = $msg;
        }
        $out = array_slice($out, -self::MAX_MESSAGES);
        if (!$out) {
            throw new \RuntimeException('Nothing to save.', 422);
        }
        if (strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE)) > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException('This conversation is too large to save. Start a new chat.', 413);
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    private static function cleanEdits($raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $e) {
            if (!is_array($e) || count($out) >= CodeChatEdits::MAX_EDITS) {
                continue;
            }
            $path = CodeChatEdits::safePath($e['path'] ?? null);
            $kind = $e['kind'] ?? null;
            if ($path === null || !in_array($kind, ['edit', 'create'], true)) {
                continue;
            }
            $state = in_array($e['state'] ?? null, self::STATES, true) ? $e['state'] : 'pending';
            $edit = [
                'id'         => is_string($e['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $e['id']) ? $e['id'] : 'e' . (count($out) + 1),
                'path'       => $path,
                'kind'       => $kind,
                'search'     => self::text($e['search'] ?? '', self::MAX_EDIT_TEXT),
                'replace'    => self::text($e['replace'] ?? '', self::MAX_EDIT_TEXT),
                'start_line' => max(1, (int) ($e['start_line'] ?? 1)),
                'end_line'   => max(1, (int) ($e['end_line'] ?? 1)),
                'state'      => $state,
            ];
            if (!empty($e['rewrite'])) {
                $edit['rewrite'] = true;
            }
            $out[] = $edit;
        }
        return $out;
    }
}
