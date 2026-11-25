<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * @username لكل مستخدم (زي تليجرام): اختياري، فريد، حروف إنجليزي صغيرة + أرقام + _ .
 *
 * القواعد: 4–32 حرف، يبدأ بحرف، مفيهوش "__" ومينتهيش بـ "_"، ومش من الأسماء المحجوزة (admin / all / here ...).
 * "all" و"everyone" و"here" محجوزين لأن @all مستخدمة كمنشن للمجموعة كلها في الشات.
 * اليوزرنيم المحذوف (soft delete) فاضل محجوز عشان محدش ينتحل شخصية صاحبه.
 */
class UsernameService
{
    public const MIN = 4;
    public const MAX = 32;

    private const RESERVED = [
        'admin', 'administrator', 'root', 'system', 'support', 'help', 'security', 'staff', 'official', 'moderator', 'mod',
        'uip', 'uipe', 'api', 'www', 'mail', 'null', 'undefined', 'all', 'everyone', 'here', 'channel', 'me', 'you',
        'settings', 'login', 'logout', 'register', 'student', 'doctor', 'university', 'faculty', 'supervisor', 'network', 'messages',
    ];

    /** يشيل "@" والمسافات ويحوّل لحروف صغيرة. */
    public function normalize(?string $raw): string
    {
        return strtolower(ltrim(trim((string) $raw), '@'));
    }

    /** @return array{ok:bool,code:?string,message:?string} */
    public function validate(string $u): array
    {
        $fail = fn (string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg];
        if (strlen($u) < self::MIN) {
            return $fail('too_short', 'Username must be at least ' . self::MIN . ' characters.');
        }
        if (strlen($u) > self::MAX) {
            return $fail('too_long', 'Username must be at most ' . self::MAX . ' characters.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $u)) {
            return $fail('invalid_format', 'Use only English letters, numbers and underscore, and start with a letter.');
        }
        if (str_contains($u, '__') || str_ends_with($u, '_')) {
            return $fail('invalid_format', 'Username cannot contain a double underscore or end with an underscore.');
        }
        if (in_array($u, self::RESERVED, true)) {
            return $fail('reserved', 'This username is reserved.');
        }
        return ['ok' => true, 'code' => null, 'message' => null];
    }

    public function isTaken(string $u, ?int $exceptUserId = null): bool
    {
        $q = DB::table('users')->where('username', $u);
        if ($exceptUserId) {
            $q->where('id', '!=', $exceptUserId);
        }
        return $q->exists();
    }

    /** @return array{available:bool,code:?string,message:?string,username:string} */
    public function check(string $raw, ?int $forUserId = null): array
    {
        $u = $this->normalize($raw);
        $v = $this->validate($u);
        if (!$v['ok']) {
            return ['available' => false, 'code' => $v['code'], 'message' => $v['message'], 'username' => $u];
        }
        if ($this->isTaken($u, $forUserId)) {
            return ['available' => false, 'code' => 'taken', 'message' => 'This username is already taken.', 'username' => $u];
        }
        return ['available' => true, 'code' => null, 'message' => null, 'username' => $u];
    }

    /** @return array{username:?string,changed_at:?string,can_change_at:?string} */
    public function mine(int $uid): array
    {
        $r = DB::table('users')->where('id', $uid)->first(['username', 'username_changed_at']);
        $next = null;
        if ($r && $r->username_changed_at) {
            $t = strtotime($r->username_changed_at) + $this->cooldownMinutes() * 60;
            $next = $t > time() ? date('Y-m-d H:i:s', $t) : null;
        }
        return ['username' => $r->username ?? null, 'changed_at' => $r->username_changed_at ?? null, 'can_change_at' => $next];
    }

    /** بيحط/يغيّر اليوزرنيم. null أو '' = مسحه. بيرمي RuntimeException برسالة آمنة للعرض. */
    public function set(int $uid, ?string $raw): array
    {
        $u = $this->normalize($raw);
        $cur = $this->mine($uid);
        if ($u === '') {
            DB::table('users')->where('id', $uid)->update(['username' => null, 'username_changed_at' => now()]);
            return $this->mine($uid);
        }
        if ($cur['username'] === $u) {
            return $cur;
        }
        if ($cur['can_change_at']) {
            throw new \RuntimeException('You can change your username again after ' . $cur['can_change_at'] . '.');
        }
        $c = $this->check($u, $uid);
        if (!$c['available']) {
            throw new \RuntimeException($c['message']);
        }
        try {
            DB::table('users')->where('id', $uid)->update(['username' => $u, 'username_changed_at' => now()]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') { // سباق: حد تاني خدّه في نفس اللحظة
                throw new \RuntimeException('This username is already taken.');
            }
            throw $e;
        }
        return $this->mine($uid);
    }

    public function idFor(?string $raw): ?int
    {
        $u = $this->normalize($raw);
        if ($u === '' || !$this->validate($u)['ok']) {
            return null;
        }
        $id = DB::table('users')->where('username', $u)->whereNull('deleted_at')->value('id');
        return $id ? (int) $id : null;
    }

    /** @return array<int,int> ids المستخدمين اللي اتكتب @username بتاعهم جوه النص (لحد 20). */
    public function idsInText(string $body): array
    {
        if ($body === '' || !str_contains($body, '@')) {
            return [];
        }
        preg_match_all('/(?<![A-Za-z0-9_@.])@([A-Za-z][A-Za-z0-9_]{3,31})(?![A-Za-z0-9_@])/', $body, $m);
        $handles = array_slice(array_values(array_unique(array_map('strtolower', $m[1] ?? []))), 0, 20);
        if (!$handles) {
            return [];
        }
        return array_map('intval', DB::table('users')->whereIn('username', $handles)->whereNull('deleted_at')->pluck('id')->all());
    }

    /** اقتراحات متاحة من الاسم والإيميل. */
    public function suggest(int $uid, int $limit = 5): array
    {
        $r = DB::table('users')->where('id', $uid)->first(['full_name', 'email']);
        if (!$r) {
            return [];
        }
        $bases = [];
        $local = strtolower(preg_replace('/[^a-z0-9_]/i', '', explode('@', (string) $r->email)[0]));
        if ($local !== '') {
            $bases[] = $local;
        }
        $nameAscii = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $r->full_name)));
        $nameAscii = trim($nameAscii, '_');
        if ($nameAscii !== '') {
            $bases[] = $nameAscii;
        }
        $out = [];
        foreach ($bases as $b) {
            $b = preg_replace('/^[^a-z]+/', '', $b);
            foreach (['', '_' . random_int(10, 99), random_int(100, 999)] as $suffix) {
                $c = substr($b, 0, self::MAX - strlen($suffix)) . $suffix;
                $c = preg_replace('/_{2,}/', '_', $c);
                if (strlen($c) >= self::MIN && $this->check($c, $uid)['available'] && !in_array($c, $out, true)) {
                    $out[] = $c;
                }
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    private function cooldownMinutes(): int
    {
        return max(0, (int) config('messaging.username_cooldown_minutes', 60));
    }
}
