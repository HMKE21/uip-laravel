<?php

namespace App\Support\Forms;

use App\Models\Form;

/**
 * Short-lived "unlock" token for password-protected forms.
 * The respondent posts the password once (POST /f/{code}/unlock); the server answers with a signed token that the browser
 * then sends on every call (header X-Form-Unlock). Nothing is stored server-side, and the token dies when the form's
 * password changes (it is bound to a fingerprint of the stored hash) or after $ttl seconds.
 */
final class FormUnlock
{
    public const TTL = 43200; // 12 h

    public static function protected(Form $form): bool
    {
        return (string) $form->setting('password_hash', '') !== '';
    }

    private static function fingerprint(Form $form): string
    {
        return substr(hash('sha256', (string) $form->setting('password_hash', '')), 0, 16);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public static function issue(Form $form, int $ttl = self::TTL): string
    {
        $payload = $form->id . '|' . (time() + $ttl) . '|' . self::fingerprint($form);
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . self::sign($payload);
    }

    public static function valid(Form $form, ?string $token): bool
    {
        if (!self::protected($form)) {
            return true;
        }
        if (!$token || !str_contains($token, '.')) {
            return false;
        }
        [$b64, $sig] = explode('.', $token, 2);
        $payload = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($payload === false || !hash_equals(self::sign($payload), $sig)) {
            return false;
        }
        $parts = explode('|', $payload);
        return count($parts) === 3
            && (int) $parts[0] === (int) $form->id
            && (int) $parts[1] >= time()
            && hash_equals(self::fingerprint($form), $parts[2]);
    }

    public static function check(Form $form, string $plain): bool
    {
        $hash = (string) $form->setting('password_hash', '');
        return $hash !== '' && \Illuminate\Support\Facades\Hash::check($plain, $hash);
    }
}
