<?php

namespace App\Services;

use App\Events\Meetings\ChatCleared;
use App\Events\Meetings\ChatMessageDeleted;
use App\Events\Meetings\ChatMessageReactionChanged;
use App\Events\Meetings\ChatMessageSent;
use App\Models\Meeting;
use App\Models\MeetingChatMessage;
use App\Repositories\MeetingRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/**
 * Meetings & Collaboration Platform — Round 5 (Live Collaboration): بند
 * 7 (Meeting Chat)، بند 8 (Private Chat). نفس فلسفة كل خدمة تانية في
 * الموديول ده: المنطق كله هنا، الكنترولر بس بيتحقق من شكل الـ input.
 *
 * "actor" هنا نفس المصفوفة الموحّدة اللي MeetingSignalingService::
 * resolveActor() بترجعها بالظبط ({type, model, key, display_name,
 * role}) — الكنترولر بيحلّها مرة واحدة عبر MeetingSignalingService
 * ويمررها هنا، مش كل خدمة بتحل الـ actor بنفسها.
 *
 * postSystemMessage() بينادى من MeetingSignalingService (مش العكس) —
 * حقن اختياري nullable (زي $notifications في MeetingLobbyService) عشان
 * القرار يفضل one-directional ومفيش circular dependency بين الخدمتين.
 */
class MeetingChatService
{
    public function __construct(
        private MeetingRepository $meetings,
        private ?FileUploadService $uploads = null
    ) {
    }

    /** بند 7 (settings.allow_chat اللي Round 1 حجز مكانها في config/meetings.php default_settings). */
    public function isChatEnabled(Meeting $meeting): bool
    {
        return \App\Services\MeetingAdminSettings::on('chat') && (bool) ($meeting->settings['allow_chat'] ?? true);
    }

    /** "keep" = people who join later can read what was said before them; "hide" = they start with an empty chat. */
    public function chatHistoryMode(Meeting $meeting): string
    {
        return ($meeting->settings['chat_history'] ?? 'keep') === 'hide' ? 'hide' : 'keep';
    }

    /** @return array<int,string> actor keys the host has silenced in chat */
    public function mutedKeys(Meeting $meeting): array
    {
        return array_values(array_unique(array_filter((array) ($meeting->settings['chat_muted_keys'] ?? []), 'is_string')));
    }

    /** Everything the front end needs to know about the chat policy (sent with the history and with every live change). */
    public function chatSettings(Meeting $meeting): array
    {
        return [
            'chat_enabled' => $this->isChatEnabled($meeting),
            'chat_history' => $this->chatHistoryMode($meeting),
            'muted_keys'   => $this->mutedKeys($meeting),
        ];
    }

    /** Host / co-host of this meeting (a guest is never a moderator). */
    public function actorIsModerator(Meeting $meeting, array $actor): bool
    {
        if (($actor['type'] ?? null) !== 'participant') {
            return false;
        }
        $userId = (int) ($actor['model']->user_id ?? 0);

        return $userId > 0 && app(MeetingPolicyService::class)->canManage($meeting, $userId, $this->meetings);
    }

    /** @param array{type:string, key:string, display_name:string} $actor */
    private function actorSenderType(array $actor): string
    {
        return $actor['type'] === 'guest' ? 'guest' : 'participant';
    }

    /**
     * بند 7 (رسالة عامة) + بند 8 (رسالة خاصة لو recipient_key موجود).
     *
     * @param array{type:string, model:mixed, key:string, display_name:string, role:string} $actor
     * @param array{body:string, reply_to_message_id?:?int, recipient_key?:?string, mentioned_keys?:?array} $data
     * @throws \RuntimeException الشات متعطّل، أو recipient/reply_to مش صالحين.
     */
    public function send(Meeting $meeting, array $actor, array $data): MeetingChatMessage
    {
        if (!$this->isChatEnabled($meeting)) {
            throw new \RuntimeException('Chat is disabled for this meeting.');
        }

        if (in_array($actor['key'], $this->mutedKeys($meeting), true) && !$this->actorIsModerator($meeting, $actor)) {
            throw new \RuntimeException('The host has turned off your chat.');
        }

        $file = $data['file'] ?? null;
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && !$file instanceof UploadedFile) {
            throw new \RuntimeException('Type a message or attach a file.');
        }

        $attachment = null;
        if ($file instanceof UploadedFile) {
            if (!\App\Services\MeetingAdminSettings::on('files') || !(bool) ($meeting->settings['allow_file_sharing'] ?? true)) {
                throw new \RuntimeException('File sharing is disabled for this meeting.');
            }
            // الحقن nullable (عشان الاختبارات اللي بتعمل new MeetingChatService($repo))، بس في الإنتاج لو الـ container
            // رجّع null لأي سبب بنحلّه هنا صراحة بدل ما نرفض الرفع — نفس FileUploadService اللي بيستخدمه MeetingFileService.
            $uploads = $this->uploads ?? app(FileUploadService::class);
            $stored = $uploads->store($file, 'meetings', $meeting->uuid);
            $attachment = [
                'original_name' => $stored['original_name'],
                'stored_path'   => $stored['stored_path'],
                'extension'     => $stored['extension'],
                'mime_type'     => $stored['mime_type'],
                'size_bytes'    => $stored['size_bytes'],
            ];
        }

        $recipientKey = $data['recipient_key'] ?? null;
        if ($recipientKey !== null) {
            if (!\App\Services\MeetingAdminSettings::on('private_chat')) {
                throw new \RuntimeException('Private messages are disabled by the administrator.');
            }
            if ($recipientKey === $actor['key']) {
                throw new \RuntimeException('You cannot send a private message to yourself.');
            }
            if (!$this->actorKeyIsActiveInMeeting($meeting, $recipientKey)) {
                throw new \RuntimeException('This participant is not currently in the meeting.');
            }
        }

        $replyToId = $data['reply_to_message_id'] ?? null;
        if ($replyToId !== null) {
            $original = $this->meetings->findChatMessage($meeting->id, $replyToId);
            if (!$original) {
                throw new \RuntimeException('The message being replied to could not be found.');
            }
        }

        // بند 7 (Mentions) — بنخزّن الـ keys اللي وصلت من الفرونت من غير
        // تحقق عضوية إضافي (الفرونت بيبنيها من نفس roster اللي عنده
        // أصلًا)، بس بنقصّها لحد معقول (بند "لا تكسر UIP" — مفيش قائمة
        // مفتوحة تكبر من غير حد).
        $mentionedKeys = array_values(array_unique(array_slice((array) ($data['mentioned_keys'] ?? []), 0, 20)));

        $message = $this->meetings->createChatMessage([
            'meeting_id'           => $meeting->id,
            'sender_key'           => $actor['key'],
            'sender_type'          => $this->actorSenderType($actor),
            'sender_display_name'  => $actor['display_name'],
            'recipient_key'        => $recipientKey,
            'type'                 => 'text',
            'body'                 => $body,
            'attachment'           => $attachment,
            'reply_to_message_id'  => $replyToId,
            'mentioned_keys'       => $mentionedKeys ?: null,
        ]);

        $this->broadcast($meeting, $message);

        return $message;
    }

    /** بند 7: "Ahmed joined the meeting" / "Sara raised her hand" / "Mohamed started sharing his screen". */
    public function postSystemMessage(Meeting $meeting, string $body): MeetingChatMessage
    {
        $message = $this->meetings->createChatMessage([
            'meeting_id'          => $meeting->id,
            'sender_key'          => 'system',
            'sender_type'         => 'system',
            'sender_display_name' => 'System',
            'recipient_key'       => null,
            'type'                => 'system',
            'body'                => $body,
        ]);

        $this->broadcast($meeting, $message);

        return $message;
    }

    /** Channel a message travels on: the room's presence channel (public) or the recipient's personal inbox (private). */
    private function channelFor(Meeting $meeting, MeetingChatMessage $message): string
    {
        return $message->recipient_key === null
            ? 'presence-meeting.' . $meeting->uuid
            : 'private-meeting.' . $meeting->uuid . '.inbox.' . str_replace(':', '-', $message->recipient_key);
    }

    private function broadcast(Meeting $meeting, MeetingChatMessage $message): void
    {
        event(new ChatMessageSent($this->channelFor($meeting, $message), $this->present($message)));
    }

    /**
     * بند 7: history عند فتح شاشة الاجتماع/بعد إعادة اتصال.
     *
     * History policy: the first time someone opens the chat we remember the newest message id that already existed
     * (`chat_from_message_id`, never moved afterwards). With the host's "hide history" choice a person only gets public
     * messages newer than that mark, so late joiners start clean, while a refresh / re-join keeps what they had already seen.
     */
    public function listFor(Meeting $meeting, array $actor, ?int $afterId = null): array
    {
        $floor = null;
        $mark = $this->entryMark($meeting, $actor);
        if ($mark !== null && $this->chatHistoryMode($meeting) === 'hide') {
            $floor = $mark;
        }

        $messages = $this->meetings->chatMessagesVisibleTo($meeting->id, $actor['key'], $afterId, 200, $floor);

        return array_map(fn (MeetingChatMessage $m) => $this->present($m), $messages);
    }

    /** First-entry mark of this actor (lazily stored). Null when the column isn't migrated yet — then nothing is hidden. */
    private function entryMark(Meeting $meeting, array $actor): ?int
    {
        $model = $actor['model'] ?? null;
        if (!$model || !Schema::hasColumn($model->getTable(), 'chat_from_message_id')) {
            return null;
        }
        if ($model->chat_from_message_id === null) {
            $model->chat_from_message_id = (int) MeetingChatMessage::where('meeting_id', $meeting->id)->max('id');
            $model->save();
        }

        return (int) $model->chat_from_message_id;
    }

    /**
     * Delete one message for EVERYONE. Allowed for its sender, and for a host/co-host on any public message (including system
     * lines). A private message can only be removed by whoever sent it — a moderator can't even see it, so it isn't theirs to touch.
     *
     * @throws \RuntimeException message missing / not allowed
     */
    public function deleteMessage(Meeting $meeting, array $actor, int $messageId): array
    {
        $message = $this->meetings->findChatMessage($meeting->id, $messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }

        $own = $message->sender_key === $actor['key'];
        $moderates = !$message->isPrivate() && $this->actorIsModerator($meeting, $actor);
        if (!$own && !$moderates) {
            throw new \RuntimeException('You can only delete your own messages.');
        }

        $channelName = $this->channelFor($meeting, $message);
        $this->removeAttachmentFile($message);
        $this->meetings->deleteChatMessage($message);

        event(new ChatMessageDeleted($channelName, $messageId, $actor['key'], $actor['display_name']));

        return ['id' => $messageId];
    }

    /** Wipe the public chat (host/co-host only — the caller checks). Private messages are left alone. */
    public function clearPublic(Meeting $meeting, string $byDisplayName): int
    {
        $count = 0;
        foreach ($this->meetings->publicChatMessages($meeting->id) as $message) {
            $this->removeAttachmentFile($message);
            $this->meetings->deleteChatMessage($message);
            $count++;
        }
        event(new ChatCleared($meeting->uuid, $byDisplayName));

        return $count;
    }

    private function removeAttachmentFile(MeetingChatMessage $message): void
    {
        $path = $message->attachment['stored_path'] ?? null;
        if (!$path) {
            return;
        }
        try {
            ($this->uploads ?? app(FileUploadService::class))->delete((string) $path);
        } catch (\Throwable $e) {
            // the row is what matters; an orphaned file must never block a delete
        }
    }

    /**
     * بند 7 ("Message reactions") — toggle: إرسال نفس الإيموجي تاني من
     * نفس الشخص بيشيله بدل ما يكرره (نفس سلوك MessageReaction في
     * الموديول العام).
     *
     * @throws \RuntimeException الرسالة مش موجودة، أو الرسالة خاصة والـ actor مش طرف فيها.
     */
    public function toggleReaction(Meeting $meeting, array $actor, int $messageId, string $emoji): array
    {
        $message = $this->meetings->findChatMessage($meeting->id, $messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }

        if ($message->isPrivate() && $message->recipient_key !== $actor['key'] && $message->sender_key !== $actor['key']) {
            throw new \RuntimeException('You cannot react to a private message that is not yours.');
        }

        $existing = $this->meetings->findChatReaction($message->id, $actor['key'], $emoji);

        if ($existing) {
            $this->meetings->deleteChatReaction($existing);
            $added = false;
        } else {
            $this->meetings->addChatReaction([
                'message_id'          => $message->id,
                'actor_key'           => $actor['key'],
                'actor_display_name'  => $actor['display_name'],
                'emoji'               => $emoji,
                'created_at'          => now(),
            ]);
            $added = true;
        }

        event(new ChatMessageReactionChanged($meeting->uuid, $message->id, $actor['key'], $emoji, $added));

        return ['message_id' => $message->id, 'emoji' => $emoji, 'added' => $added];
    }

    /** @return array<string,mixed> شكل الرسالة اللي بيتبعت للفرونت (REST list وbroadcast سوا). */
    public function present(MeetingChatMessage $message): array
    {
        $reactions = [];
        foreach ($message->reactions ?? [] as $reaction) {
            $reactions[$reaction->emoji] ??= ['emoji' => $reaction->emoji, 'count' => 0, 'actor_keys' => []];
            $reactions[$reaction->emoji]['count']++;
            $reactions[$reaction->emoji]['actor_keys'][] = $reaction->actor_key;
        }

        return [
            'id'                   => $message->id,
            'sender_key'           => $message->sender_key,
            'sender_type'          => $message->sender_type,
            'sender_display_name'  => $message->sender_display_name,
            'recipient_key'        => $message->recipient_key,
            'is_private'           => $message->isPrivate(),
            'type'                 => $message->type,
            'body'                 => $message->body,
            'attachment'           => $this->presentAttachment($message->attachment),
            'reply_to_message_id'  => $message->reply_to_message_id,
            'mentioned_keys'       => $message->mentioned_keys ?? [],
            'reactions'            => array_values($reactions),
            'created_at'           => optional($message->created_at)->toISOString(),
        ];
    }

    /** @return array<string,mixed>|null الملف المرفق بالرسالة (من غير المسار الداخلي) + رابط التحميل/المعاينة. */
    private function presentAttachment(?array $attachment): ?array
    {
        if (!$attachment || empty($attachment['stored_path'])) {
            return null;
        }

        return [
            'original_name' => $attachment['original_name'] ?? 'file',
            'extension'     => $attachment['extension'] ?? null,
            'mime_type'     => $attachment['mime_type'] ?? null,
            'size_bytes'    => $attachment['size_bytes'] ?? null,
            'download_url'  => asset($attachment['stored_path']),
        ];
    }

    /** هل الـ key ده لسه حاضر فعليًا دلوقتي في الاجتماع (joined/admitted) — بند 8، مايتبعتش رسالة خاصة لحد مش موجود. */
    private function actorKeyIsActiveInMeeting(Meeting $meeting, string $key): bool
    {
        if (str_starts_with($key, 'user:')) {
            $userId = (int) substr($key, strlen('user:'));
            $participant = $this->meetings->findParticipant($meeting->id, $userId);
            return $participant !== null && $participant->status === 'joined';
        }

        if (str_starts_with($key, 'guest:')) {
            $guestId = (int) substr($key, strlen('guest:'));
            $joinRequest = $this->meetings->findJoinRequest($guestId);
            return $joinRequest !== null
                && (int) $joinRequest->meeting_id === (int) $meeting->id
                && $joinRequest->user_id === null
                && $joinRequest->status === 'admitted';
        }

        return false;
    }
}
