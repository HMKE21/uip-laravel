<?php

namespace App\Services;

use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * المكالمات 1:1 جوّه المحادثات المباشرة (صوت أولًا؛ الفيديو متقفل بـ config('calls.video_enabled') لحد المرحلة 2).
 *
 * الصوت/الصورة بيعدي P2P (WebRTC)، ولارافيل بيشتغل signaling بس: جدول calls (الحالة) + call_signals
 * (offer/answer/ice) بيتقروا بـ polling (GET /calls/poll). الشات عندنا أصلًا polling، فمفيش داعي لـ websocket.
 *
 * دورة الحياة:  ringing ──accept──▶ active ──end/stale──▶ ended
 *                  ├─decline─▶ declined   ├─caller end─▶ cancelled   ├─مفيش رد ring_seconds─▶ missed   └─المستلم مشغول─▶ busy
 * كل انتقال بيتم بـ UPDATE مشروط (WHERE status IN (...)) فأول واحد بس ينجح — سباق قبول/رفض/إنهاء مش بيكرر السجل أو الإشعار.
 *
 * "تنضيف كسول": أي poll/start بيقفل المكالمات المعلّقة (رنّة عدّت المدة، أو طرف مبعتش نبضة stale_seconds) —
 * مفيش cron مطلوب لصحة السلوك (calls:prune تنضيف تخزين بس).
 *
 * كل مكالمة بتسيب رسالة سجل في الشات (message_type='call' + metadata) ومكالمة فايتة بتبعت إشعار للمستلم.
 * مفيش أي سلوك هنا بيكسر لو الإشعار/السجل فشل — دول تحسينات حول المكالمة، مش المكالمة نفسها.
 */
class CallService
{
    public const OPEN = ['ringing', 'active'];
    public const SIGNAL_KINDS = ['offer', 'answer', 'ice', 'state', 'resume'];

    public function __construct(
        private ChatSafetyService $safety,
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private CallIceService $ice
    ) {
    }

    // ------------------------------------------------------------------ config / ice

    /** @return array<string,mixed> */
    public function iceConfig(int $userId): array
    {
        $this->assertEnabled();
        $r = $this->ice->forUser($userId);
        return [
            'ice_servers'   => $r['ice_servers'],
            'ttl'           => $r['ttl'],
            'turn'          => $r['provider'] !== 'none',
            'video_enabled' => (bool) config('calls.video_enabled', false),
            'ring_seconds'  => (int) config('calls.ring_seconds', 45),
        ];
    }

    // ------------------------------------------------------------------ start

    /** @return array<string,mixed> المكالمة (من منظور المتصل) — ممكن تكون status=busy لو المستلم في مكالمة تانية. */
    public function start(int $userId, int $conversationId, string $type, string $clientId): array
    {
        $this->assertEnabled();
        $type = $type === 'video' ? 'video' : 'audio';
        if ($type === 'video' && !config('calls.video_enabled', false)) {
            throw new \RuntimeException('Video calls are not available yet.');
        }
        $clientId = $this->cleanClient($clientId);

        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $peerId = $this->safety->directPeerId($conversationId, $userId);
        if (!$peerId) {
            throw new \RuntimeException('Calls are available in direct chats only.');
        }
        $peer = $this->user($peerId);
        if (!$peer || !empty($peer->deleted_at)) {
            throw new \RuntimeException('This account no longer exists.');
        }
        if ($this->safety->isBlockedEitherWay($userId, $peerId)) {
            throw new \RuntimeException('You cannot call this user.');
        }

        // حد بدء المكالمات في الدقيقة (حماية من إزعاج/رنّات متكررة).
        $key = 'calls_start_' . $userId . '_' . (int) floor(time() / 60);
        $n = (int) Cache::get($key, 0);
        if ($n >= (int) config('calls.start_per_minute', 8)) {
            throw new \RuntimeException('Too many call attempts. Please wait a moment.');
        }
        Cache::put($key, $n + 1, 70);

        $this->reapFor($userId);
        $this->reapFor($peerId);

        // الطرف التاني بيرن عليّا حاليًا → مفيش داعي لمكالمة تانية؛ الفرونت يعرض له "رد".
        $incoming = DB::table('calls')->where('caller_id', $peerId)->where('callee_id', $userId)->where('status', 'ringing')->first();
        if ($incoming) {
            throw new \RuntimeException('This person is already calling you.');
        }
        if ($this->busyCaller($userId)) {
            throw new \RuntimeException('You are already in a call.');
        }

        $now = now();
        $busy = $this->busyCallee($peerId);
        $id = (int) DB::table('calls')->insertGetId([
            'conversation_id' => $conversationId,
            'caller_id'       => $userId,
            'callee_id'       => $peerId,
            'type'            => $type,
            'status'          => 'ringing',
            'caller_client'   => $clientId,
            'created_at'      => $now,
            'caller_seen_at'  => $now,
        ]);

        if ($busy) {
            $this->close($this->find($id), 'busy', 'busy');
        }
        return $this->present($this->find($id), $userId);
    }

    // ------------------------------------------------------------------ accept / decline / end

    /** @return array<string,mixed> */
    public function accept(int $userId, int $callId, string $clientId): array
    {
        $call = $this->mine($userId, $callId);
        if ((int) $call->callee_id !== $userId) {
            throw new \RuntimeException('Only the person being called can answer.');
        }
        $call = $this->reap($call);
        $now = now();
        $ok = DB::table('calls')->where('id', $callId)->where('status', 'ringing')->update([
            'status'         => 'active',
            'answered_at'    => $now,
            'callee_client'  => $this->cleanClient($clientId),
            'callee_seen_at' => $now,
        ]);
        if (!$ok) {
            throw new \RuntimeException('This call is no longer available.');
        }
        return $this->present($this->find($callId), $userId);
    }

    /**
     * استئناف مكالمة شغّالة بعد ريفريش/إعادة تحميل الصفحة: الصفحة الجديدة ليها client_id جديد، فبنربط المكالمة بيه (عشان
     * الـ poll والـ signal يقبلوه) ونسجّل نبضة. شغّال بس للمكالمة اللي status بتاعها active (اللي لسه بترن بتتلغي زي الأول).
     * بيرجّع بيانات المكالمة + after_signal_id = آخر إشارة موجّهة لي، عشان الصفحة الجديدة تتجاهل إشارات الاتصال القديم.
     *
     * @return array<string,mixed>
     */
    public function resume(int $userId, int $callId, string $clientId): array
    {
        $this->assertEnabled();
        $clientId = $this->cleanClient($clientId);
        $call = $this->mine($userId, $callId);
        $call = $this->reap($call);
        if ($call->status !== 'active') {
            throw new \RuntimeException('This call has ended.');
        }
        $isCaller = (int) $call->caller_id === $userId;
        DB::table('calls')->where('id', $callId)->where('status', 'active')->update([
            $isCaller ? 'caller_client' : 'callee_client'     => $clientId,
            $isCaller ? 'caller_seen_at' : 'callee_seen_at'   => now(),
        ]);
        $after = (int) DB::table('call_signals')->where('call_id', $callId)->where('to_user_id', $userId)->max('id');
        $out = $this->present($this->find($callId), $userId);
        $out['after_signal_id'] = $after;
        return $out;
    }

    /** @return array<string,mixed> */
    public function decline(int $userId, int $callId): array
    {
        $call = $this->mine($userId, $callId);
        if ((int) $call->callee_id !== $userId) {
            throw new \RuntimeException('Only the person being called can decline.');
        }
        $this->close($call, 'declined', 'declined');
        return $this->present($this->find($callId), $userId);
    }

    /** إنهاء: المتصل قبل الرد = cancelled، المستلم قبل الرد = declined، بعد الرد = ended. idempotent. */
    public function end(int $userId, int $callId, string $reason = 'hangup'): array
    {
        $call = $this->mine($userId, $callId);
        $call = $this->reap($call);
        if ($call->status === 'ringing') {
            if ((int) $call->caller_id === $userId) {
                $this->close($call, 'cancelled', 'cancelled');
            } else {
                $this->close($call, 'declined', 'declined');
            }
        } elseif ($call->status === 'active') {
            $this->close($call, 'ended', in_array($reason, ['hangup', 'failed', 'connection_lost'], true) ? $reason : 'hangup');
        }
        return $this->present($this->find($callId), $userId);
    }

    // ------------------------------------------------------------------ signaling

    /** @param mixed $payload  @return array{id:int} */
    public function signal(int $userId, int $callId, string $clientId, string $kind, $payload): array
    {
        if (!in_array($kind, self::SIGNAL_KINDS, true)) {
            throw new \InvalidArgumentException('Unknown signal kind.');
        }
        $call = $this->mine($userId, $callId);
        $call = $this->reap($call);
        if (!in_array($call->status, self::OPEN, true)) {
            throw new \RuntimeException('This call has ended.');
        }
        $isCaller = (int) $call->caller_id === $userId;
        $clientId = $this->cleanClient($clientId);
        if (!$isCaller && $call->status !== 'active') {
            throw new \RuntimeException('Answer the call first.');
        }
        if (($isCaller ? $call->caller_client : $call->callee_client) !== $clientId) {
            throw new \RuntimeException('This call is handled in another window.');
        }

        $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $json === null) {
            throw new \InvalidArgumentException('Invalid signal payload.');
        }
        if (strlen($json) > (int) config('calls.max_signal_bytes', 24000)) {
            throw new \InvalidArgumentException('Signal payload too large.');
        }
        if (DB::table('call_signals')->where('call_id', $callId)->count() >= (int) config('calls.max_signals_per_call', 600)) {
            throw new \RuntimeException('Too many signals for this call.');
        }

        $id = (int) DB::table('call_signals')->insertGetId([
            'call_id'      => $callId,
            'from_user_id' => $userId,
            'to_user_id'   => $isCaller ? (int) $call->callee_id : (int) $call->caller_id,
            'kind'         => $kind,
            'payload'      => $json,
            'created_at'   => now(),
        ]);
        return ['id' => $id];
    }

    /**
     * الـ polling الوحيد اللي الفرونت محتاجه: بيرجّع المكالمات الواردة + حالة مكالمتي الحالية + إشارات جديدة،
     * وبيسجّل نبضة (heartbeat) لو call_id متبعت.
     *
     * @return array{server_time:string,incoming:array,call:?array,signals:array}
     */
    public function poll(int $userId, string $clientId, ?int $callId, int $afterSignalId): array
    {
        $this->assertEnabled();
        $clientId = $this->cleanClient($clientId);
        $this->reapFor($userId);

        $callOut = null;
        $signals = [];
        if ($callId) {
            $call = DB::table('calls')->where('id', $callId)
                ->where(function ($q) use ($userId) {
                    $q->where('caller_id', $userId)->orWhere('callee_id', $userId);
                })->first();
            if ($call) {
                $isCaller = (int) $call->caller_id === $userId;
                $mine = ($isCaller ? $call->caller_client : $call->callee_client) === $clientId;
                if ($mine && in_array($call->status, self::OPEN, true)) {
                    DB::table('calls')->where('id', $callId)->whereIn('status', self::OPEN)
                        ->update([$isCaller ? 'caller_seen_at' : 'callee_seen_at' => now()]);
                }
                $callOut = $this->present($call, $userId);
                // مكالمة اتردّ عليها من تبويب/جهاز تاني بتاعي → الفرونت يقفل الرنّة هنا.
                $callOut['handled_elsewhere'] = !$isCaller && $call->status === 'active' && $call->callee_client !== $clientId;
                if ($mine) {
                    $signals = DB::table('call_signals')
                        ->where('call_id', $callId)->where('to_user_id', $userId)->where('id', '>', $afterSignalId)
                        ->orderBy('id')->limit(100)->get()
                        ->map(fn ($s) => [
                            'id'      => (int) $s->id,
                            'kind'    => (string) $s->kind,
                            'payload' => json_decode((string) $s->payload, true),
                            'from'    => (int) $s->from_user_id,
                        ])->all();
                }
            }
        }

        $incoming = [];
        $rows = DB::table('calls')->where('callee_id', $userId)->where('status', 'ringing')
            ->where('created_at', '>', now()->subSeconds((int) config('calls.ring_seconds', 45)))
            ->orderBy('id', 'desc')->limit(3)->get();
        foreach ($rows as $r) {
            $incoming[] = $this->present($r, $userId);
        }

        return [
            'server_time' => now()->toIso8601String(),
            'incoming'    => $incoming,
            'call'        => $callOut,
            'signals'     => $signals,
        ];
    }

    // ------------------------------------------------------------------ maintenance

    /** calls:prune — بيمسح المكالمات (والإشارات بالـ cascade) الأقدم من prune_days، وبيقفل أي معلّق قديم. @return int عدد المحذوف */
    public function prune(): int
    {
        $stale = DB::table('calls')->whereIn('status', self::OPEN)->where('created_at', '<', now()->subHours(6))->get();
        foreach ($stale as $c) {
            $this->reap($c);
        }
        $cut = now()->subDays(max(1, (int) config('calls.prune_days', 14)));
        $ids = DB::table('calls')->whereNotIn('status', self::OPEN)->where('created_at', '<', $cut)->pluck('id')->all();
        if (!$ids) {
            return 0;
        }
        DB::table('call_signals')->whereIn('call_id', $ids)->delete();
        DB::table('calls')->whereIn('id', $ids)->delete();
        return count($ids);
    }

    // ------------------------------------------------------------------ internals

    private function assertEnabled(): void
    {
        if (!config('calls.enabled', true)) {
            throw new \RuntimeException('Calls are disabled.');
        }
    }

    private function cleanClient(string $clientId): string
    {
        $c = preg_replace('/[^A-Za-z0-9_\-]/', '', $clientId) ?? '';
        if ($c === '') {
            throw new \InvalidArgumentException('client_id is required.');
        }
        return substr($c, 0, 64);
    }

    private function find(int $id): ?object
    {
        return DB::table('calls')->where('id', $id)->first();
    }

    /** المكالمة لو أنا طرف فيها، وإلا "not found" (مفيش تسريب لوجود مكالمات الناس التانية). */
    private function mine(int $userId, int $callId): object
    {
        $call = DB::table('calls')->where('id', $callId)
            ->where(function ($q) use ($userId) {
                $q->where('caller_id', $userId)->orWhere('callee_id', $userId);
            })->first();
        if (!$call) {
            throw new \RuntimeException('Call not found.');
        }
        return $call;
    }

    private function user(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first(['id', 'full_name', 'avatar_path', 'deleted_at']);
    }

    /** المتصل نفسه مشغول: عنده مكالمة شغالة، أو بيرن على حد (مكالمة صادرة لسه ringing). */
    private function busyCaller(int $userId): bool
    {
        return DB::table('calls')->where('status', 'active')
            ->where(function ($q) use ($userId) {
                $q->where('caller_id', $userId)->orWhere('callee_id', $userId);
            })->exists()
            || DB::table('calls')->where('status', 'ringing')->where('caller_id', $userId)->exists();
    }

    /** المستلم مشغول: في مكالمة شغالة، أو بيرن على حد تاني. */
    private function busyCallee(int $userId): bool
    {
        return $this->busyCaller($userId);
    }

    /** يقفل المعلّق من مكالمات مستخدم (رنّة عدّت / نبضة قديمة). */
    private function reapFor(int $userId): void
    {
        $calls = DB::table('calls')->whereIn('status', self::OPEN)
            ->where(function ($q) use ($userId) {
                $q->where('caller_id', $userId)->orWhere('callee_id', $userId);
            })->get();
        foreach ($calls as $c) {
            $this->reap($c);
        }
    }

    /** يقفل المكالمة لو انتهت صلاحيتها؛ بيرجّع الصف المحدّث. */
    private function reap(object $call): object
    {
        if (!in_array($call->status, self::OPEN, true)) {
            return $call;
        }
        $now = now()->getTimestamp();
        if ($call->status === 'ringing') {
            if ($now - Carbon::parse($call->created_at)->getTimestamp() > (int) config('calls.ring_seconds', 45)) {
                $this->close($call, 'missed', 'timeout');
            }
        } else {
            $stale = (int) config('calls.stale_seconds', 25);
            $fallback = $call->answered_at ?? $call->created_at;
            foreach (['caller_seen_at', 'callee_seen_at'] as $col) {
                $seen = Carbon::parse($call->{$col} ?: $fallback)->getTimestamp();
                if ($now - $seen > $stale) {
                    $this->close($call, 'ended', 'connection_lost');
                    break;
                }
            }
        }
        return $this->find((int) $call->id) ?? $call;
    }

    /**
     * يقفل المكالمة مرة واحدة بس (UPDATE مشروط)، وبعدها يسجّل رسالة الشات ويبعت إشعار المكالمة الفايتة.
     * @return bool true لو الاستدعاء ده هو اللي قفلها
     */
    private function close(?object $call, string $status, string $reason): bool
    {
        if (!$call) {
            return false;
        }
        $now = now();
        $duration = null;
        if ($status === 'ended' && $call->answered_at) {
            $duration = max(0, $now->getTimestamp() - Carbon::parse($call->answered_at)->getTimestamp());
        }
        $ok = DB::table('calls')->where('id', $call->id)->whereIn('status', self::OPEN)->update([
            'status'           => $status,
            'end_reason'       => $reason,
            'ended_at'         => $now,
            'duration_seconds' => $duration,
        ]);
        if (!$ok) {
            return false;
        }

        $outcome = match ($status) {
            'ended'     => $reason === 'failed' ? 'failed' : 'completed',
            'missed'    => 'missed',
            'cancelled' => 'cancelled',
            'declined'  => 'declined',
            'busy'      => 'busy',
            default     => 'failed',
        };
        $this->logMessage($call, $outcome, $duration);
        if (in_array($outcome, ['missed', 'cancelled', 'busy'], true)) {
            $this->notifyMissed($call);
        }
        return true;
    }

    private function logMessage(object $call, string $outcome, ?int $duration): void
    {
        try {
            $label = $call->type === 'video' ? 'video' : 'voice';
            $body = match ($outcome) {
                'completed' => ucfirst($label) . ' call (' . $this->fmtDuration((int) $duration) . ')',
                'declined'  => 'Declined ' . $label . ' call',
                'cancelled' => 'Cancelled ' . $label . ' call',
                'failed'    => 'Failed ' . $label . ' call',
                default     => 'Missed ' . $label . ' call',
            };
            $msg = $this->messages->create((int) $call->conversation_id, (int) $call->caller_id, $body, null, null, null, 'call', [
                'call_id'   => (int) $call->id,
                'call_type' => (string) $call->type,
                'outcome'   => $outcome,
                'duration'  => $duration,
                'caller_id' => (int) $call->caller_id,
                'callee_id' => (int) $call->callee_id,
            ]);
            $this->conversations->touchUpdatedAt((int) $call->conversation_id);
            DB::table('calls')->where('id', $call->id)->update(['log_message_id' => (int) $msg->id]);
        } catch (\Throwable $e) {
            Log::warning('Calls: failed to write call log message', ['call' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    protected function notifyMissed(object $call): void
    {
        try {
            $caller = $this->user((int) $call->caller_id);
            // نفس منطق MessagingService::messagesLinkFor (البريفيكس من home_route الخاص بدور المستلم).
            $role = app(RoleService::class)->primaryRoleFor((int) $call->callee_id);
            $home = (string) config("roles.home_route.{$role}", '');
            $prefix = $home !== '' ? explode('/', trim($home, '/'))[0] : str_replace('_', '-', (string) $role);
            app(NotificationService::class)->notify(
                (int) $call->callee_id,
                'missed_call_message',
                'Missed ' . ($call->type === 'video' ? 'video' : 'voice') . ' call from ' . ($caller->full_name ?? 'a user'),
                null,
                "/{$prefix}/messages/" . (int) $call->conversation_id
            );
        } catch (\Throwable $e) {
            // الإشعار تحسين؛ فشله ميكسرش المكالمة
        }
    }

    private function fmtDuration(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    /** @return array<string,mixed> شكل المكالمة من منظور $viewerId (peer = الطرف التاني). */
    private function present(object $call, int $viewerId): array
    {
        $isCaller = (int) $call->caller_id === $viewerId;
        $peerId = $isCaller ? (int) $call->callee_id : (int) $call->caller_id;
        $peer = $this->user($peerId);
        $expires = null;
        if ($call->status === 'ringing') {
            $expires = max(0, (int) config('calls.ring_seconds', 45) - (now()->getTimestamp() - Carbon::parse($call->created_at)->getTimestamp()));
        }
        return [
            'id'               => (int) $call->id,
            'conversation_id'  => (int) $call->conversation_id,
            'type'             => (string) $call->type,
            'status'           => (string) $call->status,
            'end_reason'       => $call->end_reason,
            'role'             => $isCaller ? 'caller' : 'callee',
            'peer'             => [
                'id'     => $peerId,
                'name'   => $peer->full_name ?? '',
                'avatar' => $peer ? $this->avatarFor($peer, $viewerId) : null,
            ],
            'created_at'       => Carbon::parse($call->created_at)->toIso8601String(),
            'answered_at'      => $call->answered_at ? Carbon::parse($call->answered_at)->toIso8601String() : null,
            'ended_at'         => $call->ended_at ? Carbon::parse($call->ended_at)->toIso8601String() : null,
            'duration_seconds' => $call->duration_seconds !== null ? (int) $call->duration_seconds : null,
            'expires_in'       => $expires,
        ];
    }

    /** نفس خصوصية الأفاتار بتاعة الشات (مخفي = null). أي خطأ => null (الأمان أهم من الصورة). */
    private function avatarFor(object $user, int $viewerId): ?string
    {
        if (empty($user->avatar_path)) {
            return null;
        }
        try {
            return app(MessagingService::class)->isAvatarHidden((int) $user->id, $viewerId) ? null : (string) $user->avatar_path;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
