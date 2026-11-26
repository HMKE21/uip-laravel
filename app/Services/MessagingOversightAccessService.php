<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * قفل محتوى "إشراف الرسائل".
 *
 * - محتوى الرسايل والملفات والفيديوهات (بين الأشخاص وبين الشخص والـ AI) بيرجع من الـ API مشفّر/مخفي دايمًا (الإخفاء
 *   بيحصل على السيرفر، مش على الشاشة).
 * - لفتح جزء منه: لازم المفتاح السري بتاع النطاق من .env (إدارة المنصة هي اللي معاها) + سبب + فترة تاريخ (+ محادثة
 *   اختياريًا). التصريح بيتسجّل وبينتهي لوحده بعد دقايق، وبيفتح الرسايل اللي تاريخها جوه الفترة بس.
 * - مفتاح غلط: محاولات محدودة (5 كل 10 دقايق لكل أدمن) وكل محاولة بتتسجّل في الـ audit log.
 * - لو المفتاح مش متضبط في .env: الفتح مقفول بالكامل.
 */
class MessagingOversightAccessService
{
    public const SCOPES = ['people', 'ai', 'blocked'];

    public function __construct(private AuditLogService $audit)
    {
    }

    // ------------------------------------------------------------------ config

    public function keyConfigured(string $scope): bool
    {
        return $this->expectedKey($scope) !== '';
    }

    private function expectedKey(string $scope): string
    {
        $k = match ($scope) {
            'ai'      => config('messaging.oversight.key_people_to_ai'),
            'blocked' => config('messaging.oversight.key_blocked_attempts'),
            default   => config('messaging.oversight.key_people_to_people'),
        };

        return trim((string) $k);
    }

    private function maxRangeDays(): int
    {
        return max(1, (int) config('messaging.oversight.max_range_days', 31));
    }

    // ------------------------------------------------------------------ status / grant / revoke

    /** @return array<string,mixed> */
    public function status(int $adminId): array
    {
        $rows = DB::table('messaging_oversight_grants')
            ->where('admin_id', $adminId)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->get();

        return [
            'configured' => ['people' => $this->keyConfigured('people'), 'ai' => $this->keyConfigured('ai'), 'blocked' => $this->keyConfigured('blocked')],
            'grants'     => $rows->map(fn ($g) => $this->shapeGrant((array) $g))->all(),
            'limits'     => [
                'max_range_days'  => $this->maxRangeDays(),
                'default_minutes' => (int) config('messaging.oversight.grant_minutes', 15),
                'max_minutes'     => 120,
            ],
        ];
    }

    /**
     * @param  array{key?:string,reason?:string,date_from?:string,date_to?:string,conversation_id?:int|string|null,minutes?:int|string|null} $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException بيانات ناقصة/غلط (422)
     * @throws \RuntimeException         المفتاح غلط (403) / كتر المحاولات (429) / المفتاح مش متضبط (503)
     */
    public function grant(int $adminId, string $scope, array $in, ?string $ip = null): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('Unknown scope.');
        }
        if (!$this->keyConfigured($scope)) {
            throw new \RuntimeException('The secret key for this section is not configured on the server (.env).', 503);
        }

        $throttle = 'oversight-key:' . $adminId;
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $this->audit->record($adminId, 'admin.messaging_access_throttled', 'MessagingOversight', null, null, ['scope' => $scope], $ip);
            throw new \RuntimeException('Too many wrong keys. Try again in ' . ceil(RateLimiter::availableIn($throttle) / 60) . ' minute(s).', 429);
        }

        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Write the reason for opening these messages (at least 5 characters).');
        }
        $from = $this->parseDate($in['date_from'] ?? null);
        $to = $this->parseDate($in['date_to'] ?? null);
        if (!$from || !$to) {
            throw new \InvalidArgumentException('Choose the start and end date of the messages you want to open.');
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ((int) $from->diffInDays($to) + 1 > $this->maxRangeDays()) {
            throw new \InvalidArgumentException('The date range is too long (max ' . $this->maxRangeDays() . ' days).');
        }

        $conversationId = isset($in['conversation_id']) && $in['conversation_id'] !== '' && $in['conversation_id'] !== null
            ? (int) $in['conversation_id'] : null;
        if ($scope === 'blocked') {
            $conversationId = null; // المحاولات المحجوبة مفيهاش محادثات: الفتح بالفترة بس
        }
        if ($conversationId !== null) {
            $exists = DB::table($scope === 'ai' ? 'ai_conversations' : 'conversations')->where('id', $conversationId)->exists();
            if (!$exists) {
                throw new \InvalidArgumentException('Conversation not found.');
            }
        }

        $minutes = (int) ($in['minutes'] ?? 0);
        if ($minutes <= 0) {
            $minutes = (int) config('messaging.oversight.grant_minutes', 15);
        }
        $minutes = max(5, min(120, $minutes));

        // مقارنة ثابتة الزمن — بعد كل التحققات الشكلية عشان محاولة غلط شكلاً متحسبش ضد الأدمن.
        if (!hash_equals($this->expectedKey($scope), trim((string) ($in['key'] ?? '')))) {
            RateLimiter::hit($throttle, 600);
            $this->audit->record($adminId, 'admin.messaging_access_denied', 'MessagingOversight', $conversationId, null, ['scope' => $scope], $ip);
            throw new \RuntimeException('Wrong secret key.', 403);
        }
        RateLimiter::clear($throttle);

        $id = (int) DB::table('messaging_oversight_grants')->insertGetId([
            'admin_id'        => $adminId,
            'scope'           => $scope,
            'conversation_id' => $conversationId,
            'date_from'       => $from->toDateString(),
            'date_to'         => $to->toDateString(),
            'reason'          => mb_substr($reason, 0, 255),
            'ip'              => $ip ? mb_substr($ip, 0, 64) : null,
            'expires_at'      => now()->addMinutes($minutes),
            'created_at'      => now(),
        ]);

        $this->audit->record($adminId, 'admin.messaging_access_granted', 'MessagingOversight', $conversationId, null, [
            'grant_id' => $id, 'scope' => $scope, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
            'minutes' => $minutes, 'reason' => mb_substr($reason, 0, 255),
        ], $ip);

        return $this->shapeGrant((array) DB::table('messaging_oversight_grants')->where('id', $id)->first());
    }

    public function revoke(int $adminId, int $grantId, ?string $ip = null): bool
    {
        $n = DB::table('messaging_oversight_grants')
            ->where('id', $grantId)->where('admin_id', $adminId)->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        if ($n) {
            $this->audit->record($adminId, 'admin.messaging_access_revoked', 'MessagingOversight', null, null, ['grant_id' => $grantId], $ip);
        }

        return (bool) $n;
    }

    // ------------------------------------------------------------------ evaluation

    /** تصاريح الأدمن السارية للنطاق ده، اللي بتغطي المحادثة دي (أو كل المحادثات). */
    public function activeGrants(int $adminId, string $scope, ?int $conversationId = null): array
    {
        $q = DB::table('messaging_oversight_grants')
            ->where('admin_id', $adminId)->where('scope', $scope)
            ->whereNull('revoked_at')->where('expires_at', '>', now());
        if ($conversationId !== null) {
            $q->where(fn ($w) => $w->whereNull('conversation_id')->orWhere('conversation_id', $conversationId));
        }

        return $q->get()->map(fn ($g) => (array) $g)->all();
    }

    /** هل رسالة بتاريخ ده مفتوحة بأي تصريح من دول؟ */
    public function covers(array $grants, ?string $createdAt): bool
    {
        $day = substr((string) $createdAt, 0, 10);
        if ($day === '') {
            return false;
        }
        foreach ($grants as $g) {
            if ($day >= substr((string) $g['date_from'], 0, 10) && $day <= substr((string) $g['date_to'], 0, 10)) {
                return true;
            }
        }

        return false;
    }

    /** هل أي تصريح ساري بيغطي المحادثة دي (من غير النظر للتاريخ)؟ — لعناوين القوائم. */
    public function coversConversation(array $grants, int $conversationId): bool
    {
        foreach ($grants as $g) {
            if ($g['conversation_id'] === null || (int) $g['conversation_id'] === $conversationId) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ masking

    /** نص مشفّر الشكل بطول ثابت (مفيش تسريب لطول الرسالة ولا لمحتواها). */
    public static function cipher(string $seed): string
    {
        $raw = hash_hmac('sha256', $seed, (string) config('app.key'), true) . hash_hmac('sha256', 'x' . $seed, (string) config('app.key'), true);

        return 'ENC·' . strtoupper(substr(chunk_split(rtrim(strtr(base64_encode($raw), '+/', 'AZ'), '='), 8, '-'), 0, 53));
    }

    /**
     * رسالة بين أشخاص بعد adminThread: لو مقفولة بنسيب بس الميتاداتا (مين/إمتى) ونمسح كل المحتوى (نص/ميتاداتا/ملفات/ردود).
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public function maskPeopleMessage(array $m): array
    {
        $atts = [];
        foreach ((array) ($m['attachments'] ?? []) as $a) {
            $atts[] = ['id' => (int) ($a['id'] ?? 0), 'kind' => (string) ($a['kind'] ?? 'file'), 'locked' => true];
        }

        return [
            'id'              => (int) $m['id'],
            'conversation_id' => (int) $m['conversation_id'],
            'sender_id'       => $m['sender_id'] ?? null,
            'sender_name'     => $m['sender_name'] ?? null,
            'created_at'      => $m['created_at'] ?? null,
            'is_deleted'      => (bool) ($m['is_deleted'] ?? false),
            'is_edited'       => (bool) ($m['is_edited'] ?? false),
            'message_type'    => $m['message_type'] ?? 'text',
            'locked'          => true,
            'cipher'          => self::cipher('p' . $m['id']),
            'has_text'        => !empty($m['body']),
            'attachments'     => $atts,
        ];
    }

    /**
     * رسالة مستخدم↔AI (صف ai_messages + attachments).
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public function maskAiMessage(array $m): array
    {
        $atts = [];
        foreach ((array) ($m['attachments'] ?? []) as $a) {
            $atts[] = ['id' => (int) ($a['id'] ?? 0), 'kind' => (string) ($a['kind'] ?? 'file'), 'locked' => true];
        }

        return [
            'id'         => (int) $m['id'],
            'role'       => $m['role'] ?? null,
            'status'     => ($m['status'] ?? null) === 'error' ? 'error' : null,
            'created_at' => $m['created_at'] ?? null,
            'is_deleted' => (bool) ($m['is_deleted'] ?? false),
            'locked'     => true,
            'cipher'     => self::cipher('a' . $m['id']),
            'attachments' => $atts,
        ];
    }

    /** محاولة محجوبة (content_safety_events): نسيب مين/إمتى/النوع/التصنيف/المكان ونخفي الكلمة المطابقة والعينة واسم الملف. */
    public function maskBlockedEvent(array $e): array
    {
        foreach (['detail', 'sample', 'file_name', 'file_mime'] as $k) {
            if (array_key_exists($k, $e)) {
                $e[$k] = null;
            }
        }
        $e['file_size'] = null;
        $e['can_allow'] = false;
        $e['locked'] = true;
        $e['cipher'] = self::cipher('b' . (int) ($e['id'] ?? 0));

        return $e;
    }

    /** هل الأدمن ده عنده تصريح ساري للنطاق ده بيغطي يوم الإنشاء ده؟ (أي خطأ في قراءة التصاريح = مقفول). */
    public function canRead(int $adminId, string $scope, ?string $createdAt, ?int $conversationId = null): bool
    {
        try {
            return $this->covers($this->activeGrants($adminId, $scope, $conversationId), $createdAt);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ helpers

    private function shapeGrant(array $g): array
    {
        return [
            'id'              => (int) $g['id'],
            'scope'           => $g['scope'],
            'conversation_id' => $g['conversation_id'] !== null ? (int) $g['conversation_id'] : null,
            'date_from'       => substr((string) $g['date_from'], 0, 10),
            'date_to'         => substr((string) $g['date_to'], 0, 10),
            'reason'          => $g['reason'],
            'expires_at'      => (string) $g['expires_at'],
            'seconds_left'    => max(0, Carbon::parse($g['expires_at'])->timestamp - now()->timestamp),
        ];
    }

    private function parseDate($v): ?Carbon
    {
        $v = trim((string) $v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $v);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
