<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** إيميل الصفوف المحذوفة: الـ tombstone (`deleted-<id>-<time>-<email>`) + حفظ الإيميل الأصلي في users.original_email. */
class DeletedEmail
{
    public static function original(object $row): string
    {
        $orig = trim((string) ($row->original_email ?? ''));
        if ($orig !== '') {
            return $orig;
        }
        $email = (string) ($row->email ?? '');

        return preg_replace('/^deleted-' . (int) $row->id . '-\d+-/', '', $email) ?? $email;
    }

    /**
     * يحرّر الإيميل من أي صف محذوف ماسكه (unique) بتحويله لـ tombstone ويحفظ الأصلي.
     * $exceptId: صف مش هنلمسه (الحساب اللي بيترجّع).
     */
    public static function release(string $email, ?int $exceptId = null): void
    {
        $email = trim($email);
        if ($email === '') {
            return;
        }
        $q = DB::table('users')->whereNotNull('deleted_at')->whereRaw('LOWER(email) = ?', [strtolower($email)]);
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }
        foreach ($q->get(['id', 'email', 'original_email']) as $row) {
            DB::table('users')->where('id', $row->id)->update([
                'email'          => substr('deleted-' . $row->id . '-' . time() . '-' . $row->email, 0, 190),
                'original_email' => $row->original_email ?: $row->email,
            ]);
        }
    }
}
