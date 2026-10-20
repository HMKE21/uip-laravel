<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\DB;

/**
 * أمان الشات: حظر مستخدم (user_blocks) + بلاغات الرسائل/المستخدمين (chat_reports).
 *
 * - الحظر ثنائي الأثر في المحادثات المباشرة: لو أي طرف حظر التاني، الإرسال ممنوع
 *   في الاتجاهين وبدء محادثة جديدة ممنوع. اللي اتحظر مبيعرفش إنه اتحظر
 *   (رسالة الخطأ عامة)، والحاظر بيشوف زرار "إلغاء الحظر".
 * - البلاغ بيروح: للأدمن (دايمًا) + جامعة المبلَّغ عنه + كليته (لو له).
 *   اللي يشوف/يقفل البلاغ: admin (الكل)، university (جامعته)، faculty (كليته).
 */
class ChatSafetyService
{
    public const REASONS = ['harassment', 'spam', 'inappropriate', 'threat', 'impersonation', 'other'];
    public const STATUSES = ['pending', 'reviewing', 'resolved', 'dismissed'];

    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $auditLog,
        private SettingRepository $settings,
    ) {
    }

    // -- Blocks ----------------------------------------------------------------

    public function block(int $blockerId, int $blockedId): void
    {
        if ($blockerId === $blockedId) {
            throw new \RuntimeException('You cannot block yourself.');
        }
        if (!DB::table('users')->where('id', $blockedId)->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('User not found.');
        }
        DB::table('user_blocks')->insertOrIgnore([
            'blocker_user_id' => $blockerId,
            'blocked_user_id' => $blockedId,
            'created_at'      => now(),
        ]);
    }

    public function unblock(int $blockerId, int $blockedId): void
    {
        DB::table('user_blocks')
            ->where('blocker_user_id', $blockerId)
            ->where('blocked_user_id', $blockedId)
            ->delete();
    }

    public function hasBlocked(int $blockerId, int $blockedId): bool
    {
        return DB::table('user_blocks')
            ->where('blocker_user_id', $blockerId)
            ->where('blocked_user_id', $blockedId)
            ->exists();
    }

    /** @return int[] ids كل اللي أنا حاظرهم (استعلام واحد — للـ inbox بدل استعلام لكل محادثة). */
    public function blockedIds(int $blockerId): array
    {
        return DB::table('user_blocks')->where('blocker_user_id', $blockerId)->pluck('blocked_user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** أي طرف حظر التاني؟ */
    public function isBlockedEitherWay(int $a, int $b): bool
    {
        return DB::table('user_blocks')
            ->where(function ($q) use ($a, $b) {
                $q->where('blocker_user_id', $a)->where('blocked_user_id', $b);
            })
            ->orWhere(function ($q) use ($a, $b) {
                $q->where('blocker_user_id', $b)->where('blocked_user_id', $a);
            })
            ->exists();
    }

    /** @return 'me'|'peer'|null مين الحاظر في الاتجاه ده (لو الاتنين حاظرين بعض: 'me'). */
    public function blockDirection(int $viewerId, int $peerId): ?string
    {
        if ($this->hasBlocked($viewerId, $peerId)) {
            return 'me';
        }
        if ($this->hasBlocked($peerId, $viewerId)) {
            return 'peer';
        }
        return null;
    }

    /** الطرف التاني في محادثة مباشرة (null لو جروب أو مش موجود). */
    public function directPeerId(int $conversationId, int $userId): ?int
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first(['is_group']);
        if (!$conv || $conv->is_group) {
            return null;
        }
        $peer = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', '!=', $userId)
            ->value('user_id');
        return $peer ? (int) $peer : null;
    }

    /** @return array<int,array<string,mixed>> قايمة المحظورين عندي (الأحدث أولًا). */
    public function blockedList(int $userId): array
    {
        $rows = DB::table('user_blocks as b')
            ->join('users as u', 'u.id', '=', 'b.blocked_user_id')
            ->where('b.blocker_user_id', $userId)
            ->orderByDesc('b.created_at')
            ->get(['u.id', 'u.full_name', 'u.email', 'u.avatar_path', 'u.deleted_at', 'b.created_at as blocked_at']);

        return $rows->map(function ($r) {
            $hidden = !empty($r->deleted_at)
                || $this->settings->get('messaging_show_avatar', 'user', $r->id, '1') === '0'
                || $this->settings->get('profile_photo_public', 'user', $r->id, '1') === '0';
            return [
                'id'           => (int) $r->id,
                'full_name'    => $r->full_name,
                'email'        => empty($r->deleted_at) ? $r->email : '',
                'avatar_path'  => $hidden ? null : $r->avatar_path,
                'account_role' => $this->primaryRoleSlug((int) $r->id),
                'is_deleted'   => !empty($r->deleted_at),
                'blocked_at'   => $r->blocked_at,
            ];
        })->all();
    }

    // -- Reporting ---------------------------------------------------------------

    /**
     * @param array{message_id?:int|null,user_id?:int|null,reason?:string,details?:string|null,also_block?:bool} $in
     * @return array{id:int,blocked:bool}
     * @throws \RuntimeException
     */
    public function report(int $reporterId, array $in): array
    {
        $reason = (string) ($in['reason'] ?? '');
        if (!in_array($reason, self::REASONS, true)) {
            throw new \RuntimeException('Please choose a reason for the report.');
        }
        $details = trim((string) ($in['details'] ?? ''));
        if (mb_strlen($details) > 1000) {
            throw new \RuntimeException('Details are too long (max 1000 characters).');
        }
        if ($reason === 'other' && $details === '') {
            throw new \RuntimeException('Please describe the problem.');
        }

        $conversationId = null;
        $messageId = null;
        $snapshot = null;

        if (!empty($in['message_id'])) {
            $message = DB::table('messages')->where('id', (int) $in['message_id'])->first();
            if (!$message) {
                throw new \RuntimeException('Message not found.');
            }
            $isMember = DB::table('conversation_participants')
                ->where('conversation_id', $message->conversation_id)
                ->where('user_id', $reporterId)
                ->exists();
            if (!$isMember) {
                throw new \RuntimeException('Message not found.');
            }
            if ((int) $message->sender_id === $reporterId) {
                throw new \RuntimeException('You cannot report your own message.');
            }
            if ($message->message_type === 'system') {
                throw new \RuntimeException('System messages cannot be reported.');
            }
            $reportedId = (int) $message->sender_id;
            $conversationId = (int) $message->conversation_id;
            $messageId = (int) $message->id;
            $snapshot = $this->snapshotOf($message);

            if (DB::table('chat_reports')->where('reporter_user_id', $reporterId)->where('message_id', $messageId)->exists()) {
                throw new \RuntimeException('You have already reported this message.');
            }
        } elseif (!empty($in['user_id'])) {
            $reportedId = (int) $in['user_id'];
            if (!DB::table('users')->where('id', $reportedId)->exists()) {
                throw new \RuntimeException('User not found.');
            }
            if (!empty($in['conversation_id'])) {
                $cid = (int) $in['conversation_id'];
                $isMember = DB::table('conversation_participants')->where('conversation_id', $cid)->where('user_id', $reporterId)->exists();
                $peerIn = DB::table('conversation_participants')->where('conversation_id', $cid)->where('user_id', $reportedId)->exists();
                if ($isMember && $peerIn) {
                    $conversationId = $cid;
                }
            }
            $open = DB::table('chat_reports')
                ->where('reporter_user_id', $reporterId)
                ->where('reported_user_id', $reportedId)
                ->whereNull('message_id')
                ->whereIn('status', ['pending', 'reviewing'])
                ->exists();
            if ($open) {
                throw new \RuntimeException('You already have an open report about this user.');
            }
        } else {
            throw new \RuntimeException('Nothing to report.');
        }

        if ($reportedId === $reporterId) {
            throw new \RuntimeException('You cannot report yourself.');
        }

        // جهة البلاغ: المبلَّغ عنه أولًا، ولو مالوش جهة نرجع لجهة المُبلِّغ.
        $aff = $this->affiliationFor($reportedId);
        if ($aff['university_id'] === null && $aff['faculty_id'] === null) {
            $aff = $this->affiliationFor($reporterId);
        }

        $id = (int) DB::table('chat_reports')->insertGetId([
            'reporter_user_id' => $reporterId,
            'reported_user_id' => $reportedId,
            'conversation_id'  => $conversationId,
            'message_id'       => $messageId,
            'reason'           => $reason,
            'details'          => $details !== '' ? $details : null,
            'message_snapshot' => $snapshot,
            'university_id'    => $aff['university_id'],
            'faculty_id'       => $aff['faculty_id'],
            'status'           => 'pending',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $blocked = false;
        if (!empty($in['also_block'])) {
            $this->block($reporterId, $reportedId);
            $blocked = true;
        }

        $this->auditLog->record($reporterId, 'chat_report.created', 'chat_report', $id, null, [
            'reported_user_id' => $reportedId, 'reason' => $reason, 'message_id' => $messageId,
        ]);
        $this->notifyRecipients($id, $reportedId, $reason, $aff);

        return ['id' => $id, 'blocked' => $blocked];
    }

    /** بلاغاتي كمُبلِّغ + حالتها. */
    public function myReports(int $userId): array
    {
        $rows = DB::table('chat_reports as r')
            ->join('users as ru', 'ru.id', '=', 'r.reported_user_id')
            ->where('r.reporter_user_id', $userId)
            ->orderByDesc('r.id')
            ->limit(200)
            ->get(['r.id', 'r.reason', 'r.details', 'r.message_snapshot', 'r.status', 'r.resolution_notes', 'r.created_at', 'r.reviewed_at', 'ru.full_name as reported_name']);

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    /**
     * طابور المراجعة حسب الدور.
     * @return array{items:array,counts:array}
     * @throws \RuntimeException لو الدور مالوش صلاحية
     */
    public function reportsFor(string $role, int $userId, array $filters = []): array
    {
        $scope = $this->scopeFor($role, $userId);

        $base = DB::table('chat_reports as r')
            ->join('users as rep', 'rep.id', '=', 'r.reporter_user_id')
            ->join('users as tgt', 'tgt.id', '=', 'r.reported_user_id')
            ->leftJoin('users as rev', 'rev.id', '=', 'r.reviewed_by');
        $this->applyScope($base, $scope);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ((clone $base)->selectRaw('r.status as s, COUNT(*) as c')->groupBy('r.status')->get() as $row) {
            $counts[$row->s] = (int) $row->c;
        }

        $query = clone $base;
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $query->where('r.status', $status);
        }
        $reason = (string) ($filters['reason'] ?? '');
        if (in_array($reason, self::REASONS, true)) {
            $query->where('r.reason', $reason);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('rep.full_name', 'like', $like)->orWhere('rep.email', 'like', $like)
                    ->orWhere('tgt.full_name', 'like', $like)->orWhere('tgt.email', 'like', $like)
                    ->orWhere('r.message_snapshot', 'like', $like)->orWhere('r.details', 'like', $like);
            });
        }

        $rows = $query->orderByRaw("CASE r.status WHEN 'pending' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END")
            ->orderByDesc('r.id')
            ->limit(300)
            ->get([
                'r.*',
                'rep.full_name as reporter_name', 'rep.email as reporter_email',
                'tgt.full_name as reported_name', 'tgt.email as reported_email',
                'rev.full_name as reviewer_name',
            ]);

        $items = $rows->map(function ($r) use ($role) {
            $r = (array) $r;
            $r['reporter_role'] = $this->primaryRoleSlug((int) $r['reporter_user_id']);
            $r['reported_role'] = $this->primaryRoleSlug((int) $r['reported_user_id']);
            $r['reported_blocked_count'] = DB::table('user_blocks')->where('blocked_user_id', $r['reported_user_id'])->count();
            $r['reported_total_reports'] = DB::table('chat_reports')->where('reported_user_id', $r['reported_user_id'])->count();
            $r['can_delete_message'] = $role === 'admin' && !empty($r['message_id']);
            return $r;
        })->all();

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * يغيّر حالة بلاغ. 'delete_message' للأدمن بس (بيحذف الرسالة المبلَّغ عنها للجميع).
     * @throws \RuntimeException
     */
    public function updateStatus(string $role, int $userId, int $reportId, string $status, ?string $notes, ?string $action, MessagingService $messaging): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \RuntimeException('Invalid status.');
        }
        $scope = $this->scopeFor($role, $userId);

        $q = DB::table('chat_reports as r')->where('r.id', $reportId);
        $this->applyScope($q, $scope);
        $report = $q->first(['r.*']);
        if (!$report) {
            throw new \RuntimeException('Report not found.');
        }

        $notes = $notes !== null ? trim($notes) : null;
        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw new \RuntimeException('Notes are too long (max 1000 characters).');
        }

        if ($action === 'delete_message') {
            if ($role !== 'admin') {
                throw new \RuntimeException('Only admins can delete the reported message.');
            }
            if (!$report->message_id) {
                throw new \RuntimeException('This report has no message to delete.');
            }
            $messaging->adminDeleteMessage((int) $report->message_id, $userId);
        } elseif ($action !== null && $action !== '') {
            throw new \RuntimeException('Unknown action.');
        }

        $closing = in_array($status, ['resolved', 'dismissed'], true);
        DB::table('chat_reports')->where('id', $reportId)->update([
            'status'           => $status,
            'reviewed_by'      => $userId,
            'reviewed_at'      => now(),
            'resolution_notes' => $notes !== '' ? $notes : null,
            'updated_at'       => now(),
        ]);

        $this->auditLog->record($userId, 'chat_report.' . $status, 'chat_report', $reportId, ['status' => $report->status], [
            'status' => $status, 'action' => $action,
        ]);

        if ($closing && (string) $report->status !== $status) {
            $this->notifications->notify(
                (int) $report->reporter_user_id,
                'chat_report_update',
                $status === 'resolved' ? 'Your report was reviewed' : 'Your report was closed',
                $notes !== null && $notes !== '' ? mb_substr($notes, 0, 140) : 'Thanks for helping keep the platform safe.',
                null
            );
        }
    }

    // -- helpers -----------------------------------------------------------------

    private function snapshotOf(object $message): string
    {
        if (!empty($message->is_deleted)) {
            return '(message was deleted)';
        }
        $body = trim((string) $message->body);
        $files = DB::table('message_attachments')->where('message_id', $message->id)->pluck('original_name')->all();
        if ($files) {
            $body .= ($body !== '' ? "\n" : '') . '[' . implode(', ', array_map('strval', $files)) . ']';
        }
        return mb_substr($body !== '' ? $body : '(empty)', 0, 4000);
    }

    /** @return array{type:string,id:?int} */
    private function scopeFor(string $role, int $userId): array
    {
        if ($role === 'admin') {
            return ['type' => 'all', 'id' => null];
        }
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $userId)->value('id');
            if ($id) {
                return ['type' => 'university', 'id' => (int) $id];
            }
        }
        if ($role === 'faculty') {
            $id = DB::table('faculties')->where('user_id', $userId)->value('id');
            if ($id) {
                return ['type' => 'faculty', 'id' => (int) $id];
            }
        }
        throw new \RuntimeException('You are not allowed to view chat reports.');
    }

    private function applyScope($query, array $scope): void
    {
        if ($scope['type'] === 'university') {
            $query->where('r.university_id', $scope['id']);
        } elseif ($scope['type'] === 'faculty') {
            $query->where('r.faculty_id', $scope['id']);
        }
    }

    /** @return array{university_id:?int,faculty_id:?int} */
    public function affiliationFor(int $userId): array
    {
        $out = ['university_id' => null, 'faculty_id' => null];

        $uni = DB::table('universities')->where('user_id', $userId)->value('id');
        if ($uni) {
            $out['university_id'] = (int) $uni;
            return $out;
        }
        $fac = DB::table('faculties')->where('user_id', $userId)->first(['id', 'university_id']);
        if ($fac) {
            return ['university_id' => (int) $fac->university_id, 'faculty_id' => (int) $fac->id];
        }
        foreach (['students', 'academic_staff', 'supervisors'] as $table) {
            $cols = $table === 'supervisors' ? ['university_id'] : ['university_id', 'faculty_id'];
            $row = DB::table($table)->where('user_id', $userId)->first($cols);
            if ($row) {
                return [
                    'university_id' => $row->university_id ? (int) $row->university_id : null,
                    'faculty_id'    => isset($row->faculty_id) && $row->faculty_id ? (int) $row->faculty_id : null,
                ];
            }
        }
        return $out;
    }

    private function primaryRoleSlug(int $userId): ?string
    {
        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->orderBy('ur.assigned_at')
            ->value('r.slug');
    }

    private function notifyRecipients(int $reportId, int $reportedId, string $reason, array $aff): void
    {
        $targets = [];
        $admins = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.slug', 'admin')
            ->pluck('ur.user_id')
            ->all();
        foreach ($admins as $a) {
            $targets[(int) $a] = '/admin/chat-reports';
        }
        if ($aff['university_id']) {
            $uid = DB::table('universities')->where('id', $aff['university_id'])->value('user_id');
            if ($uid) {
                $targets[(int) $uid] = '/university/chat-reports';
            }
        }
        if ($aff['faculty_id']) {
            $fid = DB::table('faculties')->where('id', $aff['faculty_id'])->value('user_id');
            if ($fid) {
                $targets[(int) $fid] = '/faculty/chat-reports';
            }
        }
        unset($targets[$reportedId]);

        foreach ($targets as $uid => $link) {
            $this->notifications->notify($uid, 'chat_report_new', 'New chat report', 'A chat message or user was reported (' . $reason . ').', $link, 'high');
        }
    }
}
