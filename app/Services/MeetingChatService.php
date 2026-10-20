<?php

namespace App\Services;

use App\Events\Meetings\ChatMessageReactionChanged;
use App\Events\Meetings\ChatMessageSent;
use App\Models\Meeting;
use App\Models\MeetingChatMessage;
use App\Repositories\MeetingRepository;

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
        private MeetingRepository $meetings
    ) {
    }

    /** بند 7 (settings.allow_chat اللي Round 1 حجز مكانها في config/meetings.php default_settings). */
    public function isChatEnabled(Meeting $meeting): bool
    {
        return (bool) ($meeting->settings['allow_chat'] ?? true);
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

        $recipientKey = $data['recipient_key'] ?? null;
        if ($recipientKey !== null) {
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
            'body'                 => $data['body'],
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

    private function broadcast(Meeting $meeting, MeetingChatMessage $message): void
    {
        $channelName = $message->recipient_key === null
            ? 'presence-meeting.' . $meeting->uuid
            : 'private-meeting.' . $meeting->uuid . '.inbox.' . str_replace(':', '-', $message->recipient_key);

        event(new ChatMessageSent($channelName, $this->present($message)));
    }

    /** بند 7: history عند فتح شاشة الاجتماع/بعد إعادة اتصال — راجع docblock MeetingRepository::chatMessagesVisibleTo(). */
    public function listFor(Meeting $meeting, array $actor, ?int $afterId = null): array
    {
        $messages = $this->meetings->chatMessagesVisibleTo($meeting->id, $actor['key'], $afterId);

        return array_map(fn (MeetingChatMessage $m) => $this->present($m), $messages);
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
            'reply_to_message_id'  => $message->reply_to_message_id,
            'mentioned_keys'       => $message->mentioned_keys ?? [],
            'reactions'            => array_values($reactions),
            'created_at'           => optional($message->created_at)->toISOString(),
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
