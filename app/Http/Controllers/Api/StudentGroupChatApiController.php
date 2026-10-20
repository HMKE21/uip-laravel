<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GroupCollaborationService;
use App\Services\MessagingService;
use App\Services\StudentGroupChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * سطح /api/v1/group-chat لصفحة StudentGroupChat.jsx — get-or-create
 * محادثة المجموعة الجماعية بتاعة الطالب (StudentGroupChatService::
 * getOrCreateForGroup())، بنفس نمط resolve()
 * بالظبط بس مفتاحها group_id
 * الطالب (GroupCollaborationService::groupIdForStudent() الموجودة
 * فعلًا لبند Group Hub).
 *
 * فرق حقيقي عن الكنترولرين الشقيقين: هما اتكتبوا وقت ما بند 18
 * (Messaging) لسه ما اتنقلش، فرجّعوا messages فاضية مع علم
 * needs_messaging_module=true. بند 18 خلص دلوقتي (MessagingService
 * موجودة بالكامل)، فـ resolve() هنا بيرجّع thread حقيقي كامل عن طريق
 * MessagingService::threadForUser() زي ما التعليقات هناك وعدت بالظبط.
 * الـ participants برضه بترجع من conversation_participants مباشرة (كل
 * أعضاء المجموعة، مش بس "الطرف التاني" زي threadForUser->others) عشان
 * تفضل roster كاملة للهيدر، زي الكنترولرين الشقيقين.
 *
 * RBAC: uip.auth (routes/api.php) + role='student' هنا؛ عضوية المجموعة
 * بتتفحص من جديد دايمًا عبر groupIdForStudent() — مفيش group id جاي من
 * الفرونت أصلًا، فمفيش حاجة تتلغبط.
 */
class StudentGroupChatApiController extends Controller
{
    public function __construct(
        private GroupCollaborationService $collabService,
        private StudentGroupChatService $groupChat,
        private MessagingService $messaging
    ) {
    }

    /** GET /api/v1/group-chat — get-or-create محادثة المجموعة الجماعية بتاعة الطالب. */
    public function resolve(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts have a group chat.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $groupId = $this->collabService->groupIdForStudent($userId);
        if (!$groupId) {
            return $this->apiError('You are not assigned to a group yet.', null, 404);
        }

        $conversation = $this->groupChat->getOrCreateForGroup($groupId);
        if (!$conversation) {
            return $this->apiError('Group not found.', null, 404);
        }

        $thread = $this->messaging->threadForUser((int) $conversation->id, $userId);
        $messages = $thread['messages'] ?? [];

        $participants = DB::table('conversation_participants as cp')
            ->join('users as u', 'u.id', '=', 'cp.user_id')
            ->where('cp.conversation_id', $conversation->id)
            ->select('u.id', 'u.full_name')
            ->orderBy('cp.joined_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();

        return $this->apiSuccess([
            'group_id'         => $groupId,
            'conversation_id'  => (int) $conversation->id,
            'group_name'       => $conversation->group_name ?: 'Group Chat',
            'messages'         => $messages,
            'participants'     => $participants,
        ], 'Group chat retrieved successfully.');
    }
}
