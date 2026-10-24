<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingChatMessage;
use App\Models\MeetingFile;
use App\Models\MeetingInvitation;
use App\Models\MeetingJoinRequest;
use App\Models\MeetingParticipant;
use App\Models\MeetingPoll;
use App\Models\MeetingRecording;
use App\Repositories\MeetingRepository;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Meetings Control. منطق الأدمن على مستوى المنصة كلها: قايمة الاجتماعات،
 * تفاصيل اجتماع، إجراءات إجبارية (إنهاء/إلغاء/حذف/قفل/طرد/كتم ...)، ومراقبة المحتوى
 * المرفوع (ملفات/تسجيلات) بالـ metadata بس.
 *
 * الخصوصية (بند 39 في الـ spec: "Admins should NOT automatically have access to
 * private meeting content"): الخدمة دي عمدًا **مش** بترجّع نص الشات ولا نص الملاحظات
 * ولا بتحمّل ملفات/تسجيلات. الأدمن يقدر يشوف إن فيه ملف/تسجيل (الاسم/الحجم/مين رفعه)
 * ويحذفه، لكن مش يقراه.
 */
class MeetingAdminService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingService $meetingService,
        private MeetingHostControlService $hostControl,
        private MeetingSignalingService $signaling,
        private MeetingChatService $chat,
        private FileUploadService $uploads
    ) {
    }

    // ------------------------------------------------------------------
    // نظرة عامة
    // ------------------------------------------------------------------

    public function overview(): array
    {
        $now = now();

        $byStatus = Meeting::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all();

        $liveParticipants = MeetingParticipant::where('status', 'joined')->where('connection_state', 'connected')->count()
            + MeetingJoinRequest::whereNull('user_id')->where('status', 'admitted')->where('connection_state', 'connected')->count();

        $fileCount = MeetingFile::count();
        $fileBytes = (int) MeetingFile::sum('size_bytes');

        return [
            'system_enabled'      => (bool) MeetingAdminSettings::get('feature_meetings'),
            'maintenance_message' => (string) MeetingAdminSettings::get('maintenance_message'),
            'features'            => MeetingAdminSettings::featureMap(),
            'meetings_by_status'  => [
                'scheduled' => (int) ($byStatus['scheduled'] ?? 0),
                'lobby'     => (int) ($byStatus['lobby'] ?? 0),
                'live'      => (int) ($byStatus['live'] ?? 0),
                'ended'     => (int) ($byStatus['ended'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            ],
            'deleted_meetings'    => Meeting::onlyTrashed()->count(),
            'live_participants'   => $liveParticipants,
            'upcoming_24h'        => Meeting::where('status', 'scheduled')->whereBetween('scheduled_start_at', [$now, $now->copy()->addDay()])->count(),
            'pending_join_requests' => MeetingJoinRequest::where('status', 'pending')->count(),
            'files'               => ['count' => $fileCount, 'bytes' => $fileBytes],
            'chat_messages_today' => MeetingChatMessage::where('type', '!=', 'system')->where('created_at', '>=', $now->copy()->startOfDay())->count(),
            'blocked_users'       => count((array) MeetingAdminSettings::get('blocked_user_ids')),
            'monitoring'          => app(MeetingAnalyticsService::class)->platformMonitoring(),
        ];
    }

    // ------------------------------------------------------------------
    // قايمة الاجتماعات
    // ------------------------------------------------------------------

    /**
     * @param array $f status?, type?, q?, host_id?, from?, to?, sort?, dir?, page?, per_page?
     * @return array{items:array,meta:array}
     */
    public function list(array $f): array
    {
        $status = $f['status'] ?? null;
        $q = $status === 'deleted' ? Meeting::onlyTrashed() : Meeting::query();
        $q->with('host:id,full_name,email');

        if ($status && $status !== 'deleted' && $status !== 'all') {
            $q->where('status', $status);
        }
        if (!empty($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (!empty($f['host_id'])) {
            $q->where('host_user_id', (int) $f['host_id']);
        }
        if (!empty($f['from'])) {
            $q->where('created_at', '>=', $f['from']);
        }
        if (!empty($f['to'])) {
            $q->where('created_at', '<=', $f['to'] . ' 23:59:59');
        }
        if (!empty($f['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $f['q'])) . '%';
            $q->where(function ($w) use ($term) {
                $w->where('title', 'like', $term)
                  ->orWhere('meeting_code', 'like', $term)
                  ->orWhere('uuid', 'like', $term)
                  ->orWhereHas('host', fn ($h) => $h->where('full_name', 'like', $term)->orWhere('email', 'like', $term));
            });
        }

        $sortable = ['created_at', 'scheduled_start_at', 'started_at', 'title', 'status'];
        $sort = in_array($f['sort'] ?? '', $sortable, true) ? $f['sort'] : 'created_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $dir)->orderByDesc('id');

        $perPage = max(5, min(100, (int) ($f['per_page'] ?? 20)));
        $page = $q->paginate($perPage, ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $ids = $page->pluck('id')->all();
        $live = $this->liveCounts($ids);

        return [
            'items' => $page->getCollection()->map(fn (Meeting $m) => $this->row($m, $live[$m->id] ?? 0))->all(),
            'meta'  => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<int,int> meeting_id => عدد الحاضرين دلوقتي */
    private function liveCounts(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (MeetingParticipant::whereIn('meeting_id', $ids)->where('status', 'joined')->where('connection_state', 'connected')
            ->select('meeting_id', DB::raw('count(*) c'))->groupBy('meeting_id')->get() as $r) {
            $out[$r->meeting_id] = ($out[$r->meeting_id] ?? 0) + (int) $r->c;
        }
        foreach (MeetingJoinRequest::whereIn('meeting_id', $ids)->whereNull('user_id')->where('status', 'admitted')->where('connection_state', 'connected')
            ->select('meeting_id', DB::raw('count(*) c'))->groupBy('meeting_id')->get() as $r) {
            $out[$r->meeting_id] = ($out[$r->meeting_id] ?? 0) + (int) $r->c;
        }

        return $out;
    }

    private function row(Meeting $m, int $liveCount = 0): array
    {
        return [
            'uuid'                  => $m->uuid,
            'title'                 => $m->title,
            'description'           => $m->description,
            'meeting_code'          => $m->meeting_code,
            'type'                  => $m->type,
            'status'                => $m->status,
            'host'                  => $m->host ? ['id' => (int) $m->host->id, 'name' => $m->host->full_name, 'email' => $m->host->email] : null,
            'scheduled_start_at'    => $m->scheduled_start_at,
            'duration_minutes'      => $m->duration_minutes,
            'started_at'            => $m->started_at,
            'ended_at'              => $m->ended_at,
            'cancelled_at'          => $m->cancelled_at,
            'created_at'            => $m->created_at,
            'deleted_at'            => $m->deleted_at,
            'live_participants'     => $liveCount,
            'max_participants'      => $m->max_participants,
            'waiting_room_enabled'  => (bool) $m->waiting_room_enabled,
            'allow_guests'          => (bool) $m->allow_guests,
            'has_password'          => $m->hasPassword(),
            'locked'                => (bool) $m->locked,
            'screen_sharing_locked' => (bool) $m->screen_sharing_locked,
            'settings'              => $m->settings ?? [],
            'attachable_type'       => $m->attachable_type,
            'attachable_id'         => $m->attachable_id,
        ];
    }

    // ------------------------------------------------------------------
    // تفاصيل اجتماع
    // ------------------------------------------------------------------

    public function find(string $uuid, bool $withTrashed = true): ?Meeting
    {
        $q = $withTrashed ? Meeting::withTrashed() : Meeting::query();

        return $q->where('uuid', $uuid)->first();
    }

    public function details(Meeting $m): array
    {
        $m->loadMissing('host:id,full_name,email');

        $participants = MeetingParticipant::with('user:id,full_name,email')->where('meeting_id', $m->id)->orderBy('id')->get()
            ->map(fn (MeetingParticipant $p) => [
                'key'                  => 'user:' . $p->user_id,
                'type'                 => 'user',
                'user_id'              => (int) $p->user_id,
                'name'                 => $p->user->full_name ?? ('User #' . $p->user_id),
                'email'                => $p->user->email ?? null,
                'role'                 => $p->role,
                'status'               => $p->status,
                'joined_at'            => $p->joined_at,
                'left_at'              => $p->left_at,
                'mic_enabled'          => (bool) $p->mic_enabled,
                'camera_enabled'       => (bool) $p->camera_enabled,
                'screen_sharing'       => (bool) $p->screen_sharing,
                'hand_raised'          => (bool) $p->hand_raised,
                'screen_share_allowed' => $p->screen_share_allowed,
                'connection_state'     => $p->connection_state,
                'last_seen_at'         => $p->last_seen_at,
            ])->all();

        $guests = MeetingJoinRequest::where('meeting_id', $m->id)->whereNull('user_id')->orderBy('id')->get()
            ->map(fn (MeetingJoinRequest $g) => [
                'key'                  => 'guest:' . $g->id,
                'type'                 => 'guest',
                'user_id'              => null,
                'name'                 => (string) $g->guest_name,
                'email'                => null,
                'role'                 => 'guest',
                'status'               => $g->status,
                'joined_at'            => $g->decided_at,
                'left_at'              => $g->left_at,
                'mic_enabled'          => (bool) $g->mic_enabled,
                'camera_enabled'       => (bool) $g->camera_enabled,
                'screen_sharing'       => (bool) $g->screen_sharing,
                'hand_raised'          => (bool) $g->hand_raised,
                'screen_share_allowed' => $g->screen_share_allowed,
                'connection_state'     => $g->connection_state,
                'last_seen_at'         => $g->last_seen_at,
            ])->all();

        $files = MeetingFile::where('meeting_id', $m->id)->orderByDesc('id')->get()
            ->map(fn (MeetingFile $f) => [
                'id' => (int) $f->id, 'name' => $f->original_name, 'extension' => $f->extension, 'mime_type' => $f->mime_type,
                'size_bytes' => (int) $f->size_bytes, 'uploaded_by' => $f->uploader_display_name, 'created_at' => $f->created_at,
            ])->all();

        $recordings = MeetingRecording::where('meeting_id', $m->id)->orderByDesc('id')->get()
            ->map(fn (MeetingRecording $r) => [
                'id' => (int) $r->id, 'kind' => $r->kind, 'status' => $r->status, 'started_by' => $r->started_by_display_name,
                'started_at' => $r->started_at, 'stopped_at' => $r->stopped_at, 'duration_seconds' => $r->duration_seconds,
                'size_bytes' => (int) $r->size_bytes, 'name' => $r->original_name,
            ])->all();

        $polls = MeetingPoll::withCount('options')->where('meeting_id', $m->id)->orderByDesc('id')->get()
            ->map(fn (MeetingPoll $p) => [
                'id' => (int) $p->id, 'question' => $p->question, 'status' => $p->status, 'poll_type' => $p->poll_type,
                'is_anonymous' => (bool) $p->is_anonymous, 'options' => (int) $p->options_count, 'votes' => (int) $p->votes()->count(),
            ])->all();

        $invitations = MeetingInvitation::where('meeting_id', $m->id)->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status')->all();

        $audit = AuditLog::where('subject_type', 'Meeting')->where('subject_id', $m->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditLog $a) => $this->auditRow($a))->all();

        $live = $this->liveCounts([$m->id]);

        return [
            'meeting'      => $this->row($m, $live[$m->id] ?? 0),
            'participants' => $participants,
            'guests'       => $guests,
            'counts'       => [
                'chat_messages'    => MeetingChatMessage::where('meeting_id', $m->id)->where('type', '!=', 'system')->count(),
                'action_items'     => MeetingActionItem::where('meeting_id', $m->id)->count(),
                'pending_requests' => MeetingJoinRequest::where('meeting_id', $m->id)->where('status', 'pending')->count(),
                'invitations'      => $invitations,
            ],
            'files'        => $files,
            'recordings'   => $recordings,
            'polls'        => $polls,
            'audit'        => $audit,
        ];
    }

    private function auditRow(AuditLog $a): array
    {
        return [
            'id' => (int) $a->id, 'user_id' => $a->user_id, 'action' => $a->action, 'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id, 'old_values' => $a->old_values, 'new_values' => $a->new_values,
            'ip_address' => $a->ip_address, 'created_at' => $a->created_at,
        ];
    }

    /** @return array{items:array,meta:array} سجل نشاط كل الميتنج (action يبدأ بـ meetings. أو admin.meetings.) */
    public function audit(array $f): array
    {
        $q = AuditLog::where(fn ($w) => $w->where('action', 'like', 'meetings.%')->orWhere('action', 'like', 'admin.meetings.%'));
        if (!empty($f['action'])) {
            $q->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['action']) . '%');
        }
        if (!empty($f['user_id'])) {
            $q->where('user_id', (int) $f['user_id']);
        }
        $q->orderByDesc('id');
        $perPage = max(10, min(100, (int) ($f['per_page'] ?? 30)));
        $page = $q->paginate($perPage, ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));

        $userIds = $page->pluck('user_id')->filter()->unique()->all();
        $names = $userIds ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id')->all() : [];

        return [
            'items' => $page->getCollection()->map(fn (AuditLog $a) => $this->auditRow($a) + ['user_name' => $names[$a->user_id] ?? null])->all(),
            'meta'  => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    // ------------------------------------------------------------------
    // تعديل / إجراءات إجبارية
    // ------------------------------------------------------------------

    /** @param array $d title, description, scheduled_start_at, duration_minutes, max_participants, waiting_room_enabled, allow_guests, settings, password, clear_password */
    public function update(Meeting $m, array $d): Meeting
    {
        $fields = array_intersect_key($d, array_flip(['title', 'description', 'scheduled_start_at', 'duration_minutes', 'max_participants', 'waiting_room_enabled', 'allow_guests']));

        if (!empty($d['clear_password'])) {
            $fields['password_hash'] = null;
        } elseif (!empty($d['password'])) {
            $fields['password_hash'] = \Illuminate\Support\Facades\Hash::make($d['password']);
        }

        if (isset($d['settings']) && is_array($d['settings'])) {
            $allowed = ['mute_on_entry', 'camera_on_entry', 'allow_chat', 'allow_file_sharing', 'recording_enabled'];
            $fields['settings'] = array_merge($m->settings ?? [], array_map('boolval', array_intersect_key($d['settings'], array_flip($allowed))));
        }

        $m->fill($fields)->save();

        return $m;
    }

    /** @throws \RuntimeException الاجتماع مش في حالة تسمح. */
    public function end(Meeting $m): Meeting
    {
        if (!in_array($m->status, ['lobby', 'live'], true)) {
            throw new \RuntimeException('Only a live meeting can be ended.');
        }

        return $this->meetingService->end($m);
    }

    /** @throws \RuntimeException */
    public function cancel(Meeting $m): Meeting
    {
        if ($m->status !== 'scheduled') {
            throw new \RuntimeException('Only a scheduled meeting can be cancelled. End it instead if it is live.');
        }

        return $this->meetingService->cancel($m);
    }

    public function delete(Meeting $m): void
    {
        if (in_array($m->status, ['lobby', 'live'], true)) {
            $this->meetingService->end($m);
        }
        $this->meetingService->delete($m);
    }

    public function restore(Meeting $m): Meeting
    {
        $m->restore();

        return $m;
    }

    /** @return int عدد الاجتماعات اللي اتنهت */
    public function endAllLive(): int
    {
        $n = 0;
        foreach (Meeting::whereIn('status', ['lobby', 'live'])->get() as $m) {
            try {
                $this->meetingService->end($m);
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    public function setLock(Meeting $m, int $adminId, bool $locked): bool
    {
        return $this->hostControl->asAdmin()->setLock($m, $adminId, $locked);
    }

    public function setScreenShareLock(Meeting $m, int $adminId, bool $locked): bool
    {
        return $this->signaling->setScreenSharingLock($m, $this->adminActor($adminId), $locked);
    }

    public function regenerateLink(Meeting $m): Meeting
    {
        $m->join_token = $this->meetings->generateUniqueJoinToken();
        $m->save();

        return $m;
    }

    public function announce(Meeting $m, string $message): void
    {
        $this->chat->postSystemMessage($m, '[Administrator] ' . trim($message));
    }

    /**
     * @param string $action mute|disable-camera|remove|promote|demote|make-host|stop-screen-share|allow-screen-share|deny-screen-share|reset-screen-share
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function participantAction(Meeting $m, int $adminId, string $key, string $action): void
    {
        $hc = $this->hostControl->asAdmin();

        switch ($action) {
            case 'mute':
                $hc->mute($m, $adminId, $key);
                break;
            case 'disable-camera':
                $hc->disableCamera($m, $adminId, $key);
                break;
            case 'remove':
                $hc->remove($m, $adminId, $key);
                break;
            case 'promote':
                $hc->promote($m, $adminId, $key);
                break;
            case 'demote':
                $hc->demote($m, $adminId, $key);
                break;
            case 'make-host':
                $hc->transferHost($m, $adminId, $key);
                break;
            case 'stop-screen-share':
                $this->signaling->stopParticipantScreenShare($m, $this->adminActor($adminId), $key);
                break;
            case 'allow-screen-share':
                $this->signaling->setParticipantScreenSharePolicy($m, $this->adminActor($adminId), $key, true);
                break;
            case 'deny-screen-share':
                $this->signaling->setParticipantScreenSharePolicy($m, $this->adminActor($adminId), $key, false);
                break;
            case 'reset-screen-share':
                $this->signaling->setParticipantScreenSharePolicy($m, $this->adminActor($adminId), $key, null);
                break;
            default:
                throw new \InvalidArgumentException('Unknown participant action.');
        }
    }

    /** actor وهمي بدور host لخدمات الـ signaling اللي بتتطلب role فقط. */
    private function adminActor(int $adminId): array
    {
        return ['type' => 'admin', 'key' => 'admin:' . $adminId, 'display_name' => 'Administrator', 'role' => 'host', 'model' => null];
    }

    // ------------------------------------------------------------------
    // ملفات / تسجيلات (حذف بس — مفيش قراءة محتوى)
    // ------------------------------------------------------------------

    public function deleteFile(Meeting $m, int $fileId): MeetingFile
    {
        $file = MeetingFile::where('meeting_id', $m->id)->where('id', $fileId)->first();
        if (!$file) {
            throw new \RuntimeException('File not found.');
        }
        if (!empty($file->stored_path)) {
            $this->uploads->delete($file->stored_path);
        }
        $file->delete();
        event(new \App\Events\Meetings\MeetingFileRemoved($m->uuid, (int) $file->id));

        return $file;
    }

    public function deleteRecording(Meeting $m, int $recordingId): MeetingRecording
    {
        $rec = MeetingRecording::where('meeting_id', $m->id)->where('id', $recordingId)->first();
        if (!$rec) {
            throw new \RuntimeException('Recording not found.');
        }
        if (!empty($rec->stored_path)) {
            $this->uploads->delete($rec->stored_path);
        }
        $rec->delete();

        return $rec;
    }

    // ------------------------------------------------------------------
    // المستخدمين المحظورين من إنشاء اجتماعات
    // ------------------------------------------------------------------

    public function blockedUsers(): array
    {
        $ids = (array) MeetingAdminSettings::get('blocked_user_ids');
        if (!$ids) {
            return [];
        }
        $rows = DB::table('users')->whereIn('id', $ids)->get(['id', 'full_name', 'email'])->keyBy('id');

        return array_map(fn ($id) => [
            'id'    => (int) $id,
            'name'  => $rows[$id]->full_name ?? ('User #' . $id),
            'email' => $rows[$id]->email ?? null,
        ], $ids);
    }

    public function searchUsers(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        return DB::table('users')
            ->where(fn ($w) => $w->where('full_name', 'like', $term)->orWhere('email', 'like', $term))
            ->orderBy('full_name')->limit(15)->get(['id', 'full_name', 'email'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->full_name, 'email' => $u->email])->all();
    }
}
