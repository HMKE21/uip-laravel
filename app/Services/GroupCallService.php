<?php

namespace App\Services;

use App\Models\Meeting;
use App\Repositories\ConversationRepository;
use App\Repositories\MeetingRepository;
use App\Repositories\MessageRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Group voice / video calls inside group chats.
 *
 * A group call is an INSTANT MEETING attached to the conversation (attachable_type='conversation'), so everything the meeting
 * room already does comes for free: WebRTC mesh for small rooms, automatic switch to the SFU for big ones (Admin → Meetings
 * Control → Video: auto / always SFU / always mesh, switch-over size, ...), host controls, signalling.
 * This service only adds the chat side: start-or-join, ringing every member, one log card in the chat, and lazy cleanup.
 *
 * Model: ring everybody, join any time (WhatsApp style). One live call per group: starting again while one is live returns it.
 * The call closes by itself once nobody is in the room (checked lazily from the poll, no cron needed).
 * Only members of the conversation can enter (enforced in MeetingLobbyService::requestAccess).
 */
class GroupCallService
{
    public const TYPE = 'conversation';
    /** A call nobody is in for this long is closed (the starter opened it and walked away). */
    private const EMPTY_GRACE_SECONDS = 120;
    private const MAX_AGE_HOURS = 12;

    public function __construct(
        private MeetingService $meetingService,
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private MeetingPolicyService $policy
    ) {
    }

    // ------------------------------------------------------------------ public API

    /** Is the feature switched on (calls + meetings + group flag)? */
    public function enabled(): bool
    {
        return (bool) config('calls.enabled', true)
            && (bool) config('calls.group_enabled', true)
            && $this->policy->systemEnabled();
    }

    /**
     * Start a group call, or return the one already live in this group.
     *
     * @return array<string,mixed> { created: bool, call: {...} }
     */
    public function start(int $userId, int $conversationId, string $type): array
    {
        $this->assertEnabled();
        $type = $type === 'video' ? 'video' : 'audio';

        $conv = DB::table('conversations')->where('id', $conversationId)->first(['id', 'is_group', 'group_name']);
        if (!$conv || !$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        if (!$conv->is_group) {
            throw new \RuntimeException('Group calls are available in group chats only.');
        }

        // Spam guard: a handful of starts per minute per user is plenty.
        $key = 'groupcall:start:' . $userId;
        $n = (int) Cache::get($key, 0);
        if ($n >= (int) config('calls.start_per_minute', 8)) {
            throw new \RuntimeException('Too many call attempts. Please wait a moment.');
        }
        Cache::put($key, $n + 1, 60);

        // Two people pressing "call" at the same moment must end up in ONE call.
        $lock = Cache::lock('groupcall:lock:' . $conversationId, 10);
        try {
            $lock->block(5);
        } catch (\Throwable $e) {
            // couldn't get the lock in time: fall through, the live-call lookup below still protects us in the common case
        }
        try {
            $live = $this->findLive($conversationId);
            if ($live) {
                $this->ensureMember($live, $userId);
                return ['created' => false, 'call' => $this->present($live, $userId)];
            }

            $title = trim((string) ($conv->group_name ?? '')) !== '' ? (string) $conv->group_name : 'Group call';
            $meeting = $this->meetingService->create($userId, [
                'title'                => mb_substr($title, 0, 200),
                'type'                 => 'instant',
                'waiting_room_enabled' => false,
                'allow_guests'         => false,
                'duration_minutes'     => null,
                'settings'             => ['camera_on_entry' => $type === 'video', 'mute_on_entry' => false],
                'attachable_type'      => self::TYPE,
                'attachable_id'        => $conversationId,
            ]);
            // start() notifies existing participants (only the host so far, who is excluded) → no notification spam
            $meeting = $this->meetingService->start($meeting);

            $memberIds = array_map(fn ($m) => (int) $m['id'], $this->conversations->allParticipants($conversationId));
            foreach ($memberIds as $mid) {
                if ($mid === $userId) {
                    continue;
                }
                $this->ensureMember($meeting, $mid);
            }

            $this->postCard($meeting, $conversationId, $userId, $type);
            $this->ring($meeting, $conversationId, $userId, $type, $title, $memberIds);

            return ['created' => true, 'call' => $this->present($meeting, $userId, $type)];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // lock may have expired/never been acquired
            }
        }
    }

    /** The live call of this group (null when none) — lets the thread header show "Join" instead of "Call". */
    public function activeFor(int $userId, int $conversationId): ?array
    {
        if (!$this->enabled() || !$this->conversations->isParticipant($conversationId, $userId)) {
            return null;
        }
        $live = $this->findLive($conversationId);
        return $live ? $this->present($live, $userId) : null;
    }

    /**
     * Group calls to ring on this user's screen right now: live, started recently, in one of the user's groups,
     * not started by them and not already joined. Also the place where abandoned calls get closed lazily.
     *
     * @return array<int,array<string,mixed>>
     */
    public function incomingFor(int $userId): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $rows = DB::table('meetings as m')
            ->join('conversation_participants as cp', function ($j) use ($userId) {
                $j->on('cp.conversation_id', '=', 'm.attachable_id')->where('cp.user_id', '=', $userId);
            })
            ->where('m.attachable_type', self::TYPE)
            ->whereIn('m.status', ['live', 'scheduled'])
            ->orderByDesc('m.id')
            ->limit(5)
            ->get(['m.id']);

        $out = [];
        $ring = (int) config('calls.group_ring_seconds', 45);
        foreach ($rows as $r) {
            $meeting = $this->meetings->find($r->id);
            if (!$meeting || $this->reap($meeting)) {
                continue;
            }
            if ((int) $meeting->host_user_id === $userId) {
                continue;
            }
            $started = $meeting->started_at ? Carbon::parse($meeting->started_at) : null;
            if (!$started || $started->lt(now()->subSeconds($ring))) {
                continue; // still joinable from the chat card, but the phone stops ringing
            }
            $p = $this->meetings->findParticipant($meeting->id, $userId);
            if ($p && $p->status === 'joined') {
                continue;
            }
            $out[] = $this->present($meeting, $userId);
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    /** Called by MeetingService::end() so the chat card flips from "live" to "ended · duration". */
    public function onMeetingEnded(Meeting $meeting): void
    {
        if ($meeting->attachable_type !== self::TYPE) {
            return;
        }
        try {
            $this->finalizeCard($meeting);
            $members = array_map(fn ($m) => (int) $m['id'], $this->conversations->allParticipants((int) $meeting->attachable_id));
            \App\Support\Realtime::toUsers($members, 'messaging', ['conversation_id' => (int) $meeting->attachable_id]);
            \App\Support\Realtime::toUsers($members, 'call', ['group' => true, 'status' => 'ended', 'meeting_uuid' => $meeting->uuid]);
        } catch (\Throwable $e) {
            Log::warning('GroupCall: failed to finalize card', ['meeting' => $meeting->id, 'error' => $e->getMessage()]);
        }
    }

    /** True when the user belongs to the chat this meeting is attached to (also true for ordinary meetings: not our business). */
    public function userMayEnter(Meeting $meeting, int $userId): bool
    {
        if ($meeting->attachable_type !== self::TYPE) {
            return true;
        }
        return $this->conversations->isParticipant((int) $meeting->attachable_id, $userId);
    }

    // ------------------------------------------------------------------ internals

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('Group calls are not available right now.');
        }
    }

    private function findLive(int $conversationId): ?Meeting
    {
        $m = Meeting::where('attachable_type', self::TYPE)
            ->where('attachable_id', $conversationId)
            ->whereIn('status', ['live', 'scheduled'])
            ->orderByDesc('id')
            ->first();
        if (!$m) {
            return null;
        }
        return $this->reap($m) ? null : $m;
    }

    /** Close a call nobody is in any more (or that got too old). @return bool true when it was closed */
    private function reap(Meeting $meeting): bool
    {
        // the poll runs every 1-3 s per user: look at one call at most every 10 s
        $chk = 'groupcall:reapchk:' . $meeting->id;
        if (Cache::has($chk)) {
            return false;
        }
        Cache::put($chk, 1, 10);
        $started = $meeting->started_at ? Carbon::parse($meeting->started_at) : Carbon::parse($meeting->created_at);
        $tooOld = $started->lt(now()->subHours(self::MAX_AGE_HOURS));
        $empty = false;
        if (!$tooOld) {
            try {
                $present = count($this->signaling->roster($meeting));
            } catch (\Throwable $e) {
                return false; // can't tell → leave it alone
            }
            if ($present === 0) {
                $last = $started;
                foreach ($this->meetings->participantsFor($meeting->id) as $p) {
                    foreach ([$p->last_seen_at, $p->joined_at] as $ts) {
                        if ($ts && Carbon::parse($ts)->gt($last)) {
                            $last = Carbon::parse($ts);
                        }
                    }
                }
                $empty = $last->lt(now()->subSeconds(self::EMPTY_GRACE_SECONDS));
            }
        }
        if (!$tooOld && !$empty) {
            return false;
        }
        try {
            $this->meetingService->end($meeting); // → onMeetingEnded() finalizes the chat card
        } catch (\Throwable $e) {
            Log::warning('GroupCall: failed to end abandoned call', ['meeting' => $meeting->id, 'error' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    private function ensureMember(Meeting $meeting, int $userId): void
    {
        $p = $this->meetings->findParticipant($meeting->id, $userId);
        if ($p) {
            if (in_array($p->status, ['removed', 'declined'], true)) {
                $p->status = 'invited';
                $p->save();
            }
            return;
        }
        $this->meetings->addParticipant([
            'meeting_id'         => $meeting->id,
            'user_id'            => $userId,
            'role'               => 'participant',
            'status'             => 'invited',
            'invited_by_user_id' => (int) $meeting->host_user_id,
        ]);
    }

    private function callType(Meeting $meeting): string
    {
        return !empty($meeting->settings['camera_on_entry']) ? 'video' : 'audio';
    }

    /** @return array<string,mixed> */
    private function present(Meeting $meeting, int $viewerId, ?string $type = null): array
    {
        $host = DB::table('users')->where('id', $meeting->host_user_id)->first(['id', 'full_name']);
        $present = 0;
        try {
            $present = count($this->signaling->roster($meeting));
        } catch (\Throwable $e) {
            // count is cosmetic
        }
        $started = $meeting->started_at ? Carbon::parse($meeting->started_at) : null;
        $ring = (int) config('calls.group_ring_seconds', 45);
        return [
            'meeting_uuid'    => (string) $meeting->uuid,
            'join_token'      => (string) $meeting->join_token,
            'conversation_id' => (int) $meeting->attachable_id,
            'title'           => (string) $meeting->title,
            'type'            => $type ?? $this->callType($meeting),
            'started_by'      => ['id' => (int) ($host->id ?? 0), 'name' => (string) ($host->full_name ?? '')],
            'started_at'      => $started ? $started->toIso8601String() : null,
            'participants'    => $present,
            'expires_in'      => $started ? max(0, $ring - (now()->getTimestamp() - $started->getTimestamp())) : 0,
            'is_host'         => (int) $meeting->host_user_id === $viewerId,
        ];
    }

    private function postCard(Meeting $meeting, int $conversationId, int $starterId, string $type): void
    {
        try {
            $label = $type === 'video' ? 'Group video call' : 'Group voice call';
            $msg = $this->messages->create($conversationId, $starterId, $label, null, null, null, 'call', [
                'group'        => true,
                'call_type'    => $type,
                'outcome'      => 'live',
                'meeting_uuid' => (string) $meeting->uuid,
                'join_token'   => (string) $meeting->join_token,
                'caller_id'    => $starterId,
                'started_at'   => now()->toIso8601String(),
                'duration'     => null,
            ]);
            $this->conversations->touchUpdatedAt($conversationId);
            Cache::put('groupcall:msg:' . $meeting->id, (int) $msg->id, now()->addHours(self::MAX_AGE_HOURS + 1));
        } catch (\Throwable $e) {
            Log::warning('GroupCall: failed to write chat card', ['meeting' => $meeting->id, 'error' => $e->getMessage()]);
        }
    }

    private function finalizeCard(Meeting $meeting): void
    {
        $conversationId = (int) $meeting->attachable_id;
        $msgId = (int) Cache::get('groupcall:msg:' . $meeting->id, 0);
        $q = DB::table('messages')->where('conversation_id', $conversationId)->where('message_type', 'call');
        if ($msgId) {
            $q->where('id', $msgId);
        } else {
            $q->where('metadata', 'like', '%"meeting_uuid":"' . $meeting->uuid . '"%');
        }
        $row = $q->orderByDesc('id')->first(['id', 'metadata']);
        if (!$row) {
            return;
        }
        $meta = json_decode((string) $row->metadata, true) ?: [];
        if (empty($meta['group'])) {
            return;
        }
        $started = $meeting->started_at ? Carbon::parse($meeting->started_at) : null;
        $ended = $meeting->ended_at ? Carbon::parse($meeting->ended_at) : now();
        $duration = $started ? max(0, $ended->getTimestamp() - $started->getTimestamp()) : 0;
        $meta['outcome'] = 'completed';
        $meta['duration'] = $duration;
        $meta['ended_at'] = $ended->toIso8601String();
        $label = ($meta['call_type'] ?? 'audio') === 'video' ? 'Group video call' : 'Group voice call';
        DB::table('messages')->where('id', $row->id)->update([
            'body'     => $label . ' (' . $this->fmtDuration($duration) . ')',
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
        Cache::forget('groupcall:msg:' . $meeting->id);
    }

    /** @param array<int,int> $memberIds */
    private function ring(Meeting $meeting, int $conversationId, int $starterId, string $type, string $title, array $memberIds): void
    {
        $others = array_values(array_filter($memberIds, fn ($id) => (int) $id !== $starterId));
        if (!$others) {
            return;
        }
        try {
            \App\Support\Realtime::toUsers($others, 'call', ['group' => true, 'status' => 'ringing', 'meeting_uuid' => (string) $meeting->uuid]);
            \App\Support\Realtime::toUsers(array_merge($others, [$starterId]), 'messaging', ['conversation_id' => $conversationId]);
        } catch (\Throwable $e) {
            // realtime is an optimisation: the poll still delivers the ring
        }
        try {
            $starter = (string) (DB::table('users')->where('id', $starterId)->value('full_name') ?? '');
            $notifier = app(NotificationService::class);
            $label = $type === 'video' ? 'Group video call' : 'Group voice call';
            foreach ($others as $uid) {
                $notifier->notify((int) $uid, 'group_call', $label . ': ' . $title, $starter !== '' ? $starter . ' started a call' : null, $this->messagesLink((int) $uid, $conversationId), 'high');
            }
        } catch (\Throwable $e) {
            Log::warning('GroupCall: notify failed', ['meeting' => $meeting->id, 'error' => $e->getMessage()]);
        }
    }

    private function messagesLink(int $userId, int $conversationId): ?string
    {
        try {
            $role = app(RoleService::class)->primaryRoleFor($userId);
            $home = (string) config("roles.home_route.{$role}", '');
            $prefix = $home !== '' ? explode('/', trim($home, '/'))[0] : str_replace('_', '-', (string) $role);
            return "/{$prefix}/messages/{$conversationId}";
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fmtDuration(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
