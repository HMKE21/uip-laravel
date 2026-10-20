<?php

namespace App\Services;

use App\Events\Meetings\MeetingActionItemChanged;
use App\Events\Meetings\MeetingNotesUpdated;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingNote;
use App\Repositories\MeetingRepository;

/**
 * Meetings & Collaboration Platform — Round 7 (Collaboration Extras):
 * بند 20 (Meeting Notes). المواصفة بتقول "The host or authorized
 * participants" — هنا مبسّطة لـ canManage() (هوست/co-host بس)، نفس
 * تدرّج الصلاحيات المتبع في كل قرارات "مين يقدر يدير" في الموديول ده
 * (MeetingHostControlService)، من غير نظام "authorize فرد بفرد" إضافي
 * (ده أوسع من حجم بند واحد، ومحتاج UI مخصص مش مطلوب هنا صراحة).
 *
 * meeting_notes: صف واحد لكل اجتماع (الأجندة + الملاحظات الحرة كمستند
 * تعاوني واحد، last-write-wins — راجع docblock MeetingNotesUpdated).
 * meeting_action_items: Decision/Action Item/Task منظّمين (المثال في
 * المواصفة بالظبط).
 */
class MeetingNotesService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy
    ) {
    }

    private function requireManager(Meeting $meeting, array $actor): void
    {
        $userId = $actor['type'] === 'participant' ? (int) str_replace('user:', '', $actor['key']) : 0;
        if (!$this->policy->canManage($meeting, $userId, $this->meetings)) {
            throw new \RuntimeException('Only the host or co-host can edit meeting notes.');
        }
    }

    public function get(Meeting $meeting): MeetingNote
    {
        return $this->meetings->findOrCreateNotes($meeting->id);
    }

    /** @throws \RuntimeException actor مش هوست/co-host. */
    public function updateBody(Meeting $meeting, array $actor, string $body): MeetingNote
    {
        $this->requireManager($meeting, $actor);

        $note = $this->get($meeting);
        $note->body = $body;
        $note->last_edited_by_key = $actor['key'];
        $note->last_edited_by_display_name = $actor['display_name'];
        $note->last_edited_at = now();
        $note->save();

        event(new MeetingNotesUpdated($meeting->uuid, $body, $actor['key'], $actor['display_name']));

        return $note;
    }

    /** @return MeetingActionItem[] */
    public function listItems(Meeting $meeting): array
    {
        return $this->meetings->actionItemsFor($meeting->id);
    }

    /**
     * @param array $data type ('decision'|'action_item'|'task'), title, assignee_display_name?, due_at?
     * @throws \RuntimeException actor مش هوست/co-host.
     */
    public function createItem(Meeting $meeting, array $actor, array $data): MeetingActionItem
    {
        $this->requireManager($meeting, $actor);

        $item = $this->meetings->createActionItem([
            'meeting_id'             => $meeting->id,
            'type'                   => $data['type'] ?? 'task',
            'title'                  => $data['title'],
            'assignee_display_name'  => $data['assignee_display_name'] ?? null,
            'due_at'                 => $data['due_at'] ?? null,
            'status'                 => 'open',
            'created_by_key'         => $actor['key'],
            'created_by_display_name' => $actor['display_name'],
        ]);

        event(new MeetingActionItemChanged($meeting->uuid, 'created', $item->id, $item->toArray()));

        return $item;
    }

    /**
     * @param array $data title?, assignee_display_name?, due_at?, status? ('open'|'done')
     * @throws \RuntimeException actor مش هوست/co-host.
     */
    public function updateItem(Meeting $meeting, array $actor, MeetingActionItem $item, array $data): MeetingActionItem
    {
        $this->requireManager($meeting, $actor);

        $item->fill(array_intersect_key($data, array_flip(['title', 'assignee_display_name', 'due_at', 'status', 'type'])));
        $item->save();

        event(new MeetingActionItemChanged($meeting->uuid, 'updated', $item->id, $item->toArray()));

        return $item;
    }

    /** @throws \RuntimeException actor مش هوست/co-host. */
    public function deleteItem(Meeting $meeting, array $actor, MeetingActionItem $item): void
    {
        $this->requireManager($meeting, $actor);

        $itemId = $item->id;
        $item->delete();

        event(new MeetingActionItemChanged($meeting->uuid, 'deleted', $itemId));
    }
}
