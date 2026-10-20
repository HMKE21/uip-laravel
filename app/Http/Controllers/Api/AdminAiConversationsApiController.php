<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/admin/messaging/ai-conversations — إشراف الأدمن على محادثات
 * المستخدمين مع المساعد الذكي (ai_conversations / ai_messages) عبر كل البوابات.
 * مكمّل لـ AdminMessagingOversightApiController (اللي بيغطي المحادثات بين الأشخاص).
 *
 * قراءة-فقط، وكل فتح لمحادثة بيتسجّل في الـ audit log. مش بنرجّع context_snapshot
 * ولا extracted_text للمرفقات (بيانات داخلية مش جزء من المحادثة الظاهرة للمستخدم).
 */
class AdminAiConversationsApiController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access AI conversations oversight.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/messaging/ai-conversations — ?search=&portal=&page= */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $search = trim((string) $request->input('search', ''));
        $portal = trim((string) $request->input('portal', ''));
        $page   = max(1, (int) $request->input('page', 1));

        $query = DB::table('ai_conversations as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id');

        if ($portal !== '') {
            $query->where('c.portal', $portal);
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($w) use ($like) {
                $w->where('c.title', 'like', $like)
                  ->orWhere('u.full_name', 'like', $like)
                  ->orWhere('u.email', 'like', $like)
                  ->orWhereExists(function ($sub) use ($like) {
                      $sub->select(DB::raw(1))->from('ai_messages as m')
                          ->whereColumn('m.conversation_id', 'c.id')
                          ->where('m.content', 'like', $like);
                  });
            });
        }

        $total = (clone $query)->distinct()->count('c.id');

        $items = $query
            ->select(
                'c.id', 'c.title', 'c.portal', 'c.message_count', 'c.is_archived', 'c.created_at', 'c.last_message_at',
                'u.id as user_id', 'u.full_name as user_name', 'u.email as user_email', 'u.avatar_path as user_avatar',
                DB::raw('MIN(r.slug) as user_role')
            )
            ->groupBy('c.id', 'c.title', 'c.portal', 'c.message_count', 'c.is_archived', 'c.created_at', 'c.last_message_at',
                'u.id', 'u.full_name', 'u.email', 'u.avatar_path')
            ->orderByDesc(DB::raw('COALESCE(c.last_message_at, c.created_at)'))
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $portals = DB::table('ai_conversations')->distinct()->orderBy('portal')->pluck('portal')->all();

        return $this->apiSuccess([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'portals'  => $portals,
        ], 'AI conversations retrieved successfully.');
    }

    /** GET /api/v1/admin/messaging/ai-conversations/{id} — ثريد قراءة-فقط، بيتسجل. */
    public function thread(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $conversation = DB::table('ai_conversations as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('c.id', (int) $id)
            ->select(
                'c.id', 'c.title', 'c.portal', 'c.message_count', 'c.is_archived', 'c.created_at', 'c.last_message_at',
                'u.id as user_id', 'u.full_name as user_name', 'u.email as user_email', 'u.avatar_path as user_avatar',
                DB::raw('MIN(r.slug) as user_role')
            )
            ->groupBy('c.id', 'c.title', 'c.portal', 'c.message_count', 'c.is_archived', 'c.created_at', 'c.last_message_at',
                'u.id', 'u.full_name', 'u.email', 'u.avatar_path')
            ->first();

        if (!$conversation) {
            return $this->apiError('Conversation not found.', null, 404);
        }

        $messages = DB::table('ai_messages')
            ->where('conversation_id', (int) $id)
            ->where('role', '!=', 'system')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'role', 'content', 'status', 'model', 'answer_source', 'error_message', 'is_deleted', 'edited_at', 'created_at'])
            ->map(fn ($m) => (array) $m)
            ->all();

        $ids = array_column($messages, 'id');
        $attachments = $ids
            ? DB::table('ai_message_attachments')->whereIn('message_id', $ids)
                ->get(['id', 'message_id', 'kind', 'original_name', 'size_bytes'])->groupBy('message_id')
            : collect();

        foreach ($messages as &$m) {
            $m['attachments'] = ($attachments[$m['id']] ?? collect())->map(fn ($a) => (array) $a)->values()->all();
            $m['is_deleted']  = (bool) $m['is_deleted'];
        }
        unset($m);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'admin.ai_conversation_viewed', 'AiConversation', (int) $id);

        return $this->apiSuccess([
            'conversation' => (array) $conversation,
            'messages'     => $messages,
        ], 'AI conversation retrieved successfully.');
    }
}
