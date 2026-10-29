<?php

namespace App\Services;

use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WhatsApp-style group administration on top of the existing conversations tables:
 *  - group info (name / description / photo) and who may edit it
 *  - "who can send" (everyone / admins only = a closed group), per-member send restriction
 *  - allowed message types (text, image, video, audio/voice, file, poll, gif, sticker)
 *  - shareable invite link (enable / disable / reset) with optional join approval + join requests
 *  - admins / ownership transfer / leave / delete
 *  - system messages ("X added Y", "Z joined via link", ...) stored as messages with message_type='system'
 *
 * Roles: owner > admin > member. Owner/admin are never limited by "only admins send" or the
 * allowed-types list (they control those settings).
 * Student-group chats (student_group_id set) are managed by the student group itself, so they
 * get no invite link.
 */
class GroupChatService
{
    /** Message types an admin can switch on/off for members. */
    public const TYPES = ['text', 'image', 'video', 'audio', 'file', 'poll', 'gif', 'sticker'];

    private const MAX_NAME = 100;
    private const MAX_DESCRIPTION = 500;
    private const DEFAULT_MAX_MEMBERS = 1024;

    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private NotificationService $notifications,
        private FileUploadService $uploads,
        private RoleService $roles,
    ) {
    }

    // -- Lookups ---------------------------------------------------------------

    /** @return object|null raw conversations row */
    private function row(int $conversationId): ?object
    {
        return DB::table('conversations')->where('id', $conversationId)->first() ?: null;
    }

    private function groupRowOrFail(int $conversationId): object
    {
        $row = $this->row($conversationId);
        if (!$row || !$row->is_group) {
            throw new \RuntimeException('Group not found.');
        }
        return $row;
    }

    private function roleOf(int $conversationId, $userId): ?string
    {
        return $this->conversations->memberRole($conversationId, $userId);
    }

    private function isAdminRole(?string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    private function assertMember(int $conversationId, $userId): string
    {
        $role = $this->roleOf($conversationId, $userId);
        if ($role === null) {
            throw new \RuntimeException('Group not found.');
        }
        return $role;
    }

    private function assertAdmin(int $conversationId, $userId): string
    {
        $role = $this->assertMember($conversationId, $userId);
        if (!$this->isAdminRole($role)) {
            throw new \RuntimeException('Only group admins can do that.');
        }
        return $role;
    }

    private function userName(int $userId): string
    {
        return (string) (DB::table('users')->where('id', $userId)->value('full_name') ?? '');
    }

    /** @param array<int,string>|null $types */
    private function normalizeTypes($types): ?array
    {
        if ($types === null) {
            return null;
        }
        $types = array_values(array_unique(array_filter(array_map('strval', (array) $types), fn ($t) => in_array($t, self::TYPES, true))));
        // text is always allowed in practice? No — an admin may want a media-only or poll-only group,
        // but a group where nothing can be sent would be pointless; require at least one type.
        if (!$types) {
            throw new \RuntimeException('Choose at least one allowed message type.');
        }
        // Everything allowed = same as "no restriction".
        if (count($types) === count(self::TYPES)) {
            return null;
        }
        return $types;
    }

    /** @return array<int,string> */
    private function decodeTypes($raw): array
    {
        if ($raw === null || $raw === '') {
            return self::TYPES;
        }
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($arr) || !$arr) {
            return self::TYPES;
        }
        return array_values(array_intersect(self::TYPES, array_map('strval', $arr)));
    }

    // -- Settings snapshot for the thread --------------------------------------

    /**
     * Group block added to the thread's `conversation`. `settings`/invite details are only sent to
     * the people allowed to use them; every member gets the effective `can_send` + `allowed_types`.
     *
     * @return array<string,mixed>
     */
    public function threadExtras(int $conversationId, $userId): array
    {
        $row = $this->row($conversationId);
        if (!$row || !$row->is_group) {
            return [];
        }
        $role = $this->roleOf($conversationId, $userId);
        $isAdmin = $this->isAdminRole($role);
        $restricted = (bool) DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', $userId)->value('send_restricted');
        $allowed = $this->decodeTypes($row->allowed_types);

        $canSend = true;
        $blockReason = null;
        if (!$isAdmin) {
            if ($restricted) {
                $canSend = false;
                $blockReason = 'restricted';
            } elseif ($row->only_admins_send) {
                $canSend = false;
                $blockReason = 'admins_only';
            }
        }

        $extras = [
            'description'     => $row->description,
            'avatar_path'     => $row->avatar_path,
            'created_at'      => $row->created_at,
            'can_send'        => $canSend,
            'send_block'      => $blockReason,
            // admins/owner bypass the type list, so for them everything is allowed in the composer
            'allowed_types'   => $isAdmin ? self::TYPES : $allowed,
            'can_edit_info'   => $isAdmin || (bool) $row->members_can_edit_info,
            'can_add_members' => $isAdmin || (bool) $row->members_can_add,
            'is_admin'        => $isAdmin,
            'managed_by_student_group' => $row->student_group_id !== null,
            'pending_requests' => $isAdmin
                ? (int) DB::table('conversation_join_requests')->where('conversation_id', $conversationId)->where('status', 'pending')->count()
                : 0,
        ];

        if ($isAdmin) {
            $extras['settings'] = [
                'only_admins_send'      => (bool) $row->only_admins_send,
                'members_can_edit_info' => (bool) $row->members_can_edit_info,
                'members_can_add'       => (bool) $row->members_can_add,
                'join_approval'         => (bool) $row->join_approval,
                'allowed_types'         => $allowed,
                'invite_enabled'        => (bool) $row->invite_enabled,
                'max_members'           => (int) ($row->max_members ?: self::DEFAULT_MAX_MEMBERS),
            ];
        }
        // the link: admins always, members only when they are allowed to add people
        if ($row->student_group_id === null && ($isAdmin || $row->members_can_add) && $row->invite_enabled && $row->invite_token) {
            $extras['invite_token'] = $row->invite_token;
        }

        return $extras;
    }

    /** Per-member group flags for the members list (admin view). @return array<int,array<string,mixed>> user_id => flags */
    public function memberFlags(int $conversationId): array
    {
        $rows = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->get(['user_id', 'role', 'send_restricted', 'joined_at']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->user_id] = [
                'role'            => $r->role,
                'send_restricted' => (bool) $r->send_restricted,
                'joined_at'       => $r->joined_at,
            ];
        }
        return $out;
    }

    // -- Send-time enforcement -------------------------------------------------

    /**
     * Called by MessagingService::sendMessage() for every message. No-op for 1:1 chats.
     *
     * @param array<int,string> $attachmentKinds classified kinds of the uploaded files (image/video/audio/...)
     */
    public function assertCanSend(int $conversationId, $userId, string $messageType, string $body, array $attachmentKinds): void
    {
        $row = $this->row($conversationId);
        if (!$row || !$row->is_group) {
            return;
        }
        $role = $this->roleOf($conversationId, $userId);
        if ($role === null) {
            throw new \RuntimeException('Conversation not found.');
        }
        if ($this->isAdminRole($role)) {
            return;
        }
        $restricted = (bool) DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', $userId)->value('send_restricted');
        if ($restricted) {
            throw new \RuntimeException('An admin has restricted you from sending messages in this group.');
        }
        if ($row->only_admins_send) {
            throw new \RuntimeException('Only admins can send messages in this group.');
        }

        $allowed = $this->decodeTypes($row->allowed_types);
        if (count($allowed) === count(self::TYPES)) {
            return;
        }
        $needed = [];
        if ($messageType === 'poll') {
            $needed[] = 'poll';
        } elseif ($messageType === 'gif') {
            $needed[] = 'gif';
        } elseif ($messageType === 'sticker') {
            $needed[] = 'sticker';
        } elseif (in_array($messageType, ['location', 'live_location'], true)) {
            $needed[] = 'text'; // location sharing follows the "text messages" rule of the group
        } elseif ($messageType === 'text' && trim($body) !== '') {
            $needed[] = 'text';
        }
        foreach ($attachmentKinds as $kind) {
            // classifyKind() returns image/video/audio/archive/code/document/file → anything non-media is a "file"
            $needed[] = in_array($kind, ['image', 'video', 'audio'], true) ? $kind : 'file';
        }
        $labels = ['text' => 'text messages', 'image' => 'images', 'video' => 'videos', 'audio' => 'voice/audio messages',
                   'file' => 'files', 'poll' => 'polls', 'gif' => 'GIFs', 'sticker' => 'stickers'];
        foreach (array_unique($needed) as $type) {
            if (!in_array($type, $allowed, true)) {
                throw new \RuntimeException('This group does not allow ' . ($labels[$type] ?? $type) . '.');
            }
        }
    }

    // -- System messages -------------------------------------------------------

    /**
     * @param array<string,mixed> $meta extra data the client uses to localise the line
     */
    public function systemMessage(int $conversationId, $actorId, string $event, array $meta = []): void
    {
        $meta = array_merge(['event' => $event], $meta);
        $actorName = $this->userName((int) $actorId);
        $meta['actor_id'] = (int) $actorId;
        $meta['actor_name'] = $actorName;
        // English fallback in `body` (inbox preview / old clients); the UI localises from metadata.
        $this->messages->create($conversationId, $actorId, $this->fallbackText($event, $meta), null, null, null, 'system', $meta);
        $this->conversations->touchUpdatedAt($conversationId);
    }

    private function fallbackText(string $event, array $m): string
    {
        $a = $m['actor_name'] ?? '';
        $t = $m['target_name'] ?? '';
        return match ($event) {
            'created'        => "{$a} created the group",
            'added'          => "{$a} added {$t}",
            'removed'        => "{$a} removed {$t}",
            'left'           => "{$a} left",
            'joined_link'    => "{$a} joined using the invite link",
            'join_approved'  => "{$a} approved {$t}'s request to join",
            'renamed'        => "{$a} changed the group name to \"" . ($m['name'] ?? '') . '"',
            'description'    => "{$a} changed the group description",
            'avatar'         => "{$a} changed the group photo",
            'avatar_removed' => "{$a} removed the group photo",
            'promoted'       => "{$a} made {$t} an admin",
            'demoted'        => "{$a} removed {$t} as admin",
            'owner_transfer' => "{$a} made {$t} the group owner",
            'only_admins_on' => "{$a} closed the group: only admins can send messages",
            'only_admins_off'=> "{$a} opened the group: everyone can send messages",
            'types_changed'  => "{$a} changed the allowed message types",
            'link_reset'     => "{$a} reset the invite link",
            'link_on'        => "{$a} turned the invite link on",
            'link_off'       => "{$a} turned the invite link off",
            'restricted'     => "{$a} restricted {$t} from sending messages",
            'unrestricted'   => "{$a} allowed {$t} to send messages again",
            'settings'       => "{$a} changed the group settings",
            default          => $a . ' ' . $event,
        };
    }

    // -- Create ------------------------------------------------------------------

    /** Applies the creation-time extras (description, created_by, system line). */
    public function afterCreate(int $conversationId, $creatorId, ?string $description = null): void
    {
        $update = ['created_by' => $creatorId];
        if ($description !== null && trim($description) !== '') {
            $update['description'] = mb_substr(trim($description), 0, self::MAX_DESCRIPTION);
        }
        DB::table('conversations')->where('id', $conversationId)->update($update);
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->update(['join_source' => 'added']);
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $creatorId)->update(['join_source' => 'created']);
        $this->systemMessage($conversationId, $creatorId, 'created');
    }

    // -- Update info + settings ----------------------------------------------------

    /**
     * @param array<string,mixed> $input name?, description?, only_admins_send?, members_can_edit_info?, members_can_add?,
     *                                   join_approval?, allowed_types? (array), invite_enabled?, max_members?
     */
    public function update(int $conversationId, $actorId, array $input): void
    {
        $row = $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $actorId);
        $isAdmin = $this->isAdminRole($role);

        $infoKeys = ['name', 'description'];
        $settingKeys = ['only_admins_send', 'members_can_edit_info', 'members_can_add', 'join_approval', 'allowed_types', 'invite_enabled', 'max_members'];

        $touchesInfo = (bool) array_intersect($infoKeys, array_keys($input));
        $touchesSettings = (bool) array_intersect($settingKeys, array_keys($input));
        if ($touchesSettings && !$isAdmin) {
            throw new \RuntimeException('Only group admins can change the group settings.');
        }
        if ($touchesInfo && !$isAdmin && !$row->members_can_edit_info) {
            throw new \RuntimeException('Only group admins can edit the group info.');
        }

        $update = [];
        $events = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new \RuntimeException('Group name is required.');
            }
            $name = mb_substr($name, 0, self::MAX_NAME);
            if ($name !== (string) $row->group_name) {
                $update['group_name'] = $name;
                $events[] = ['renamed', ['name' => $name]];
            }
        }
        if (array_key_exists('description', $input)) {
            $desc = trim((string) $input['description']);
            $desc = mb_substr($desc, 0, self::MAX_DESCRIPTION);
            if ($desc !== (string) ($row->description ?? '')) {
                $update['description'] = $desc !== '' ? $desc : null;
                $events[] = ['description', []];
            }
        }
        foreach (['members_can_edit_info', 'members_can_add', 'join_approval'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $val = $this->bool($input[$flag]);
                if ($val !== (bool) $row->{$flag}) {
                    $update[$flag] = $val ? 1 : 0;
                    $events[] = ['settings', ['key' => $flag, 'value' => $val]];
                }
            }
        }
        if (array_key_exists('only_admins_send', $input)) {
            $val = $this->bool($input['only_admins_send']);
            if ($val !== (bool) $row->only_admins_send) {
                $update['only_admins_send'] = $val ? 1 : 0;
                $events[] = [$val ? 'only_admins_on' : 'only_admins_off', []];
            }
        }
        if (array_key_exists('allowed_types', $input)) {
            $types = $this->normalizeTypes($input['allowed_types']);
            $new = $types === null ? null : json_encode($types);
            $old = $row->allowed_types === null ? null : json_encode($this->decodeTypes($row->allowed_types));
            if ($new !== $old) {
                $update['allowed_types'] = $new;
                $events[] = ['types_changed', ['types' => $types ?? self::TYPES]];
            }
        }
        if (array_key_exists('invite_enabled', $input)) {
            if ($row->student_group_id !== null) {
                throw new \RuntimeException('This group is managed by a student group and has no invite link.');
            }
            $val = $this->bool($input['invite_enabled']);
            if ($val !== (bool) $row->invite_enabled) {
                $update['invite_enabled'] = $val ? 1 : 0;
                if ($val && !$row->invite_token) {
                    $update['invite_token'] = $this->newToken();
                }
                $events[] = [$val ? 'link_on' : 'link_off', []];
            }
        }
        if (array_key_exists('max_members', $input)) {
            $max = (int) $input['max_members'];
            $current = $this->conversations->memberCount($conversationId);
            if ($max < max(2, $current) || $max > 5000) {
                throw new \RuntimeException('Member limit must be between the current member count and 5000.');
            }
            $update['max_members'] = $max;
        }

        if (!$update) {
            return;
        }
        $update['updated_at'] = now();
        DB::table('conversations')->where('id', $conversationId)->update($update);
        foreach ($events as [$event, $meta]) {
            $this->systemMessage($conversationId, $actorId, $event, $meta);
        }
    }

    private function bool($v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    // -- Photo -----------------------------------------------------------------------

    public function setAvatar(int $conversationId, $actorId, ?UploadedFile $file): string
    {
        $row = $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $actorId);
        if (!$this->isAdminRole($role) && !$row->members_can_edit_info) {
            throw new \RuntimeException('Only group admins can edit the group info.');
        }
        $stored = $this->uploads->store($file, 'avatars', 'groups', 4096);
        if ($row->avatar_path) {
            $this->uploads->delete((string) $row->avatar_path);
        }
        DB::table('conversations')->where('id', $conversationId)->update(['avatar_path' => $stored['stored_path'], 'updated_at' => now()]);
        $this->systemMessage($conversationId, $actorId, 'avatar');
        return $stored['stored_path'];
    }

    public function removeAvatar(int $conversationId, $actorId): void
    {
        $row = $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $actorId);
        if (!$this->isAdminRole($role) && !$row->members_can_edit_info) {
            throw new \RuntimeException('Only group admins can edit the group info.');
        }
        if (!$row->avatar_path) {
            return;
        }
        $this->uploads->delete((string) $row->avatar_path);
        DB::table('conversations')->where('id', $conversationId)->update(['avatar_path' => null, 'updated_at' => now()]);
        $this->systemMessage($conversationId, $actorId, 'avatar_removed');
    }

    // -- Members -----------------------------------------------------------------------

    private function maxMembers(object $row): int
    {
        return (int) ($row->max_members ?: self::DEFAULT_MAX_MEMBERS);
    }

    /** @param array<int,int|string> $userIds @return int how many were really added */
    public function addMembers(int $conversationId, $actorId, array $userIds): int
    {
        $row = $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $actorId);
        if (!$this->isAdminRole($role) && !$row->members_can_add) {
            throw new \RuntimeException('Only group admins can add members.');
        }
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $count = $this->conversations->memberCount($conversationId);
        $added = 0;
        foreach ($userIds as $uid) {
            if ($uid <= 0 || $this->conversations->isParticipant($conversationId, $uid)) {
                continue;
            }
            if (!DB::table('users')->where('id', $uid)->exists()) {
                continue;
            }
            // "مين يقدر يضيفني لمجموعات": اللي مقفلها قدام الشخص ده بيتتخطى.
            if (!app(ChatSettingsService::class)->allows($uid, (int) $actorId, 'groups')) {
                continue;
            }
            if ($count + $added >= $this->maxMembers($row)) {
                throw new \RuntimeException('This group has reached its member limit.');
            }
            $this->conversations->addMember($conversationId, $uid);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $uid)
                ->update(['added_by' => $actorId, 'join_source' => 'added']);
            DB::table('conversation_join_requests')->where('conversation_id', $conversationId)->where('user_id', $uid)->where('status', 'pending')
                ->update(['status' => 'approved', 'decided_by' => $actorId, 'decided_at' => now()]);
            $this->systemMessage($conversationId, $actorId, 'added', ['target_id' => $uid, 'target_name' => $this->userName($uid)]);
            $added++;
        }
        return $added;
    }

    public function removeMember(int $conversationId, $actorId, $targetId): void
    {
        $this->groupRowOrFail($conversationId);
        $actorRole = $this->assertAdmin($conversationId, $actorId);
        $targetRole = $this->roleOf($conversationId, $targetId);
        if ($targetRole === null) {
            throw new \RuntimeException('That person is not in the group.');
        }
        if ((string) $actorId === (string) $targetId) {
            $this->leave($conversationId, $actorId);
            return;
        }
        if ($targetRole === 'owner') {
            throw new \RuntimeException('The group owner cannot be removed.');
        }
        if ($targetRole === 'admin' && $actorRole !== 'owner') {
            throw new \RuntimeException('Only the group owner can remove an admin.');
        }
        $this->conversations->removeMember($conversationId, $targetId);
        $this->systemMessage($conversationId, $actorId, 'removed', ['target_id' => (int) $targetId, 'target_name' => $this->userName((int) $targetId)]);
    }

    public function setRole(int $conversationId, $actorId, $targetId, string $role): void
    {
        $this->groupRowOrFail($conversationId);
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new \RuntimeException('Invalid role.');
        }
        $actorRole = $this->assertAdmin($conversationId, $actorId);
        $targetRole = $this->roleOf($conversationId, $targetId);
        if ($targetRole === null) {
            throw new \RuntimeException('That person is not in the group.');
        }
        if ($targetRole === 'owner') {
            throw new \RuntimeException('The group owner role cannot be changed. Transfer ownership instead.');
        }
        if ($role === 'member' && $targetRole === 'admin' && $actorRole !== 'owner') {
            throw new \RuntimeException('Only the group owner can remove an admin.');
        }
        if ($role === $targetRole) {
            return;
        }
        $this->conversations->setMemberRole($conversationId, $targetId, $role);
        if ($role === 'admin') {
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $targetId)->update(['send_restricted' => 0]);
        }
        $this->systemMessage($conversationId, $actorId, $role === 'admin' ? 'promoted' : 'demoted', ['target_id' => (int) $targetId, 'target_name' => $this->userName((int) $targetId)]);
    }

    public function setRestricted(int $conversationId, $actorId, $targetId, bool $restricted): void
    {
        $this->groupRowOrFail($conversationId);
        $this->assertAdmin($conversationId, $actorId);
        $targetRole = $this->roleOf($conversationId, $targetId);
        if ($targetRole === null) {
            throw new \RuntimeException('That person is not in the group.');
        }
        if ($this->isAdminRole($targetRole)) {
            throw new \RuntimeException('Admins cannot be restricted. Remove their admin role first.');
        }
        DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $targetId)
            ->update(['send_restricted' => $restricted ? 1 : 0]);
        $this->systemMessage($conversationId, $actorId, $restricted ? 'restricted' : 'unrestricted', ['target_id' => (int) $targetId, 'target_name' => $this->userName((int) $targetId)]);
    }

    public function transferOwnership(int $conversationId, $actorId, $targetId): void
    {
        $this->groupRowOrFail($conversationId);
        if ($this->roleOf($conversationId, $actorId) !== 'owner') {
            throw new \RuntimeException('Only the group owner can transfer ownership.');
        }
        if ((string) $actorId === (string) $targetId || !$this->conversations->isParticipant($conversationId, $targetId)) {
            throw new \RuntimeException('Pick another member of the group.');
        }
        DB::transaction(function () use ($conversationId, $actorId, $targetId) {
            $this->conversations->setMemberRole($conversationId, $targetId, 'owner');
            $this->conversations->setMemberRole($conversationId, $actorId, 'admin');
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $targetId)->update(['send_restricted' => 0]);
        });
        $this->systemMessage($conversationId, $actorId, 'owner_transfer', ['target_id' => (int) $targetId, 'target_name' => $this->userName((int) $targetId)]);
    }

    /** @return bool true when the group was deleted because nobody was left */
    public function leave(int $conversationId, $userId): bool
    {
        $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $userId);

        $others = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)->where('user_id', '!=', $userId)
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('joined_at')
            ->get(['user_id', 'role']);

        if ($others->isEmpty()) {
            DB::table('conversations')->where('id', $conversationId)->delete();
            return true;
        }

        $this->systemMessage($conversationId, $userId, 'left');
        if ($role === 'owner') {
            // the longest-standing admin, otherwise the longest-standing member, becomes the owner
            $successor = (int) $others->first()->user_id;
            $this->conversations->setMemberRole($conversationId, $successor, 'owner');
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $successor)->update(['send_restricted' => 0]);
            $this->systemMessage($conversationId, $userId, 'owner_transfer', ['target_id' => $successor, 'target_name' => $this->userName($successor)]);
        }
        $this->conversations->removeMember($conversationId, $userId);
        return false;
    }

    public function deleteGroup(int $conversationId, $actorId): void
    {
        $row = $this->groupRowOrFail($conversationId);
        if ($this->roleOf($conversationId, $actorId) !== 'owner') {
            throw new \RuntimeException('Only the group owner can delete the group.');
        }
        if ($row->avatar_path) {
            $this->uploads->delete((string) $row->avatar_path);
        }
        DB::table('conversations')->where('id', $conversationId)->delete();
    }

    // -- Invite link ----------------------------------------------------------------------

    private function newToken(): string
    {
        do {
            $token = Str::random(32);
        } while (DB::table('conversations')->where('invite_token', $token)->exists());
        return $token;
    }

    /** Current token (created on first use). Admins, or members when members_can_add. */
    public function inviteLink(int $conversationId, $actorId): array
    {
        $row = $this->groupRowOrFail($conversationId);
        $role = $this->assertMember($conversationId, $actorId);
        if ($row->student_group_id !== null) {
            throw new \RuntimeException('This group is managed by a student group and has no invite link.');
        }
        if (!$this->isAdminRole($role) && !$row->members_can_add) {
            throw new \RuntimeException('Only group admins can share the invite link.');
        }
        $token = $row->invite_token;
        if (!$token) {
            $token = $this->newToken();
            DB::table('conversations')->where('id', $conversationId)->update(['invite_token' => $token]);
        }
        return ['token' => $token, 'enabled' => (bool) $row->invite_enabled];
    }

    public function resetInviteLink(int $conversationId, $actorId): array
    {
        $row = $this->groupRowOrFail($conversationId);
        $this->assertAdmin($conversationId, $actorId);
        if ($row->student_group_id !== null) {
            throw new \RuntimeException('This group is managed by a student group and has no invite link.');
        }
        $token = $this->newToken();
        DB::table('conversations')->where('id', $conversationId)->update(['invite_token' => $token, 'invite_enabled' => 1]);
        $this->systemMessage($conversationId, $actorId, 'link_reset');
        return ['token' => $token, 'enabled' => true];
    }

    /** @return object|null */
    private function byToken(string $token): ?object
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $row = DB::table('conversations')->where('invite_token', $token)->where('is_group', 1)->first();
        return $row ?: null;
    }

    /**
     * What the "join group" page shows before the user confirms.
     * status: member | open | needs_approval | request_pending | full
     *
     * @return array<string,mixed>
     */
    public function previewInvite(string $token, $userId): array
    {
        $row = $this->byToken($token);
        if (!$row || !$row->invite_enabled || $row->student_group_id !== null) {
            throw new \RuntimeException('This invite link is invalid or has been turned off.');
        }
        $cid = (int) $row->id;
        $members = $this->conversations->memberCount($cid);
        $status = 'open';
        if ($this->conversations->isParticipant($cid, $userId)) {
            $status = 'member';
        } elseif (DB::table('conversation_join_requests')->where('conversation_id', $cid)->where('user_id', $userId)->where('status', 'pending')->exists()) {
            $status = 'request_pending';
        } elseif ($members >= $this->maxMembers($row)) {
            $status = 'full';
        } elseif ($row->join_approval) {
            $status = 'needs_approval';
        }

        $sample = DB::select(
            'SELECT u.full_name, u.avatar_path FROM conversation_participants cp INNER JOIN users u ON u.id = cp.user_id
             WHERE cp.conversation_id = ? ORDER BY cp.joined_at ASC LIMIT 4',
            [$cid]
        );

        return [
            'conversation_id' => $cid,
            'name'            => $row->group_name ?: $row->subject,
            'description'     => $row->description,
            'avatar_path'     => $row->avatar_path,
            'member_count'    => $members,
            'status'          => $status,
            'needs_approval'  => (bool) $row->join_approval,
            'member_names'    => array_map(fn ($r) => $r->full_name, $sample),
        ];
    }

    /** @return array{status:string,conversation_id:int} status = joined | already_member | requested */
    public function joinByInvite(string $token, $userId): array
    {
        $row = $this->byToken($token);
        if (!$row || !$row->invite_enabled || $row->student_group_id !== null) {
            throw new \RuntimeException('This invite link is invalid or has been turned off.');
        }
        $cid = (int) $row->id;

        if ($this->conversations->isParticipant($cid, $userId)) {
            return ['status' => 'already_member', 'conversation_id' => $cid];
        }
        if ($this->conversations->memberCount($cid) >= $this->maxMembers($row)) {
            throw new \RuntimeException('This group has reached its member limit.');
        }

        if ($row->join_approval) {
            $pending = DB::table('conversation_join_requests')->where('conversation_id', $cid)->where('user_id', $userId)->where('status', 'pending')->exists();
            if (!$pending) {
                DB::table('conversation_join_requests')->insert(['conversation_id' => $cid, 'user_id' => $userId, 'status' => 'pending', 'created_at' => now()]);
                $this->notifyAdminsOfRequest($cid, (string) ($row->group_name ?: $row->subject), (int) $userId);
            }
            return ['status' => 'requested', 'conversation_id' => $cid];
        }

        $this->conversations->addMember($cid, $userId);
        DB::table('conversation_participants')->where('conversation_id', $cid)->where('user_id', $userId)->update(['join_source' => 'link']);
        $this->systemMessage($cid, $userId, 'joined_link');
        return ['status' => 'joined', 'conversation_id' => $cid];
    }

    // -- Join requests ------------------------------------------------------------------------

    private function messagesLink($recipientId, int $conversationId): string
    {
        $role = $this->roles->primaryRoleFor((int) $recipientId);
        $home = (string) config("roles.home_route.{$role}", '');
        $prefix = $home !== '' ? explode('/', trim($home, '/'))[0] : str_replace('_', '-', $role);
        return "/{$prefix}/messages/{$conversationId}";
    }

    private function notifyAdminsOfRequest(int $conversationId, string $groupName, int $requesterId): void
    {
        $who = $this->userName($requesterId);
        $admins = DB::table('conversation_participants')->where('conversation_id', $conversationId)->whereIn('role', ['owner', 'admin'])->pluck('user_id');
        foreach ($admins as $adminId) {
            $this->notifications->notify(
                (int) $adminId,
                'group_join_request',
                "Join request for {$groupName}",
                "{$who} asked to join the group via the invite link.",
                $this->messagesLink($adminId, $conversationId)
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function joinRequests(int $conversationId, $actorId): array
    {
        $this->groupRowOrFail($conversationId);
        $this->assertAdmin($conversationId, $actorId);
        $rows = DB::select(
            "SELECT r.id, r.user_id, r.created_at, u.full_name, u.email, u.avatar_path
             FROM conversation_join_requests r INNER JOIN users u ON u.id = r.user_id
             WHERE r.conversation_id = ? AND r.status = 'pending' ORDER BY r.created_at ASC",
            [$conversationId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function decideRequest(int $conversationId, $actorId, int $requestId, bool $approve): void
    {
        $row = $this->groupRowOrFail($conversationId);
        $this->assertAdmin($conversationId, $actorId);
        $req = DB::table('conversation_join_requests')->where('id', $requestId)->where('conversation_id', $conversationId)->where('status', 'pending')->first();
        if (!$req) {
            throw new \RuntimeException('This request was already handled.');
        }
        $name = (string) ($row->group_name ?: $row->subject);

        if ($approve) {
            if ($this->conversations->memberCount($conversationId) >= $this->maxMembers($row)) {
                throw new \RuntimeException('This group has reached its member limit.');
            }
            $this->conversations->addMember($conversationId, (int) $req->user_id);
            DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $req->user_id)
                ->update(['join_source' => 'approved', 'added_by' => $actorId]);
            $this->systemMessage($conversationId, $actorId, 'join_approved', ['target_id' => (int) $req->user_id, 'target_name' => $this->userName((int) $req->user_id)]);
        }
        DB::table('conversation_join_requests')->where('id', $requestId)->update([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actorId, 'decided_at' => now(),
        ]);
        $this->notifications->notify(
            (int) $req->user_id,
            'group_join_' . ($approve ? 'approved' : 'rejected'),
            $approve ? "You can now join {$name}" : "Join request declined",
            $approve ? 'An admin approved your request to join the group.' : "An admin declined your request to join {$name}.",
            $approve ? $this->messagesLink($req->user_id, $conversationId) : null
        );
    }

    /** The requester withdraws their own pending request. */
    public function cancelMyRequest(string $token, $userId): void
    {
        $row = $this->byToken($token);
        if (!$row) {
            return;
        }
        DB::table('conversation_join_requests')->where('conversation_id', $row->id)->where('user_id', $userId)->where('status', 'pending')->delete();
    }
}
