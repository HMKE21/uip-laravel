<?php

namespace App\Services;

use App\Models\MessagePollVote;
use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use App\Repositories\MessagingPermissionRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\UserRepository;
use App\Support\PlatformPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * منقولة (Core\Database -> DB facade / Eloquent) من app/Services/
 * MessagingService.php القديمة — النظام المركزي الوحيد اللي كل بورتال
 * (Student/University/...) بيستخدمه
 * لأي حاجة خاصة بالرسايل. الكنترولر الموحّد Http\Controllers\Api\
 * MessagingController (/api/v1/messaging/*) هو المستهلك الوحيد هنا (زي
 * Common\MessagingController القديمة بالظبط — كنترولر واحد، مش واحد لكل
 * بورتال).
 *
 * Real-time note: مفيش WebSocket process متاح على الاستضافة الحالية —
 * التحديث اللحظي (رسايل جديدة/typing/online) شغال بـ short-interval
 * polling على poll()/presenceFor()/typingIn()، مش socket push. نفس قرار
 * القديم بالظبط (config('messaging.presence')).
 *
 * فجوات متعمدة موثقة (مش استخدام فعلي من الفرونت الحالي، أو أجزاء من
 * بنود لاحقة):
 *   - GIFs/Stickers: بتحتاج GiphyService/StickerCatalogService القديمة —
 *     مش منقولين هنا، ومفيش استدعاء ليهم من Messages.jsx الحالي.
 *   - تشفير المرفقات وقت الحفظ (AttachmentEncryptionService) — بند 25.
 *   - Admin Messaging Oversight — بند 26 (Admin).
 */
class MessagingService
{
    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private UserRepository $users,
        private NotificationService $notifications,
        private FileUploadService $uploads,
        private StudentRepository $students,
        private MessagingPermissionRepository $permissions,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $academicStaff,
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $supervisorAssignments,
        private SettingRepository $settings,
        private RoleService $roles,
        private AudioDenoiseService $denoise,
        private GroupChatService $groups,
        private ChatSafetyService $safety,
    ) {
    }

    // -- Inbox / search --------------------------------------------------

    /** @return array<int,array<string,mixed>> صفوف الـ inbox، بشكل الفرونت المتوقع */
    public function inboxForUser($userId, bool $trashed = false): array
    {
        $rows = $this->conversations->inboxForUser($userId, $trashed);
        $blockedSet = array_flip($this->safety->blockedIds((int) $userId));

        return array_map(function ($row) use ($blockedSet, $userId) {
            $others = $this->flagDeleted($this->applyAvatarPrivacy($row['other_participants']));
            $displayName = $row['subject'] ?: ($row['is_group'] ? $row['group_name'] : null) ?: ($others[0]['full_name'] ?? '—');
            $peer = !$row['is_group'] ? ($others[0] ?? null) : null;

            return [
                'id'                 => (int) $row['id'],
                'subject'            => $displayName,
                'is_group'           => (bool) $row['is_group'],
                'participants'       => array_map(fn ($p) => $p['full_name'], $others),
                'participant_emails' => array_map(fn ($p) => $p['email'] ?? '', $others),
                'participant_orgs'   => array_map(fn ($p) => $p['org'] ?? '', $others),
                // الطرف التاني في المحادثة المباشرة (إيميل/صورة/نوع حساب) — الصورة null لو هو مخفيها.
                'peer_email'         => $peer['email'] ?? null,
                'peer_avatar'        => $peer['avatar_path'] ?? null,
                'peer_role'          => $peer['account_role'] ?? null,
                // الطرف التاني حذف حسابه: المحادثة بتتقفل (قراءة بس) والفرونت بيغيّر شكلها.
                'peer_deleted'       => (bool) ($peer['is_deleted'] ?? false),
                'peer_id'            => $peer ? (int) $peer['id'] : null,
                // حظرت الطرف ده؟ (الفرونت بيعرض "محظور" بدل المعاينة ويقفل الإرسال)
                'peer_blocked_by_me' => $peer ? isset($blockedSet[(int) $peer['id']]) : false,
                'group_avatar'       => $row['group_avatar'] ?? null,
                'preview_type'       => $row['last_message_type'] ?? 'text',
                'preview_meta'       => in_array(($row['last_message_type'] ?? ''), ['system', 'call'], true) && !empty($row['last_message_meta']) ? json_decode($row['last_message_meta'], true) : null,
                // الرسالة اللي ملهاش نص (صورة/صوت/ملف/استطلاع) كان preview بتاعها null فالفرونت يكتب
                // "لا توجد رسائل بعد" مع إن فيه رسايل. دلوقتي بنرجّع نوع المعاينة (preview_kind) والعدد
                // والفرونت هو اللي بيترجم الليبل حسب اللغة.
                'preview'            => $row['last_message_deleted'] ? null : (trim((string) ($row['last_message_body'] ?? '')) !== '' ? mb_substr($row['last_message_body'], 0, 120) : null),
                'preview_kind'       => $this->previewKind($row),
                'preview_count'      => (int) ($row['last_message_attach_count'] ?? 0),
                'preview_from_me'    => isset($row['last_message_sender_id']) ? ((string) $row['last_message_sender_id'] === (string) $userId) : false,
                'time'               => $row['last_message_at'] ?: $row['updated_at'],
                'is_unread'          => (bool) $row['is_unread'],
                'is_favorite'        => (bool) $row['is_favorite'],
                'is_pinned'          => (bool) $row['is_pinned'],
                'is_muted'           => (bool) $row['is_muted'],
                'is_archived'        => (bool) $row['is_archived'],
                'category'           => $row['category'],
                'my_role'            => $row['my_role'],
                // سلة المحذوفات: وقت الحذف + الأيام المتبقية قبل الحذف النهائي التلقائي (null في الـ inbox العادي).
                'deleted_at'         => $row['deleted_at'] ?? null,
                'days_left'          => !empty($row['deleted_at']) ? \App\Services\ConversationTrashService::daysLeft($row['deleted_at']) : null,
            ];
        }, $rows);
    }

    /**
     * نوع معاينة آخر رسالة لما مفيش نص: deleted | image | video | audio | document | archive | code | file | poll | gif | sticker | null.
     * null = رسالة نص عادية (أو مفيش رسايل أصلاً) — الفرونت يعرض body/الحالة الفاضية.
     */
    private function previewKind(array $row): ?string
    {
        if (!empty($row['last_message_deleted'])) {
            return 'deleted';
        }
        if (empty($row['last_message_at'])) {
            return null;
        }
        $hasText = trim((string) ($row['last_message_body'] ?? '')) !== '';
        $kind = $row['last_message_attach_kind'] ?? null;
        if ($kind) {
            return (string) $kind;
        }
        $type = (string) ($row['last_message_type'] ?? 'text');
        if ($type === 'system') {
            return null; // رسائل النظام ليها preview_meta خاص
        }
        if (in_array($type, ['poll', 'gif', 'sticker', 'image', 'video', 'audio', 'file', 'call'], true)) {
            return $type;
        }
        if ($type === 'location' || $type === 'live_location') {
            return $type;
        }
        return $hasText ? null : 'file';
    }

    /** بحث نصي على قايمة الـ inbox نفسها (subject/participant name/email/org). */
    public function searchInbox($userId, string $query): array
    {
        $conversations = $this->inboxForUser($userId);
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return $conversations;
        }

        return array_values(array_filter($conversations, function ($c) use ($needle) {
            if (str_contains(mb_strtolower($c['subject'] ?? ''), $needle)) {
                return true;
            }
            foreach ($c['participants'] as $name) {
                if (str_contains(mb_strtolower($name), $needle)) {
                    return true;
                }
            }
            foreach ($c['participant_emails'] as $email) {
                if ($email && str_contains(mb_strtolower($email), $needle)) {
                    return true;
                }
            }
            foreach ($c['participant_orgs'] as $org) {
                if ($org && str_contains(mb_strtolower($org), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @return array<int,array<string,mixed>> نتائج دليل المستخدمين لبدء رسالة جديدة */
    public function searchRecipients(string $query, $excludeUserId): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $rows = $this->users->searchDirectory($query, $excludeUserId);
        // الصورة تتشال من أي شخص مخفيها — الفرونت يعرض الحروف الأولى بدلها.
        foreach ($rows as &$r) {
            if ($this->isAvatarHidden((int) $r['id'], $excludeUserId)) {
                $r['avatar_path'] = null;
            }
        }
        unset($r);
        return $rows;
    }

    // -- Thread ------------------------------------------------------------

    /** @return array{conversation:array,others:array,messages:array}|null null لو المحادثة مش موجودة أو اليوزر مش عضو فيها */
    public function threadForUser(int $conversationId, $userId, ?int $beforeId = null): ?array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            return null;
        }
        $conversation = $this->conversations->find($conversationId);
        if (!$conversation) {
            return null;
        }

        $this->conversations->markRead($conversationId, $userId);
        if ($beforeId === null) {
            $this->notifications->markConversationNotificationsRead($userId, $conversationId);
        }

        $others = $this->flagDeleted($this->applyAvatarPrivacy($this->conversations->otherParticipants($conversationId, $userId)));
        $messages = array_map(
            fn ($m) => $this->shapeMessage($m, $userId),
            $this->messages->forConversation($conversationId, $userId, $beforeId)
        );
        // ✓ / ✓✓ (sent / delivered / read) على رسايلي
        $myIds = array_values(array_map(fn ($m) => $m['id'], array_filter($messages, fn ($m) => $m['is_mine'])));
        $receiptMap = $this->receiptStatusMap($conversationId, $myIds);
        foreach ($messages as &$msgRow) {
            if ($msgRow['is_mine']) {
                $msgRow['receipt_status'] = $receiptMap[$msgRow['id']] ?? 'sent';
            }
        }
        unset($msgRow);

        $conversationOut = [
            'id'       => (int) $conversation->id,
            'subject'  => $conversation->subject ?: ($conversation->is_group ? $conversation->group_name : null) ?: ($others[0]['full_name'] ?? '—'),
            'is_group' => (bool) $conversation->is_group,
            'my_role'  => $this->conversations->memberRole($conversationId, $userId),
        ];
        if (!$conversation->is_group) {
            // محادثة مباشرة مع حساب محذوف: القراءة متاحة والإرسال لأ.
            $peerDeleted = (bool) ($others[0]['is_deleted'] ?? false);
            $peerId = isset($others[0]['id']) ? (int) $others[0]['id'] : null;
            $blockDir = $peerId ? $this->safety->blockDirection((int) $userId, $peerId) : null;
            $conversationOut['peer_deleted'] = $peerDeleted;
            $conversationOut['peer_id'] = $peerId;
            $conversationOut['blocked_by_me'] = $blockDir === 'me';
            $conversationOut['can_send'] = !$peerDeleted && $blockDir === null;
            // 'blocked_by_peer' بيتعرض للمحظور كرسالة عامة (مبنقولوش إن حد حظره صراحةً).
            $conversationOut['send_block'] = $peerDeleted ? 'peer_deleted' : ($blockDir === 'me' ? 'blocked_by_me' : ($blockDir === 'peer' ? 'blocked_by_peer' : null));
        }
        if ($conversation->is_group) {
            $conversationOut = array_merge($conversationOut, $this->groups->threadExtras($conversationId, $userId));
            // per-member group role / send restriction for the members list
            $flags = $this->groups->memberFlags($conversationId);
            foreach ($others as &$o) {
                $f = $flags[(int) $o['id']] ?? null;
                $o['group_role'] = $f['role'] ?? 'member';
                $o['send_restricted'] = $f['send_restricted'] ?? false;
                $o['joined_at'] = $f['joined_at'] ?? null;
            }
            unset($o);
        }

        return [
            'conversation' => $conversationOut,
            'others'   => $others,
            'messages' => $messages,
        ];
    }

    public function isParticipant(int $conversationId, $userId): bool
    {
        return $this->conversations->isParticipant($conversationId, $userId);
    }

    public function allParticipants(int $conversationId): array
    {
        return $this->conversations->allParticipants($conversationId);
    }

    // -- Sending -------------------------------------------------------------

    /** @param array{parent_message_id?:int,forwarded_from_id?:int,message_type?:string,metadata?:array,mentioned_user_ids?:array,attachments?:UploadedFile[]} $options */
    public function sendMessage(int $conversationId, $userId, string $body, array $options = []): array
    {
        $body = trim($body);
        $hasAttachments = !empty($options['attachments']);
        if ($body === '' && !$hasAttachments && ($options['message_type'] ?? 'text') === 'text') {
            throw new \RuntimeException('Message cannot be empty.');
        }
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }

        if ($this->directPeerDeleted($conversationId)) {
            throw new \RuntimeException('This account was deleted, so you can no longer send messages in this conversation.');
        }

        $this->assertNotBlocked($conversationId, (int) $userId);

        $maxLen = (int) config('messaging.max_message_length', 8000);
        if (mb_strlen($body) > $maxLen) {
            throw new \RuntimeException("Message is too long (max {$maxLen} characters).");
        }

        // Group rules: closed group / restricted member / allowed message types (no-op for 1:1 chats).
        $kinds = [];
        foreach (array_values((array) ($options['attachments'] ?? [])) as $file) {
            if ($file instanceof UploadedFile) {
                $kinds[] = $this->classifyKind(strtolower((string) $file->getClientOriginalExtension()), $file->getClientOriginalName(), $file->getClientMimeType());
            }
        }
        $this->groups->assertCanSend($conversationId, $userId, (string) ($options['message_type'] ?? 'text'), $body, $kinds);

        $this->assertNotRateLimited($userId);

        // رسالة "مرة واحدة / تايمر" ممنوع تتحوّل (forward) — وإلا الحماية تبقى بلا معنى.
        if (!empty($options['forwarded_from_id'])
            && DB::table('message_attachments')->where('message_id', (int) $options['forwarded_from_id'])->whereNotNull('view_mode')->exists()) {
            throw new \RuntimeException('View-once media cannot be forwarded.');
        }

        // عزل الضوضاء (اختياري لكل ملف): بنعالج الملفات المؤقتة قبل الـ transaction عشان ffmpeg ما يمسكش lock على الرسالة.
        // $options['denoise'] = مصفوفة flags بنفس ترتيب attachments. الفشل = الملف الأصلي بيتبعت (fail-open) والـ client بيتبلّغ.
        $noise = ['requested' => 0, 'applied' => 0, 'reason' => null];
        if ($hasAttachments) {
            foreach (array_values((array) $options['attachments']) as $i => $file) {
                if (empty($options['denoise'][$i]) || !($file instanceof UploadedFile)) {
                    continue;
                }
                $noise['requested']++;
                if ($this->denoise->denoiseUpload($file)) {
                    $noise['applied']++;
                } else {
                    $noise['reason'] = $this->denoise->lastReason();
                }
            }
        }

        // One transaction: a concurrent poll() must never see the message row
        // before its attachments exist (it would be delivered as an empty bubble
        // and never re-fetched, because the client's since_id moves past it).
        $message = DB::transaction(function () use ($conversationId, $userId, $body, $options, $hasAttachments) {
            // رسالة جديدة في محادثة محذوفة (في السلة) بترجّعها لقايمة الرسائل لكل الأعضاء.
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
            $message = $this->messages->create(
                $conversationId,
                $userId,
                $body,
                null,
                $options['parent_message_id'] ?? null,
                $options['forwarded_from_id'] ?? null,
                $options['message_type'] ?? 'text',
                $options['metadata'] ?? null
            );

            if ($body !== '') {
                $this->messages->recordHashtags((int) $message->id, $body);
            }

            foreach ($this->resolveMentions($conversationId, $options['mentioned_user_ids'] ?? []) as $mentionedId) {
                $this->messages->recordMention((int) $message->id, $mentionedId);
            }

            $maxAttachments = (int) config('messaging.max_attachments_per_message', 10);
            if ($hasAttachments) {
                if (count($options['attachments']) > $maxAttachments) {
                    throw new \RuntimeException("You can attach at most {$maxAttachments} files per message.");
                }
                foreach (array_values($options['attachments']) as $i => $file) {
                    $this->storeAttachment((int) $message->id, $userId, $conversationId, $file, $options['view_modes'][$i] ?? null, (int) ($options['view_seconds'][$i] ?? 0));
                }
            }

            return $message;
        });

        $this->conversations->touchUpdatedAt($conversationId);
        $this->conversations->markRead($conversationId, $userId);

        $sender = $this->users->findById($userId);
        foreach ($this->conversations->otherParticipants($conversationId, $userId) as $participant) {
            if ($this->isMuted($conversationId, $participant['id'])) {
                continue;
            }
            $this->notifications->notify(
                $participant['id'],
                'new_message',
                'New message from ' . ($sender->full_name ?? 'a user'),
                $body !== '' ? mb_substr($body, 0, 140) : '(attachment)',
                $this->messagesLinkFor($participant['id'], $conversationId)
            );
        }

        $shaped = $this->shapeMessage($this->messages->withRelations((int) $message->id), $userId);
        if ($noise['requested'] > 0) {
            $shaped['noise_reduction'] = $noise;
        }
        return $shaped;
    }

    /** يبدأ محادثة مباشرة (find-or-create) بالإيميل، من غير ما يبعت رسالة. */
    public function openOrStartDirectByEmail($userId, string $recipientEmail): int
    {
        $recipient = $this->users->findByEmail(trim($recipientEmail));
        if (!$recipient) {
            throw new \RuntimeException('No user was found with that email.');
        }
        if ((string) $recipient->id === (string) $userId) {
            throw new \RuntimeException('You cannot start a conversation with yourself.');
        }

        $existing = $this->conversations->findDirectBetween($userId, $recipient->id);
        if ($existing) {
            return (int) $existing->id; // فتح محادثة قديمة مسموح حتى مع الحظر (القراءة/إلغاء الحظر)؛ الإرسال بيتمنع في sendMessage.
        }

        $this->assertNotBlockedPair((int) $userId, (int) $recipient->id);
        $this->assertGroupMessagingAllowed($userId, $recipient->id);

        $conversation = $this->conversations->createWithParticipants(null, [$userId, $recipient->id]);
        return (int) $conversation->id;
    }

    /** POST /conversations/direct — يبدأ (أو يستخدم القائمة) محادثة مباشرة، وبيبعت أول رسالة لو body مش فاضي. */
    public function startConversation($userId, string $recipientEmail, string $body): int
    {
        $recipient = $this->users->findByEmail(trim($recipientEmail));
        if (!$recipient) {
            throw new \RuntimeException('No user was found with that email.');
        }
        if ((string) $recipient->id === (string) $userId) {
            throw new \RuntimeException('You cannot start a conversation with yourself.');
        }

        $existing = $this->conversations->findDirectBetween($userId, $recipient->id);
        $this->assertNotBlockedPair((int) $userId, (int) $recipient->id);
        if (!$existing) {
            $this->assertGroupMessagingAllowed($userId, $recipient->id);
        }
        $conversation = $existing ?? $this->conversations->createWithParticipants(null, [$userId, $recipient->id]);

        $body = trim($body);
        if ($body !== '') {
            $this->assertNotRateLimited($userId);
            $message = $this->messages->create((int) $conversation->id, $userId, $body);
            $this->messages->recordHashtags((int) $message->id, $body);
            $this->conversations->touchUpdatedAt((int) $conversation->id);
            $this->conversations->markRead((int) $conversation->id, $userId);

            $sender = $this->users->findById($userId);
            $this->notifications->notify(
                $recipient->id,
                'new_message',
                'New message from ' . ($sender->full_name ?? 'a user'),
                mb_substr($body, 0, 140),
                $this->messagesLinkFor($recipient->id, (int) $conversation->id)
            );
        }

        return (int) $conversation->id;
    }

    /**
     * Student Portal spec section 2: طالب بيقدر يراسل بس أعضاء نفس الجروب
     * بتاعه إلا لو فيه صلاحية إضافية اتوافق عليها، أو المؤسسة/الكلية/
     * المشرف بتوعه. مقيّدة على إنشاء محادثة *جديدة* بس. أي مرسل مش طالب
     * (staff/supervisor/university/admin/...)
     * مقيّدش خالص.
     * @throws \RuntimeException لو ممنوع
     */
    private function assertGroupMessagingAllowed($userId, $recipientId): void
    {
        // Admin → Platform Policy → "Restrict student messaging". Off (default) = anyone can message anyone.
        if (!PlatformPolicy::flag('messaging_restricted')) {
            return;
        }
        $senderStudent = $this->students->findByUserId($userId);
        if (!$senderStudent) {
            return;
        }

        $recipientStudent = $this->students->findByUserId($recipientId);
        if ($recipientStudent) {
            $this->assertStudentToStudentAllowed($userId, $recipientId, $senderStudent, $recipientStudent);
            return;
        }

        $this->assertStudentToInstitutionAllowed($recipientId, $senderStudent);
    }

    private function assertStudentToStudentAllowed($userId, $recipientId, $senderStudent, $recipientStudent): void
    {
        if ($senderStudent->group_id && (string) $senderStudent->group_id === (string) $recipientStudent->group_id) {
            return;
        }
        if ($this->permissions->hasApprovedGrant($userId, $recipientId)) {
            return;
        }
        throw new \RuntimeException(
            'You can only message members of your own project group. ' .
            'You can request permission to message this student from Messages > Permission Requests.'
        );
    }

    private function assertStudentToInstitutionAllowed($recipientId, $senderStudent): void
    {
        $university = $this->universities->findByUserId($recipientId);
        if ($university) {
            if ((string) $university->id === (string) $senderStudent->university_id) {
                return;
            }
            throw new \RuntimeException('You can only message your own university.');
        }

        $faculty = $this->faculties->findByUserId($recipientId);
        if ($faculty) {
            if ($senderStudent->faculty_id && (string) $faculty->id === (string) $senderStudent->faculty_id) {
                return;
            }
            throw new \RuntimeException('You can only message your own faculty.');
        }

        $staff = $this->academicStaff->findByUserId($recipientId);
        if ($staff) {
            if (
                ($staff->department_id && $senderStudent->department_id && (string) $staff->department_id === (string) $senderStudent->department_id)
                || ($staff->faculty_id && $senderStudent->faculty_id && (string) $staff->faculty_id === (string) $senderStudent->faculty_id)
                || ($staff->university_id && (string) $staff->university_id === (string) $senderStudent->university_id && !$staff->department_id && !$staff->faculty_id)
            ) {
                return;
            }
            throw new \RuntimeException('You can only message academic staff in your own department, faculty, or university.');
        }

        $supervisor = $this->supervisors->findByUserId($recipientId);
        if ($supervisor) {
            if ($this->supervisorAssignments->coversStudent($supervisor->id, $senderStudent->user_id)) {
                return;
            }
            throw new \RuntimeException('You can only message a supervisor assigned to you.');
        }

        // أي نوع حساب تاني (admin/security/data_analyst/...) من غير قيد.
    }

    /** رابط ثريد الرسايل مضبوط على بورتال المستلم نفسه (كل بورتال ليه /messages بتاعه). */
    private function messagesLinkFor($recipientId, int $conversationId): string
    {
        $role = $this->roles->primaryRoleFor((int) $recipientId);
        // البريفيكس لازم يطابق الراوتات في الفرونت (security / data-analysis ...)
        // مش اسم الدور (security_admin / data_analyst)، فنأخذه من home_route.
        $home = (string) config("roles.home_route.{$role}", '');
        $prefix = $home !== ''
            ? explode('/', trim($home, '/'))[0]
            : str_replace('_', '-', $role);
        return "/{$prefix}/messages/{$conversationId}";
    }


    /**
     * حالة التسليم/القراءة لمجموعة رسايل أنا مرسلها: sent | delivered | read.
     * read = كل المستلمين قروها (ومفعّلين read receipts)، delivered = كلهم استلموها.
     * استعلامين بس مهما كان عدد الرسايل.
     *
     * @param  array<int,int> $messageIds
     * @return array<int,string> message_id => status
     */
    private function receiptStatusMap(int $conversationId, array $messageIds): array
    {
        if (!$messageIds) {
            return [];
        }
        $senderId = DB::table('messages')->where('id', $messageIds[0])->value('sender_id');
        $recipients = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', '!=', $senderId)->count();
        if ($recipients === 0) {
            return array_fill_keys($messageIds, 'sent');
        }
        $rows = DB::table('message_read_receipts')->whereIn('message_id', $messageIds)
            ->selectRaw('message_id, COUNT(delivered_at) AS delivered, COUNT(read_at) AS read_count')
            ->groupBy('message_id')->get()->keyBy('message_id');

        $out = [];
        foreach ($messageIds as $id) {
            $r = $rows[$id] ?? null;
            $out[$id] = !$r ? 'sent'
                : ((int) $r->read_count >= $recipients ? 'read' : ((int) $r->delivered >= $recipients ? 'delivered' : 'sent'));
        }
        return $out;
    }

    // -- Shaping -------------------------------------------------------------

    private function shapeMessage(array $m, $viewerId): array
    {
        $pollExtra = [];
        if (($m['message_type'] ?? 'text') === 'poll' && empty($m['is_deleted'])) {
            $pollExtra = $this->pollSummary((int) $m['id'], $viewerId);
        }
        return $pollExtra + [
            'id'                 => (int) $m['id'],
            'conversation_id'    => (int) $m['conversation_id'],
            'body'               => $m['is_deleted'] ? null : $m['body'],
            'message_type'       => $m['message_type'] ?? 'text',
            'metadata'           => !$m['is_deleted'] && isset($m['metadata']) && $m['metadata'] ? json_decode($m['metadata'], true) : null,
            'sender_id'          => $m['sender_id'],
            'sender_name'        => $m['sender_name'],
            'sender_avatar'      => $this->isAvatarHidden((int) $m['sender_id'], $viewerId) ? null : ($m['sender_avatar'] ?? null),
            'is_mine'            => (string) $m['sender_id'] === (string) $viewerId,
            'is_edited'          => (bool) ($m['is_edited'] ?? false),
            'is_pinned'          => (bool) ($m['is_pinned'] ?? false),
            'is_deleted'         => (bool) ($m['is_deleted'] ?? false),
            'deleted_reason'     => !empty($m['is_deleted']) ? ($m['deleted_reason'] ?? null) : null,
            'created_at'         => $m['created_at'],
            'edited_at'          => $m['edited_at'] ?? null,
            'parent_message_id'  => $m['parent_message_id'] ?? null,
            'parent_body'        => $m['parent_body'] ?? null,
            'parent_sender_name' => $m['parent_sender_name'] ?? null,
            'forwarded_from_id'  => $m['forwarded_from_id'] ?? null,
            'attachments'        => !empty($m['is_deleted']) ? [] : array_map(fn ($a) => $this->shapeAttachment($a), $m['attachments'] ?? []),
            'reactions'          => $m['reactions'] ?? [],
            'mentions'           => $m['mentions'] ?? [],
        ];
    }

    private function shapeAttachment(array $a): array
    {
        // View-once / timer media: the real file URL is NEVER sent to the client. It can only be fetched once, through
        // GET /messaging/attachments/{id}/view-once (which deletes the file from the server right after sending it).
        if (!empty($a['view_mode'])) {
            return [
                'id'               => (int) $a['id'],
                'kind'             => $a['kind'],
                'url'              => null,
                'thumbnail_url'    => null,
                'original_name'    => $a['kind'] === 'video' ? 'view-once-video' : 'view-once-photo',
                'mime_type'        => null,
                'extension'        => null,
                'size_bytes'       => 0,
                'width'            => null,
                'height'           => null,
                'duration_seconds' => null,
                'waveform'         => null,
                'view_mode'        => $a['view_mode'],
                'view_seconds'     => $a['view_seconds'] !== null ? (int) $a['view_seconds'] : null,
                'consumed'         => !empty($a['viewed_at']),
            ];
        }
        return [
            'id'               => (int) $a['id'],
            'kind'             => $a['kind'],
            'url'              => '/uploads/' . ltrim(str_replace('uploads/', '', $a['stored_path']), '/'),
            'thumbnail_url'    => $a['thumbnail_path'] ? '/uploads/' . ltrim(str_replace('uploads/', '', $a['thumbnail_path']), '/') : null,
            'original_name'    => $a['original_name'],
            'mime_type'        => $a['mime_type'],
            'extension'        => $a['extension'],
            'size_bytes'       => (int) $a['size_bytes'],
            'width'            => $a['width'] !== null ? (int) $a['width'] : null,
            'height'           => $a['height'] !== null ? (int) $a['height'] : null,
            'duration_seconds' => $a['duration_seconds'] !== null ? (int) $a['duration_seconds'] : null,
            'waveform'         => $a['waveform_json'] ?? null,
        ];
    }

    private function classifyKind(string $extension, ?string $originalName = null, ?string $mime = null): string
    {
        $extension = strtolower($extension);
        // Recorded voice notes are audio-only .webm files; by extension alone they would be classed as video.
        if (($originalName !== null && preg_match('/^voice-message-/i', $originalName)) || ($mime !== null && str_starts_with(strtolower($mime), 'audio/'))) {
            return 'audio';
        }
        $map = [
            'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'],
            'video'    => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
            'audio'    => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus', 'weba'],
            'archive'  => ['zip', 'rar', '7z'],
            'code'     => ['php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb', 'html', 'css', 'sql', 'sh', 'yml', 'yaml'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'json', 'xml', 'txt', 'md', 'log'],
        ];
        foreach ($map as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }
        return 'file';
    }

    /** بيخزّن ملف واحد مرفوع (UploadedFile) عبر FileUploadService (فئة 'messages')، وبيسجّله كصف message_attachments. */
    private function storeAttachment(int $messageId, $uploaderId, int $conversationId, UploadedFile $file, $viewMode = null, int $viewSeconds = 0): void
    {
        // ملف اترفع بالأجزاء له سقفه الخاص (messaging.chunked.max_bytes) بدل سقف السياسة العام.
        $absoluteMaxKb = $file instanceof \App\Support\ChunkedUploadedFile ? (int) floor((int) config('messaging.chunked.max_bytes') / 1024) : null;
        $stored = $this->uploads->store($file, 'messages', 'conv_' . $conversationId, null, null, $absoluteMaxKb);
        $kind = $this->classifyKind($stored['extension'], $file->getClientOriginalName(), $file->getClientMimeType());
        $absolutePath = public_path($stored['stored_path']);

        [$width, $height] = $kind === 'image' ? $this->imageDimensions($absolutePath) : [null, null];

        // فيديو: صورة مصغّرة (فريم) + المدة — من غيرها الشات بيعرض أيقونة الـ WebView الرمادية بدل صورة الفيديو. ffmpeg اختياري (fail-open).
        $thumbnailPath = null;
        $duration = null;
        if ($kind === 'video') {
            try {
                $probe = app(\App\Services\MediaProbeService::class);
                $thumbnailPath = $probe->videoThumbnail($absolutePath, dirname($stored['stored_path']));
                $duration = $probe->duration($absolutePath);
            } catch (\Throwable $e) {
                \Log::warning('Video thumbnail/duration failed for chat attachment', ['error' => $e->getMessage()]);
            }
        }

        // صوت (رسائل صوتية): إعادة تغليف بدون إعادة ترميز عشان الملف يبقى له مدة وفهرس seek — من غيرهم المستلم كان
        // بينزّل الملف كله ويعمل seek وهمي لكل رسالة صوتية، وده اللي بيخلي التشغيل تقيل ومتقطع.
        $storedSize = $stored['size_bytes'];
        if ($kind === 'audio') {
            try {
                $duration = app(\App\Services\MediaProbeService::class)->normalizeAudio($absolutePath);
                clearstatcache(true, $absolutePath);
                $storedSize = @filesize($absolutePath) ?: $storedSize;
            } catch (\Throwable $e) {
                \Log::warning('Audio normalize failed for chat attachment', ['error' => $e->getMessage()]);
            }
        }

        // "مرة واحدة / تايمر" مسموحة للصور والفيديو بس.
        $viewMode = in_array($viewMode, ['once', 'timer'], true) && in_array($kind, ['image', 'video'], true) ? $viewMode : null;
        $viewSeconds = $viewMode === 'timer' ? max(1, min(60, $viewSeconds ?: 10)) : null;

        DB::table('message_attachments')->insert([
            'view_mode'        => $viewMode,
            'view_seconds'     => $viewSeconds,
            'message_id'       => $messageId,
            'uploader_id'      => $uploaderId,
            'kind'             => $kind,
            'stored_path'      => $stored['stored_path'],
            'thumbnail_path'   => $thumbnailPath,
            'original_name'    => $stored['original_name'],
            'mime_type'        => $stored['mime_type'],
            'extension'        => $stored['extension'],
            'size_bytes'       => $storedSize,
            'width'            => $width,
            'height'           => $height,
            'duration_seconds' => $duration,
            'created_at'       => now(),
        ]);
    }

    private function imageDimensions(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [null, null];
        }
        $size = @getimagesize($absolutePath);
        return $size ? [$size[0], $size[1]] : [null, null];
    }

    /** Rate Limiting / Spam Protection — بيرمي لو اليوزر بعت رسايل كتير أوي في النافذة الزمنية المضبوطة. */
    private function assertNotRateLimited($userId): void
    {
        $cfg = config('messaging.rate_limit', ['max_messages' => 30, 'window_seconds' => 60]);
        $since = date('Y-m-d H:i:s', time() - (int) $cfg['window_seconds']);
        if ($this->messages->countSentSince($userId, $since) >= (int) $cfg['max_messages']) {
            throw new \RuntimeException('You are sending messages too fast. Please wait a moment and try again.');
        }
    }

    /** @param array<int,int|string> $explicitMentionIds ids جاهزة من الـ tag-picker — بتتأكد إنهم فعلاً أعضاء في المحادثة بس */
    private function resolveMentions(int $conversationId, array $explicitMentionIds): array
    {
        if (!$explicitMentionIds) {
            return [];
        }
        $participantIds = array_column($this->allParticipants($conversationId), 'id');
        return array_values(array_intersect(array_map('strval', $explicitMentionIds), array_map('strval', $participantIds)));
    }

    private function isMuted(int $conversationId, $userId): bool
    {
        return $this->conversations->isMuted($conversationId, $userId);
    }

    // -- Message management (edit / delete / restore / undo / pin) ---------

    public function editMessage(int $messageId, $userId, string $newBody): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || (string) $message->sender_id !== (string) $userId) {
            throw new \RuntimeException('Message not found.');
        }
        $newBody = trim($newBody);
        if ($newBody === '') {
            throw new \RuntimeException('Message cannot be empty.');
        }
        $editWindow = (int) config('messaging.edit_window_minutes', 0);
        if ($editWindow > 0 && strtotime($message->created_at) < time() - $editWindow * 60) {
            throw new \RuntimeException('This message can no longer be edited.');
        }

        $this->messages->edit($messageId, $newBody);
        $this->messages->recordHashtags($messageId, $newBody);

        return $this->shapeMessage($this->messages->withRelations($messageId), $userId);
    }

    /** "Delete For Me" — إخفاء لليوزر ده بس. */
    public function deleteForMe(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        $this->messages->hideForUser($messageId, $userId);
    }

    /** "Delete For Everyone (within configurable time window)". */
    public function deleteForEveryone(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $isSender = (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $message->conversation_id, $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot delete this message.');
        }

        $window = (int) config('messaging.delete_for_everyone_window_minutes', 15);
        if ($isSender && !$isConvoAdmin && $window > 0 && strtotime($message->created_at) < time() - $window * 60) {
            throw new \RuntimeException('This message can no longer be deleted for everyone.');
        }

        $this->messages->deleteForEveryone($messageId, $userId);
    }

    /** "Restore Deleted Message" — صاحب الرسالة أو owner/admin بس. */
    public function restoreMessage(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $isSender = (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $message->conversation_id, $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot restore this message.');
        }
        $this->messages->restore($messageId);
    }

    /** "Undo Send" — صاحب الرسالة بس، نافذة زمنية قصيرة، hard delete حقيقي (مش قابل للاسترجاع). */
    public function undoSend(int $messageId, $userId): void
    {
        $message = $this->messages->withRelations($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        if ((string) $message['sender_id'] !== (string) $userId) {
            throw new \RuntimeException('You can only undo your own messages.');
        }
        if ((int) ($message['is_deleted'] ?? 0) === 1) {
            throw new \RuntimeException('This message was already deleted.');
        }

        $window = (int) config('messaging.undo_send_window_seconds', 10);
        if ($window > 0 && strtotime($message['created_at']) < time() - $window) {
            throw new \RuntimeException('This message can no longer be undone.');
        }

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (!empty($attachment['stored_path'])) {
                $this->uploads->delete($attachment['stored_path']);
            }
            if (!empty($attachment['thumbnail_path'])) {
                $this->uploads->delete($attachment['thumbnail_path']);
            }
        }

        $this->messages->unsend($messageId, $userId);
    }

    public function history(int $messageId, $userId): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        return array_map(fn ($v) => ['body' => $v->body, 'edited_at' => $v->edited_at], $this->messages->history($messageId));
    }

    public function setPinned(int $messageId, $userId, bool $pinned): void
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        $this->messages->setPinned($messageId, $pinned);
    }

    public function pinnedMessages(int $conversationId, $userId): array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->pinnedForConversation($conversationId));
    }

    // -- Reactions -----------------------------------------------------------

    public function toggleReaction(int $messageId, $userId, string $emoji): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }

        $existing = DB::table('message_reactions')
            ->where('message_id', $messageId)->where('user_id', $userId)->where('emoji', $emoji)
            ->first();

        if ($existing) {
            DB::table('message_reactions')->where('id', $existing->id)->delete();
        } else {
            DB::table('message_reactions')->insert([
                'message_id' => $messageId, 'user_id' => $userId, 'emoji' => $emoji, 'created_at' => now(),
            ]);
        }

        $shaped = $this->messages->withRelations($messageId);
        return $shaped['reactions'] ?? [];
    }

    // -- Search / mentions -----------------------------------------------

    public function searchMessages($userId, string $query, ?int $conversationId = null): array
    {
        if ($conversationId !== null && !$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->search($userId, $query, $conversationId));
    }

    public function mentionsOfMe($userId): array
    {
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->mentionsOf($userId));
    }

    // -- Group / team conversations ----------------------------------------

    public function createGroupConversation($creatorId, string $groupName, array $memberIds, ?string $description = null): array
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            throw new \RuntimeException('Group name is required.');
        }
        $conversation = $this->conversations->createGroup(mb_substr($groupName, 0, 100), $creatorId, $memberIds);
        $this->groups->afterCreate((int) $conversation->id, $creatorId, $description);
        return $this->threadForUser((int) $conversation->id, $creatorId) ?? [];
    }

    public function addMember(int $conversationId, $actingUserId, $newUserId): void
    {
        $this->groups->addMembers($conversationId, $actingUserId, [$newUserId]);
    }

    public function removeMember(int $conversationId, $actingUserId, $targetUserId): void
    {
        if ((string) $actingUserId === (string) $targetUserId) {
            $this->groups->leave($conversationId, $actingUserId);
            return;
        }
        $this->groups->removeMember($conversationId, $actingUserId, $targetUserId);
    }

    public function setMemberRole(int $conversationId, $actingUserId, $targetUserId, string $role): void
    {
        $this->groups->setRole($conversationId, $actingUserId, $targetUserId, $role);
    }

    public function renameGroup(int $conversationId, $actingUserId, string $groupName): void
    {
        $this->groups->update($conversationId, $actingUserId, ['name' => $groupName]);
    }

    // -- Per-member conversation state (favorite/pin/mute/archive/category) --

    public function toggleMemberFlag(int $conversationId, $userId, string $flag, bool $value): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->conversations->setMemberFlag($conversationId, $userId, $flag, $value);
    }

    public function setCategory(int $conversationId, $userId, ?string $category): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->conversations->setMemberCategory($conversationId, $userId, $category !== '' ? $category : null);
    }

    public function categoriesForUser($userId): array
    {
        return $this->conversations->categoriesForUser($userId);
    }

    // -- Read receipts / typing / presence (polling-based) -----------------

    public function readReceiptsEnabled($userId): bool
    {
        return $this->settings->get('messaging_read_receipts_enabled', 'user', $userId, '1') === '1';
    }

    // -- Chat privacy (متاح لكل الأدوار، مش للطالب بس) -------------------------

    /** @var array<int,bool> كاش per-request: user_id => صورته مخفية؟ */
    private array $avatarHiddenCache = [];

    /** هل المستخدم ده مخفي صورته في الشات؟ (صاحب الصورة بيشوف صورته دايمًا). */
    public function isAvatarHidden(int $ownerId, $viewerId = null): bool
    {
        if ($viewerId !== null && (string) $ownerId === (string) $viewerId) {
            return false;
        }
        if (!array_key_exists($ownerId, $this->avatarHiddenCache)) {
            // مخفية لو قفلها من إعداد الشات، أو من إعداد "صورتي للعامة" (كل البوابات).
            $this->avatarHiddenCache[$ownerId] =
                $this->settings->get('messaging_show_avatar', 'user', $ownerId, '1') === '0'
                || $this->settings->get('profile_photo_public', 'user', $ownerId, '1') === '0';
        }
        return $this->avatarHiddenCache[$ownerId];
    }

    /**
     * يشيل avatar_path من أي مشارك مخفي صورته — الفرونت بيرجع للحروف الأولى
     * تلقائيًا لما avatar_path يبقى null.
     *
     * @param array<int,array<string,mixed>> $participants
     * @return array<int,array<string,mixed>>
     */
    private function applyAvatarPrivacy(array $participants): array
    {
        foreach ($participants as &$p) {
            if (isset($p['id']) && $this->isAvatarHidden((int) $p['id'])) {
                $p['avatar_path'] = null;
            }
        }
        unset($p);
        return $participants;
    }

    /**
     * يعلّم المشاركين اللي حساباتهم اتحذفت (is_deleted) ويخفي صورتهم وإيميلهم — الاسم بيفضل عشان المحادثة تفضل مفهومة.
     * @param array<int,array<string,mixed>> $participants
     * @return array<int,array<string,mixed>>
     */
    private function flagDeleted(array $participants): array
    {
        foreach ($participants as &$p) {
            $p['is_deleted'] = !empty($p['deleted_at']);
            unset($p['deleted_at']);
            if ($p['is_deleted']) {
                $p['avatar_path'] = null;
                $p['email'] = '';
            }
        }
        unset($p);

        return $participants;
    }

    /** @throws \RuntimeException لو المحادثة مباشرة وأي طرف حظر التاني. */
    private function assertNotBlocked(int $conversationId, int $userId): void
    {
        $peerId = $this->safety->directPeerId($conversationId, $userId);
        if ($peerId !== null) {
            $this->assertNotBlockedPair($userId, $peerId);
        }
    }

    private function assertNotBlockedPair(int $userId, int $peerId): void
    {
        $dir = $this->safety->blockDirection($userId, $peerId);
        if ($dir === 'me') {
            throw new \RuntimeException('You blocked this user. Unblock them to send messages.');
        }
        if ($dir === 'peer') {
            throw new \RuntimeException('You cannot send messages to this user.');
        }
    }

    /** محادثة مباشرة (مش جروب) والطرف التاني فيها حسابه محذوف؟ */
    private function directPeerDeleted(int $conversationId): bool
    {
        if (DB::table('conversations')->where('id', $conversationId)->value('is_group')) {
            return false;
        }

        return DB::table('conversation_participants as cp')
            ->join('users as u', 'u.id', '=', 'cp.user_id')
            ->where('cp.conversation_id', $conversationId)
            ->whereNotNull('u.deleted_at')
            ->exists();
    }

    /**
     * صاحب الحساب اتحذف (أو اترجّع): سطر نظام في كل محادثاته + إشعار لكل طرف في محادثة مباشرة.
     * الحالة الفعلية (peer_deleted) بتتحسب لحظيًا من users.deleted_at، فده إضافة للتنبيه مش مصدر الحقيقة.
     */
    public function announceAccountEvent(int $userId, string $event): void
    {
        $name = (string) (DB::table('users')->where('id', $userId)->value('full_name') ?? '');
        $convs = DB::table('conversation_participants as cp')
            ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $userId)
            ->get(['c.id', 'c.is_group']);

        foreach ($convs as $c) {
            $cid = (int) $c->id;
            $meta = ['event' => $event, 'actor_id' => $userId, 'actor_name' => $name];
            $this->messages->create($cid, $userId, $event === 'account_deleted' ? "{$name} deleted their account" : "{$name}'s account was restored", null, null, null, 'system', $meta);
            $this->conversations->touchUpdatedAt($cid);

            if ($c->is_group) {
                continue;
            }
            $peerId = DB::table('conversation_participants')->where('conversation_id', $cid)->where('user_id', '!=', $userId)->value('user_id');
            if (!$peerId) {
                continue;
            }
            $ar = (DB::table('users')->where('id', $peerId)->value('preferred_language') ?? 'ar') === 'ar';
            $deleted = $event === 'account_deleted';
            try {
                $this->notifications->notify(
                    (int) $peerId,
                    'message_account_status',
                    $deleted ? ($ar ? "{$name} حذف حسابه" : "{$name} deleted their account") : ($ar ? "{$name} استرجع حسابه" : "{$name} restored their account"),
                    $deleted ? ($ar ? 'لم يعد بإمكانك مراسلته. تقدر تقرأ المحادثة القديمة فقط.' : 'You can no longer message them. You can still read the old conversation.')
                             : ($ar ? 'تقدر تكمل المحادثة معه من جديد.' : 'You can continue the conversation again.'),
                    $this->messagesLinkFor($peerId, $cid)
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{read_receipts_enabled:bool,show_avatar:bool} */
    public function chatPrivacyFor($userId): array
    {
        return [
            'read_receipts_enabled' => $this->readReceiptsEnabled($userId),
            'show_avatar'           => $this->settings->get('messaging_show_avatar', 'user', $userId, '1') === '1',
        ];
    }

    /**
     * @param array{read_receipts_enabled?:mixed,show_avatar?:mixed} $input أي مفتاح مش مبعوت بيفضل زي ما هو
     * @return array{read_receipts_enabled:bool,show_avatar:bool}
     */
    public function updateChatPrivacy($userId, array $input): array
    {
        if (array_key_exists('read_receipts_enabled', $input)) {
            $this->settings->set('messaging_read_receipts_enabled', filter_var($input['read_receipts_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'user', $userId);
        }
        if (array_key_exists('show_avatar', $input)) {
            $this->settings->set('messaging_show_avatar', filter_var($input['show_avatar'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'user', $userId);
            unset($this->avatarHiddenCache[(int) $userId]);
        }
        return $this->chatPrivacyFor($userId);
    }

    public function markConversationRead(int $conversationId, $userId): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->markMessagesRead($conversationId, $userId);
    }

    /** بتحدد كل الرسايل اللي لسه معلّمة unread من مرسلين تانيين في المحادثة كـ delivered+read لليوزر ده. */
    private function markMessagesRead(int $conversationId, $userId): void
    {
        $now = now();
        $readAt = $this->readReceiptsEnabled($userId) ? $now : null;

        $messageIds = DB::table('messages')->where('conversation_id', $conversationId)->where('sender_id', '!=', $userId)->pluck('id');
        foreach ($messageIds as $mid) {
            $existing = DB::table('message_read_receipts')->where('message_id', $mid)->where('user_id', $userId)->first();
            DB::table('message_read_receipts')->updateOrInsert(
                ['message_id' => $mid, 'user_id' => $userId],
                ['delivered_at' => $existing->delivered_at ?? $now, 'read_at' => $readAt]
            );
        }

        $this->conversations->markRead($conversationId, $userId);
        $this->notifications->markConversationNotificationsRead($userId, $conversationId);
    }

    /** حالة القراءة/التسليم لكل مستلم لرسالة واحدة (Sent/Delivered/Read). */
    public function readReceipts(int $messageId, $userId): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        return DB::table('message_read_receipts as rr')
            ->join('users as u', 'u.id', '=', 'rr.user_id')
            ->where('rr.message_id', $messageId)
            ->whereNotNull('rr.read_at')
            ->select('rr.user_id', 'u.full_name', 'rr.delivered_at', 'rr.read_at')
            ->get()
            ->all();
    }

    public function heartbeat($userId): void
    {
        DB::table('user_presence')->updateOrInsert(
            ['user_id' => $userId],
            ['last_seen_at' => now()]
        );
    }

    /** بيضبط/يمسح "بيكتب في المحادثة X" — مرّر $conversationId = null عشان تمسح. */
    public function setTyping($userId, ?int $conversationId): void
    {
        DB::table('user_presence')->updateOrInsert(
            ['user_id' => $userId],
            [
                'last_seen_at'      => now(),
                'is_typing_in'      => $conversationId,
                'typing_started_at' => $conversationId ? now() : null,
            ]
        );
    }

    /** مين (غير $viewerId) بيكتب دلوقتي في $conversationId، جوّه TTL الكتابة المضبوط. */
    public function typingIn(int $conversationId, $viewerId): array
    {
        $ttl = (int) config('messaging.presence.typing_ttl_s', 6);
        return DB::table('user_presence as up')
            ->join('users as u', 'u.id', '=', 'up.user_id')
            ->where('up.is_typing_in', $conversationId)
            ->where('up.user_id', '!=', $viewerId)
            ->where('up.typing_started_at', '>=', now()->subSeconds($ttl))
            ->select('up.user_id', 'u.full_name')
            ->get()
            ->all();
    }

    /** حالة أونلاين/آخر ظهور لمجموعة من الـ user ids. */
    public function presenceFor(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $threshold = (int) config('messaging.presence.online_threshold_s', 30);
        $rows = DB::table('user_presence')->whereIn('user_id', $userIds)->get(['user_id', 'last_seen_at']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->user_id] = [
                'last_seen_at' => $row->last_seen_at,
                'is_online'    => $row->last_seen_at && strtotime($row->last_seen_at) >= time() - $threshold,
            ];
        }
        return $result;
    }

    /** "إيه اللي اتغير من آخر poll" — رسايل جديدة في $conversationId بعد $sinceMessageId + مين بيكتب دلوقتي. */
    public function poll(int $conversationId, $userId, int $sinceMessageId = 0, ?string $changesSince = null): array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $rows = DB::select(
            "SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                    p.body AS parent_body, pu.full_name AS parent_sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN messages p ON p.id = m.parent_message_id
             LEFT JOIN users pu ON pu.id = p.sender_id
             WHERE m.conversation_id = ? AND m.id > ?
               AND m.id NOT IN (SELECT message_id FROM message_hidden_for_user WHERE user_id = ?)
             ORDER BY m.id ASC",
            [$conversationId, $sinceMessageId, $userId]
        );
        $rows = array_map(fn ($r) => (array) $r, $rows);
        // attachments/reactions/mentions — poll used to skip this, so any message
        // with a file/video arrived as an empty bubble until the page was reloaded.
        $rows = $this->messages->attachRelations($rows);

        // رسائل أقدم من since_id اتحذفت/اتعدّلت من بعد آخر poll (حذف للجميع، إلغاء الإرسال، تعديل) —
        // من غير الجزء ده الطرف التاني مكانش بيشوف أي تغيير في رسالة قديمة غير بعد refresh.
        $updated = [];
        if ($changesSince !== null && ($ts = strtotime($changesSince)) !== false) {
            $from = date('Y-m-d H:i:s', $ts - 5); // هامش أمان؛ الواجهة بتدمج بالـ id فمفيش مشكلة لو اتكرر
            $changed = DB::select(
                "SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                        p.body AS parent_body, pu.full_name AS parent_sender_name
                 FROM messages m
                 INNER JOIN users u ON u.id = m.sender_id
                 LEFT JOIN messages p ON p.id = m.parent_message_id
                 LEFT JOIN users pu ON pu.id = p.sender_id
                 WHERE m.conversation_id = ? AND m.id <= ?
                   AND (m.deleted_at >= ? OR m.edited_at >= ?)
                   AND m.id NOT IN (SELECT message_id FROM message_hidden_for_user WHERE user_id = ?)
                 ORDER BY m.id ASC
                 LIMIT 200",
                [$conversationId, $sinceMessageId, $from, $from, $userId]
            );
            $changed = $this->messages->attachRelations(array_map(fn ($r) => (array) $r, $changed));
            $updated = array_map(fn ($m) => $this->shapeMessage($m, $userId), $changed);
        }

        $recentMine = DB::table('messages')
            ->where('conversation_id', $conversationId)->where('sender_id', $userId)
            ->orderByDesc('id')->limit(100)->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'messages'    => array_map(fn ($m) => $this->shapeMessage($m, $userId), $rows),
            'updated'     => $updated,
            'reactions'   => (object) $this->recentReactionsMap($conversationId),
            'polls'       => (object) $this->recentPollsMap($conversationId, $userId),
            'receipts'    => (object) $this->receiptStatusMap($conversationId, $recentMine),
            'server_time' => now()->format('Y-m-d H:i:s'),
            'typing'      => $this->typingIn($conversationId, $userId),
        ];
    }

    /**
     * رياكشنات آخر ~80 رسالة في المحادثة (message_id => [{emoji,count,users}]) — بتتبعت مع كل poll
     * عشان إضافة/إزالة رياكشن من الطرف التاني تظهر فورًا (الحذف مش بيسيب أثر يتتبّع بـ timestamp).
     * الرسايل من غير رياكشن بترجع [] عشان الواجهة تمسح أي رياكشن اتشال.
     */
    private function recentReactionsMap(int $conversationId): array
    {
        $ids = DB::table('messages')->where('conversation_id', $conversationId)
            ->orderByDesc('id')->limit(80)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, []);
        $rows = DB::table('message_reactions as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereIn('r.message_id', $ids)
            ->orderBy('r.id')
            ->select('r.message_id', 'r.emoji', 'r.user_id', 'u.full_name')
            ->get();
        $by = [];
        foreach ($rows as $r) {
            $by[$r->message_id][$r->emoji]['emoji'] = $r->emoji;
            $by[$r->message_id][$r->emoji]['count'] = ($by[$r->message_id][$r->emoji]['count'] ?? 0) + 1;
            $by[$r->message_id][$r->emoji]['users'][] = ['id' => $r->user_id, 'full_name' => $r->full_name];
        }
        foreach ($by as $mid => $list) {
            $out[$mid] = array_values($list);
        }
        return $out;
    }

    // -- Polls -----------------------------------------------------------

    /** @param array<int,string> $options 2+ خيار */
    public function createPoll(int $conversationId, $userId, string $question, array $options): array
    {
        $options = array_values(array_filter(array_map('trim', $options), fn ($o) => $o !== ''));
        if (trim($question) === '' || count($options) < 2) {
            throw new \RuntimeException('A poll needs a question and at least two options.');
        }
        return $this->sendMessage($conversationId, $userId, '', [
            'message_type' => 'poll',
            'metadata'     => ['question' => trim($question), 'options' => $options],
        ]);
    }

    // -- Location sharing (current + live, WhatsApp style) ----------------

    /** Allowed live-share durations in minutes: 15 min / 1 hour / 8 hours. */
    private const LIVE_LOCATION_MINUTES = [15, 60, 480];

    /** @return array{0: float, 1: float, 2: ?int} lat, lng, accuracy(m) */
    private function validLocation($lat, $lng, $accuracy = null): array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new \RuntimeException('Invalid location.');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \RuntimeException('Invalid location.');
        }
        $acc = is_numeric($accuracy) ? (int) max(0, min(100000, round((float) $accuracy))) : null;
        return [round($lat, 6), round($lng, 6), $acc];
    }

    /** One-off "current location" message. */
    public function sendLocation(int $conversationId, $userId, $lat, $lng, $accuracy = null): array
    {
        [$lat, $lng, $acc] = $this->validLocation($lat, $lng, $accuracy);
        // The body is a plain Maps link so older clients (and notifications) still show something useful.
        return $this->sendMessage($conversationId, $userId, "📍 https://www.google.com/maps?q={$lat},{$lng}", [
            'message_type' => 'location',
            'metadata'     => ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc],
        ]);
    }

    /** Starts a live-location share. A new share ends any earlier live share of the same user in this chat. */
    public function startLiveLocation(int $conversationId, $userId, $lat, $lng, $accuracy = null, int $minutes = 15): array
    {
        [$lat, $lng, $acc] = $this->validLocation($lat, $lng, $accuracy);
        if (!in_array($minutes, self::LIVE_LOCATION_MINUTES, true)) {
            throw new \RuntimeException('Invalid live location duration.');
        }
        $now = time();
        $message = $this->sendMessage($conversationId, $userId, '📍 Live location', [
            'message_type' => 'live_location',
            'metadata'     => [
                'lat' => $lat, 'lng' => $lng, 'accuracy' => $acc,
                'started_ts' => $now, 'expires_ts' => $now + $minutes * 60, 'updated_ts' => $now,
                'duration_min' => $minutes, 'ended' => false,
            ],
        ]);
        $newId = (int) ($message['id'] ?? 0);
        $older = DB::table('messages')
            ->where('conversation_id', $conversationId)->where('sender_id', $userId)
            ->where('message_type', 'live_location')->where('is_deleted', 0)->where('id', '<', $newId)
            ->orderByDesc('id')->limit(20)->get(['id', 'metadata']);
        foreach ($older as $row) {
            $meta = $row->metadata ? json_decode($row->metadata, true) : null;
            if (is_array($meta) && empty($meta['ended'])) {
                $meta['ended'] = true;
                $meta['ended_ts'] = $now;
                DB::table('messages')->where('id', $row->id)->update(['metadata' => json_encode($meta), 'edited_at' => now()]);
            }
        }
        return $message;
    }

    /** Sender pushes a fresh position. Returns the updated message (metadata.ended = true once finished). */
    public function updateLiveLocation(int $messageId, $userId, $lat, $lng, $accuracy = null): array
    {
        [$lat, $lng, $acc] = $this->validLocation($lat, $lng, $accuracy);
        $row = $this->messages->find($messageId);
        if (!$row || $row->message_type !== 'live_location' || (string) $row->sender_id !== (string) $userId) {
            throw new \RuntimeException('Live location not found.');
        }
        $meta = $row->metadata ? json_decode($row->metadata, true) : [];
        $now = time();
        if (!empty($row->is_deleted) || !empty($meta['ended']) || $now >= (int) ($meta['expires_ts'] ?? 0)) {
            if (empty($meta['ended'])) {
                $meta['ended'] = true;
                $meta['ended_ts'] = $now;
                DB::table('messages')->where('id', $messageId)->update(['metadata' => json_encode($meta), 'edited_at' => now()]);
            }
        } else {
            $meta['lat'] = $lat;
            $meta['lng'] = $lng;
            $meta['accuracy'] = $acc;
            $meta['updated_ts'] = $now;
            // edited_at (not is_edited) is what makes poll() hand this row to the other side again.
            DB::table('messages')->where('id', $messageId)->update(['metadata' => json_encode($meta), 'edited_at' => now()]);
        }
        return $this->shapeMessage($this->messages->withRelations($messageId), $userId);
    }

    public function stopLiveLocation(int $messageId, $userId): array
    {
        $row = $this->messages->find($messageId);
        if (!$row || $row->message_type !== 'live_location' || (string) $row->sender_id !== (string) $userId) {
            throw new \RuntimeException('Live location not found.');
        }
        $meta = $row->metadata ? json_decode($row->metadata, true) : [];
        if (empty($meta['ended'])) {
            $meta['ended'] = true;
            $meta['ended_ts'] = time();
            DB::table('messages')->where('id', $messageId)->update(['metadata' => json_encode($meta), 'edited_at' => now()]);
        }
        return $this->shapeMessage($this->messages->withRelations($messageId), $userId);
    }

    /**
     * تصويت (اختيار واحد). الضغط على نفس الخيار تاني بيشيل صوتك (زي الواتساب)،
     * والضغط على خيار تاني بينقل صوتك. بيرجّع ملخص الاستفتاء كامل للمستخدم ده.
     */
    public function votePoll(int $messageId, $userId, int $optionIndex): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || $message->message_type !== 'poll' || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Poll not found.');
        }
        $meta = $message->metadata ? json_decode($message->metadata, true) : ['options' => []];
        if (!isset($meta['options'][$optionIndex])) {
            throw new \RuntimeException('Invalid poll option.');
        }

        DB::transaction(function () use ($messageId, $userId, $optionIndex) {
            $current = DB::table('message_poll_votes')
                ->where('message_id', $messageId)->where('user_id', $userId)
                ->lockForUpdate()->pluck('option_index')->map(fn ($i) => (int) $i)->all();
            DB::table('message_poll_votes')->where('message_id', $messageId)->where('user_id', $userId)->delete();
            if (!in_array($optionIndex, $current, true)) {
                MessagePollVote::create(['message_id' => $messageId, 'user_id' => $userId, 'option_index' => $optionIndex]);
            }
        });

        return $this->pollSummary($messageId, $userId);
    }

    /** @return array<int,int> option_index => عدد الأصوات */
    public function pollResults(int $messageId): array
    {
        return $this->pollSummary($messageId, 0)['pollResults'];
    }

    /**
     * ملخص استفتاء لمستخدم معيّن: pollResults (index => count)، pollTotal، myVote (index أو null).
     *
     * @return array{pollResults: array<int,int>, pollTotal: int, myVote: ?int}
     */
    public function pollSummary(int $messageId, $viewerId): array
    {
        $rows = DB::table('message_poll_votes')->where('message_id', $messageId)
            ->select('option_index', DB::raw('COUNT(*) as votes'))->groupBy('option_index')->get();
        $results = [];
        $total = 0;
        foreach ($rows as $row) {
            $results[(int) $row->option_index] = (int) $row->votes;
            $total += (int) $row->votes;
        }
        $mine = DB::table('message_poll_votes')->where('message_id', $messageId)->where('user_id', $viewerId)->value('option_index');
        return [
            'pollResults' => (object) $results,
            'pollTotal'   => $total,
            'myVote'      => $mine !== null ? (int) $mine : null,
        ];
    }

    /** ملخص آخر ~30 استفتاء في المحادثة (message_id => summary) — بيتبعت مع كل poll عشان الأصوات الجديدة تظهر عند الكل. */
    private function recentPollsMap(int $conversationId, $viewerId): array
    {
        $ids = DB::table('messages')
            ->where('conversation_id', $conversationId)->where('message_type', 'poll')->whereNull('deleted_at')
            ->orderByDesc('id')->limit(30)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->pollSummary($id, $viewerId);
        }
        return $out;
    }

    /**
     * مين صوّت على إيه (للقايمة اللي بتفتح تحت الاستفتاء).
     *
     * @return array{question: string, total: int, options: array<int,array{index:int,text:string,count:int,voters:array}>}
     */
    public function pollVoters(int $messageId, $userId): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || $message->message_type !== 'poll' || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Poll not found.');
        }
        $meta = $message->metadata ? json_decode($message->metadata, true) : ['question' => '', 'options' => []];
        $rows = DB::table('message_poll_votes as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.message_id', $messageId)
            ->orderBy('v.created_at')
            ->select('v.option_index', 'u.id', 'u.full_name', 'u.avatar_path', 'v.created_at')
            ->get();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->option_index][] = [
                'id'         => (int) $r->id,
                'full_name'  => $r->full_name,
                'avatar'     => $this->isAvatarHidden((int) $r->id, $userId) ? null : ($r->avatar_path ?: null),
                'is_me'      => (string) $r->id === (string) $userId,
                'voted_at'   => $r->created_at,
            ];
        }
        $options = [];
        foreach (($meta['options'] ?? []) as $i => $text) {
            $voters = $by[$i] ?? [];
            $options[] = ['index' => (int) $i, 'text' => $text, 'count' => count($voters), 'voters' => $voters];
        }
        return ['question' => $meta['question'] ?? '', 'total' => count($rows), 'options' => $options];
    }

    // -- Attachments -----------------------------------------------------

    /** Secure File Access — المرفق يتحمّل بس لو اليوزر عضو في محادثة الرسالة اللي المرفق ده تابعلها. */
    public function attachmentAuthorized(int $attachmentId, $userId): ?array
    {
        $row = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', $attachmentId)
            ->select('a.*', 'm.conversation_id')
            ->first();

        if (!$row || !$this->conversations->isParticipant((int) $row->conversation_id, $userId)) {
            return null;
        }
        return (array) $row;
    }

    /**
     * "مرة واحدة / تايمر": بيحجز المرفق لأول مستلم يفتحه (UPDATE ذرّي) ويرجّع بياناته عشان الكنترولر يبعت الملف ويمسحه.
     * المرسل نفسه مايقدرش يفتحه، ولا أي حد بعد أول فتح.
     * @return array|null null = مش موجود/مش مسموح، ['row'=>..., 'gone'=>true] لو اتفتح قبل كده
     */
    public function claimViewOnce(int $attachmentId, $userId): ?array
    {
        $row = $this->attachmentAuthorized($attachmentId, $userId);
        if (!$row || empty($row['view_mode'])) {
            return null;
        }
        if ((string) $row['uploader_id'] === (string) $userId) {
            return null; // المرسل مالوش فتح
        }
        $claimed = DB::table('message_attachments')
            ->where('id', $attachmentId)
            ->whereNull('viewed_at')
            ->update(['viewed_at' => now(), 'viewed_by' => $userId]);
        if ($claimed !== 1) {
            return ['row' => $row, 'gone' => true];
        }
        return ['row' => $row, 'gone' => false];
    }

    /** بيمسح ملفات مرفق "مرة واحدة" من السيرفر بعد ما اتسلّم (الصف بيفضل علشان يظهر "اتفتحت"). */
    public function purgeViewOnceFiles(array $row): void
    {
        foreach (['stored_path', 'thumbnail_path'] as $k) {
            if (!empty($row[$k])) {
                $this->uploads->delete($row[$k]);
            }
        }
    }

    /** حذف مرفق — صاحب الرسالة أو owner/admin بس. */
    public function deleteAttachment(int $attachmentId, $userId): void
    {
        $row = $this->attachmentAuthorized($attachmentId, $userId);
        if (!$row) {
            throw new \RuntimeException('Attachment not found.');
        }
        $message = $this->messages->find((int) $row['message_id']);
        $isSender = $message && (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $row['conversation_id'], $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot delete this attachment.');
        }

        if (!empty($row['stored_path'])) {
            $this->uploads->delete($row['stored_path']);
        }
        if (!empty($row['thumbnail_path'])) {
            $this->uploads->delete($row['thumbnail_path']);
        }
        DB::table('message_attachments')->where('id', $attachmentId)->delete();
    }

    // =====================================================================
    // -- Admin Messaging Oversight (بند 25 batch 5) ------------------------
    // =====================================================================
    // كل ميثود تحت دي platform-admin-only (الـ caller — AdminMessagingOversightApiController
    // + middleware uip.admin — هو اللي بيتأكد من ده) وبتتخطى عمدًا فحوصات
    // العضوية/الملكية اللي النظائر فوق بتفرضها: الأدمن يقدر يشوف ويشرف على
    // أي محادثة في المنصة، مش بس اللي هو عضو فيها. كل تعديل بيتسجل audit
    // من الكنترولر (AuditLogService) زي القديمة بالظبط.

    /** @return array{items:array,total:int,page:int,per_page:int} لشاشة Messaging Oversight، عبر كل البورتالات. */
    public function adminSearchConversations(array $filters = []): array
    {
        return $this->conversations->adminSearch($filters);
    }

    /** عرض ثريد قراءة-فقط لشاشة الإشراف — مفيش فحص عضوية، ومفيش تأثير markRead. */
    public function adminThread(int $conversationId): ?array
    {
        $found = $this->conversations->adminFind($conversationId);
        if (!$found) {
            return null;
        }

        $messages = array_map(
            fn ($m) => $this->shapeMessage($m, null),
            $this->messages->forConversationAdmin($conversationId)
        );

        return [
            'conversation' => $found['conversation'],
            'participants' => $found['participants'],
            'messages'     => $messages,
        ];
    }

    /**
     * حذف إجباري لرسالة للجميع — نسخة الأدمن من deleteForEveryone() من
     * غير فحص مرسل/عضو-إداري ومن غير نافذة زمنية، لإزالة محتوى لقاه
     * الأدمن على Messaging Oversight. نفس سلوك soft-delete/tombstone
     * بتاع النسخة اللي لليوزر (قابل للاسترجاع عبر restoreMessage() من
     * أي عضو، زي أي deleteForEveryone() تانية).
     * @throws \RuntimeException لو الرسالة مش موجودة
     */
    public function adminDeleteMessage(int $messageId, $adminUserId): int
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $conversationId = (int) $message->conversation_id;

        $this->messages->deleteForEveryone($messageId, $adminUserId);

        return $conversationId;
    }

    /**
     * حذف إجباري لمرفق — نسخة الأدمن من deleteAttachment() من غير فحص
     * رافع/عضو-إداري، لإزالة ملف غير لائق لقاه الأدمن على Messaging
     * Oversight. حذف فعلي (hard delete)، زي النسخة اللي لليوزر.
     * @throws \RuntimeException لو المرفق مش موجود
     */
    public function adminDeleteAttachment(int $attachmentId, $adminUserId): array
    {
        $row = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', $attachmentId)
            ->select('a.*', 'm.conversation_id')
            ->first();
        if (!$row) {
            throw new \RuntimeException('Attachment not found.');
        }
        $row = (array) $row;

        if (!empty($row['stored_path'])) {
            $this->uploads->delete($row['stored_path']);
        }
        if (!empty($row['thumbnail_path'])) {
            $this->uploads->delete($row['thumbnail_path']);
        }

        DB::table('message_attachments')->where('id', $attachmentId)->delete();

        return [
            'conversation_id' => (int) $row['conversation_id'],
            'message_id'      => (int) $row['message_id'],
            'attachment_id'   => $attachmentId,
        ];
    }

    /** @return array<string,mixed> مشكّلة لداشبورد Messaging Analytics */
    public function adminAnalytics(): array
    {
        return $this->conversations->adminAnalyticsSummary();
    }
}
