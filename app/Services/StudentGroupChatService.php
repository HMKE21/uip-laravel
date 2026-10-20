<?php

namespace App\Services;

use App\Models\Conversation;
use App\Repositories\StudentGroupRepository;
use Illuminate\Support\Facades\DB;

/**
 * تكملة الفجوة اللي كانت موثّقة في StudentManagementService (وفي docblock
 * موديل Conversation): بند 18 (Messaging) خلص، فـ StudentGroupChatService
 * بقت تتعمل دلوقتي — مفتاحها student_group_id على جدول `conversations` (عمود
 * موجود من migration 114، كان مستني الخدمة دي). كل مجموعة طلاب بتاخد
 * محادثة جماعية واحدة، بتتعمل lazily أول ما حد يفتح صفحة /student/group-chat
 * (StudentGroupChatApiController::resolve())، وبعدين كل عضو حالي في
 * المجموعة (StudentGroupRepository::members()) بينضاف كمشارك.
 */
class StudentGroupChatService
{
    public function __construct(private StudentGroupRepository $groups)
    {
    }

    /** بترجع محادثة المجموعة الجماعية، وبتنشئها (بقايمة الأعضاء الصح) لو لسه مش موجودة. */
    public function getOrCreateForGroup(int $groupId): ?Conversation
    {
        $existing = $this->findForGroup($groupId);
        if ($existing) {
            return $existing;
        }

        $group = $this->groups->find($groupId);
        if (!$group) {
            return null;
        }

        $name = $group->name . ' — Group Chat';
        $conversation = Conversation::create([
            'subject'          => $name,
            'is_group'         => true,
            'group_name'       => $name,
            'student_group_id' => $groupId,
        ]);

        foreach ($this->groups->members($groupId) as $m) {
            $this->addParticipantIfMissing((int) $conversation->id, (int) $m['user_id']);
        }

        return $conversation;
    }

    /** بتضيف عضو مجموعة جديد لمحادثة المجموعة الجماعية (لو أصلًا موجودة). */
    public function addMember(int $groupId, int $userId): void
    {
        $conversation = $this->findForGroup($groupId);
        if ($conversation) {
            $this->addParticipantIfMissing((int) $conversation->id, $userId);
        }
        // لو المجموعة لسه مالهاش محادثة، هتتعمل مع أول getOrCreateForGroup()
        // جاي وهتضم العضو ده وقتها.
    }

    /** بتشيل عضو مجموعة من المحادثة الجماعية لما يتنقل لمجموعة تانية. */
    public function removeMember(int $groupId, int $userId): void
    {
        $conversation = $this->findForGroup($groupId);
        if (!$conversation) {
            return;
        }
        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->delete();
    }

    /** صف محادثة المجموعة الجماعية، أو null لو لسه مش اتعملت. */
    public function findForGroup(int $groupId): ?Conversation
    {
        return Conversation::where(['student_group_id' => $groupId, 'is_group' => true])->first();
    }

    private function addParticipantIfMissing(int $conversationId, int $userId): void
    {
        $exists = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            DB::table('conversation_participants')->insert([
                'conversation_id' => $conversationId,
                'user_id'         => $userId,
                'joined_at'       => now(),
            ]);
        }
    }
}
