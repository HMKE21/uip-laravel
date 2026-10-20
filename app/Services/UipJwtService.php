<?php

namespace App\Services;

/**
 * نسخة طبق الأصل من core/Jwt.php بتاع المشروع القديم — نفس الـ HS256
 * بالظبط، نفس base64url، نفس ترتيب json_encode. الهدف: توكن صادر من
 * القديم يفضل شغال هنا، وتوكن صادر من هنا يفضل شغال هناك، طول فترة
 * الانتقال (طالما JWT_SECRET نفسه في الاتنين).
 */
class UipJwtService
{
    private static function secret(): string
    {
        $secret = env('JWT_SECRET');
        if (!$secret) {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }
        return (string) $secret;
    }

    private static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - (strlen($data) % 4), '=');
        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    /** @param array<string,mixed> $claims */
    public static function encode(array $claims, int $ttlSeconds): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $segments = [
            self::b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::b64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** بادئات الـ access token المشفّر. uipe2 = الحالي (فيه kid للتدوير)، uipe1 = القديم (لسه بيتفك). */
    private const SEALED_PREFIX_V1 = 'uipe1.';
    private const SEALED_PREFIX_V2 = 'uipe2.';

    private static function isSealed(string $token): bool
    {
        return strncmp($token, self::SEALED_PREFIX_V2, 6) === 0 || strncmp($token, self::SEALED_PREFIX_V1, 6) === 0;
    }

    /**
     * مفاتيح التشفير [kid => key]، أولها هو الحالي (بيشفّر بيه)، والباقي للفك بس (تدوير مفاتيح).
     *   JWT_ENC_KEY      : مفتاح التشفير الحالي — لازم يبقى مستقل عن JWT_SECRET (php artisan tinker: bin2hex(random_bytes(32))).
     *   JWT_ENC_KEY_PREV : المفتاح القديم أثناء التدوير (اختياري، شيله بعد JWT_ACCESS_TTL).
     * لو JWT_ENC_KEY مش متظبط بيتشتق من JWT_SECRET (توافق قديم) مع تحذير في اللوج.
     *
     * @return array<string,string>
     */
    private static function encKeys(): array
    {
        static $warned = false;
        $bases = [];
        $cur = env('JWT_ENC_KEY');
        if (!$cur) {
            if (!$warned && app()->environment('production')) {
                \Illuminate\Support\Facades\Log::warning('JWT_ENC_KEY is not set — access-token encryption key is derived from JWT_SECRET. Set a separate random key.');
                $warned = true;
            }
            $cur = self::secret();
        }
        $bases[] = (string) $cur;
        if ($prev = env('JWT_ENC_KEY_PREV')) {
            $bases[] = (string) $prev;
        }

        $keys = [];
        foreach ($bases as $base) {
            $key = hash_hkdf('sha256', $base, 32, 'uip-access-token-v1');
            $keys[substr(hash('sha256', 'kid|' . $key), 0, 8)] = $key;
        }
        return $keys;
    }

    /** يشفّر الـ JWT بالكامل (AES-256-GCM) فيطلع string معتم. الـ kid جوه الـ AAD فمتلعبش فيه. */
    public static function seal(string $jwt): string
    {
        $keys = self::encKeys();
        $kid  = array_key_first($keys);
        $iv   = random_bytes(12);
        $tag  = '';
        $ct   = openssl_encrypt($jwt, 'aes-256-gcm', $keys[$kid], OPENSSL_RAW_DATA, $iv, $tag, 'uip-access|' . $kid, 16);
        if ($ct === false) {
            throw new \RuntimeException('Token encryption failed.');
        }
        return self::SEALED_PREFIX_V2 . $kid . '.' . self::b64UrlEncode($iv . $tag . $ct);
    }

    /** يفك التشفير؛ null لو التوكن اتلعب فيه أو المفتاح غلط. */
    public static function open(string $sealed): ?string
    {
        $keys = self::encKeys();

        if (strncmp($sealed, self::SEALED_PREFIX_V2, 6) === 0) {
            $parts = explode('.', $sealed, 3);
            if (count($parts) !== 3 || !isset($keys[$parts[1]])) {
                return null;
            }
            $kid = $parts[1];
            $raw = self::b64UrlDecode($parts[2]);
            return self::decrypt($raw, $keys[$kid], 'uip-access|' . $kid);
        }

        if (strncmp($sealed, self::SEALED_PREFIX_V1, 6) === 0) {
            $raw = self::b64UrlDecode(substr($sealed, 6));
            foreach ($keys as $key) {
                if (($jwt = self::decrypt($raw, $key, 'uip-access')) !== null) {
                    return $jwt;
                }
            }
        }
        return null;
    }

    private static function decrypt(string $raw, string $key, string $aad): ?string
    {
        if (strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $jwt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        return $jwt === false ? null : $jwt;
    }

    /** @return array<string,mixed>|null null لو التوكن باظ أو الصلاحية خلصت أو التوقيع غلط. */
    public static function decode(string $token): ?array
    {
        if (self::isSealed($token)) {
            $inner = self::open($token);
            if ($inner === null) {
                return null;
            }
            $token = $inner;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expectedSig = self::b64UrlEncode(
            hash_hmac('sha256', $headerB64 . '.' . $payloadB64, self::secret(), true)
        );
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($payloadB64), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * فك access token فقط. decode() لوحدها بتقبل أي JWT موقّع (challenge الـ 2FA، setup، ضيف الاجتماعات)،
     * فكان ممكن challenge_token (بعد الباسورد وقبل كود الـ 2FA) يتستخدم كـ Bearer ويعدّي الـ 2FA.
     * هنا: أي توكن عليه typ مختلف عن "access" مرفوض، و AUTH_REQUIRE_SEALED=true يرفض أي توكن مش مشفّر.
     *
     * @return array<string,mixed>|null
     */
    public static function decodeAccess(string $token): ?array
    {
        if (filter_var(env('AUTH_REQUIRE_SEALED', false), FILTER_VALIDATE_BOOLEAN) && !self::isSealed($token)) {
            return null;
        }
        $claims = self::decode($token);
        if (!$claims || !isset($claims['sub'])) {
            return null;
        }
        if (isset($claims['typ']) && $claims['typ'] !== 'access') {
            return null;
        }
        return $claims;
    }

    /**
     * يصدر زوج access + refresh بنفس شكل AuthService::issueApiTokens() بالظبط.
     *
     * 'name'/'email' بينضافوا كـ claims هنا (بالإضافة لـ sub/role الأصليين)
     * عشان الفرونت (AuthContext.jsx::decodeJwt) يقدر يعرض اسم/إيميل
     * المستخدم الحقيقي بدل ما يعتمد على full_name/email مش موجودين أصلاً
     * في الـ user object بتاعه (كانوا بيرجعوا undefined دايمًا في أي
     * مكان بيحاول يعرض اسم المستخدم من الـ JWT، مش بس Meeting Room —
     * راجع نقاش الباگ ده). لسه اختياريين (null لو المستخدم اتمسح بين
     * إصدار التوكن القديم واستخدامه) عشان decode() القديم يفضل متوافق.
     */
    /**
     * عمر الـ refresh token (ثواني). مع "الدخول الدائم" (الافتراضي) بيبقى سنة، وبيتجدد مع كل استخدام (rotation)،
     * فالمستخدم عمره ما بيتسجّل خروجه إلا بنفسه. لو الأدمن قفله بيرجع JWT_REFRESH_TTL (14 يوم افتراضيًا).
     */
    public static function refreshTtl(): int
    {
        $base = (int) env('JWT_REFRESH_TTL', 1209600);
        try {
            if (\App\Support\PlatformPolicy::flag('persistent_login')) {
                return max($base, (int) config('security.persistent_refresh_ttl', 31536000));
            }
        } catch (\Throwable $e) {
            // الإعدادات مش متاحة: نفضل على القيمة العادية
        }

        return $base;
    }

    public static function issueTokenPair(int $userId, string $role, ?int $sessionId = null): array
    {
        $accessTtl  = (int) env('JWT_ACCESS_TTL', 900);
        $refreshTtl = self::refreshTtl();

        $user = \App\Models\User::find($userId);

        $rawRefresh  = bin2hex(random_bytes(32));

        // جلسة حقيقية في user_sessions: جديدة عند اللوجين، أو نفس الجلسة
        // (rotation) عند الـ refresh. الـ sid بيتحط في الـ access token عشان
        // الـ Revoke من بورتال الأمان يطرد المستخدم فعلًا.
        $sessions = app(\App\Services\UserSessionService::class);
        try {
            if ($sessionId) {
                $sessions->rotate($sessionId, $rawRefresh, $refreshTtl);
            } else {
                $sessionId = $sessions->start($userId, $rawRefresh, $refreshTtl, $role);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Session tracking failed: ' . $e->getMessage());
            throw $e; // مفيش توكن من غير sid (مينفعش يتلغى بالـ logout)
        }

        // typ=access: يمنع استخدام challenge/setup/guest tokens كـ Bearer. jti: معرّف فريد لكل توكن.
        // الاسم/الإيميل اتشالوا من التوكن (PII مالهاش لازمة جواه) — الفرونت بياخدهم من data.user.
        $claims = [
            'typ'  => 'access',
            'jti'  => bin2hex(random_bytes(8)),
            'sub'  => $userId,
            'role' => $role,
        ];
        if ($sessionId) {
            $claims['sid'] = $sessionId;
        }
        // الـ JWT نفسه (موقّع) بيتشفّر قبل ما يخرج للمتصفح، فالـ claims (الاسم/الإيميل/الدور/sid) مبقتش ظاهرة.
        $accessToken = self::seal(self::encode($claims, $accessTtl));

        \App\Models\RefreshToken::create([
            'user_id'      => $userId,
            'token_hash'   => hash('sha256', $rawRefresh),
            'device_label' => request()->userAgent() ? substr(request()->userAgent(), 0, 150) : null,
            'ip_address'   => request()->ip(),
            'expires_at'   => now()->addSeconds($refreshTtl),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $rawRefresh,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            // الفرونت بيستخدمها لقفل الجلسة لما المستخدم يسيب المنصة من غير أي تفاعل.
            'idle_timeout_minutes' => (int) round(app(\App\Services\SessionPolicyService::class)->idleTimeoutSeconds() / 60),
            // الفرونت مبقاش يقدر يقرا الـ claims من التوكن (مشفّر)، فبياخد هوية العرض من هنا.
            'user' => [
                'id'    => $userId,
                'role'  => $role,
                'name'  => $user->full_name ?? null,
                'name_ar' => $user->name_ar ?? null,
                'name_en' => $user->name_en ?? null,
                'email' => $user->email ?? null,
            ],
        ];
    }
}
