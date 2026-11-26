<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\MessagingOversightAccessService;
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

    public function __construct(private AuditLogService $auditLog, private MessagingOversightAccessService $access)
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
            // البحث بالمستخدم فقط: البحث في العنوان/نص الرسايل كان هيكشف محتوى مقفول (تخمين كلمات).
            $query->where(function ($w) use ($like) {
                $w->where('u.full_name', 'like', $like)
                  ->orWhere('u.email', 'like', $like);
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

        // عنوان المحادثة بيتولّد من أول رسالة = محتوى: بيتخفى إلا لو فيه تصريح ساري بيغطيها.
        $grants = $this->access->activeGrants((int) $request->attributes->get('uip_user_id'), 'ai');
        foreach ($items as &$it) {
            if (!$this->access->coversConversation($grants, (int) $it['id'])) {
                $it['title'] = null;
                $it['title_locked'] = true;
            }
        }
        unset($it);

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
                ->get(['id', 'message_id', 'kind', 'original_name', 'mime_type', 'extension', 'size_bytes'])->groupBy('message_id')
            : collect();

        $adminId = (int) $request->attributes->get('uip_user_id');
        $grants = $this->access->activeGrants($adminId, 'ai', (int) $id);
        $open = 0;
        $locked = 0;

        foreach ($messages as &$m) {
            $m['attachments'] = ($attachments[$m['id']] ?? collect())->map(function ($a) {
                $a = (array) $a;
                // endpoint الأدمن المحمي بالتصريح — الملفات بتتحمّل بطلب موثّق (Bearer)
                $a['url'] = '/api/v1/admin/messaging/ai-conversations/attachments/' . (int) $a['id'] . '/file';

                return $a;
            })->values()->all();
            $m['is_deleted']  = (bool) $m['is_deleted'];
            // بعض الموديلات بتكتب <tool_call> كنص: مش بيتعرض (ولا للمحادثات القديمة المخزّنة).
            if (is_string($m['content'] ?? null)) {
                $m['content'] = self::stripToolCalls($m['content']);
            }
            // الإخفاء على السيرفر: برا الفترة المصرّح بيها بترجع مشفّرة وبدون نص/أسماء ملفات/أخطاء.
            if ($this->access->covers($grants, $m['created_at'] ?? null)) {
                $m['locked'] = false;
                $open++;
            } else {
                $m = $this->access->maskAiMessage($m);
                $locked++;
            }
        }
        unset($m);

        $conv = (array) $conversation;
        if (!$this->access->coversConversation($grants, (int) $id)) {
            $conv['title'] = null;
            $conv['title_locked'] = true;
        }

        $this->auditLog->record($adminId, 'admin.ai_conversation_viewed', 'AiConversation', (int) $id, null, ['messages_opened' => $open, 'messages_locked' => $locked]);

        return $this->apiSuccess([
            'conversation' => $conv,
            'messages'     => $messages,
            'access'       => [
                'scope'      => 'ai',
                'open'       => $open,
                'locked'     => $locked,
                'grants'     => array_map(fn ($g) => [
                    'id' => (int) $g['id'], 'date_from' => substr((string) $g['date_from'], 0, 10), 'date_to' => substr((string) $g['date_to'], 0, 10),
                    'expires_at' => (string) $g['expires_at'], 'conversation_id' => $g['conversation_id'] !== null ? (int) $g['conversation_id'] : null,
                ], $grants),
                'configured' => $this->access->keyConfigured('ai'),
            ],
        ], 'AI conversation retrieved successfully.');
    }

    /** يشيل بلوكات <tool_call> / <function_call> (مقفولة وغير مقفولة) من نص رد الموديل. */
    private static function stripToolCalls(string $text): string
    {
        if (stripos($text, '<tool_call') === false && stripos($text, '</tool_call') === false && stripos($text, '<function_call') === false) {
            return $text;
        }
        $text = preg_replace('#<(tool_calls?|function_call)\b[^>]*>.*?</\1\s*>#is', '', $text) ?? $text;
        $text = preg_replace('#<(tool_calls?|function_call)\b.*$#is', '', $text) ?? $text;
        $text = preg_replace('#</(tool_calls?|function_call)\s*>#i', '', $text) ?? $text;

        return trim($text);
    }

    /** GET /api/v1/admin/messaging/ai-conversations/attachments/{id}/file — ملف مرفق محادثة AI (محمي بالتصريح، بيتسجّل). */
    public function attachmentFile(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $adminId = (int) $request->attributes->get('uip_user_id');

        $row = DB::table('ai_message_attachments as a')
            ->join('ai_conversations as c', 'c.id', '=', 'a.conversation_id')
            ->leftJoin('ai_messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', (int) $id)
            ->select('a.*', DB::raw('COALESCE(m.created_at, a.created_at) as message_created_at'))
            ->first();
        if (!$row) {
            return $this->apiError('Attachment not found.', null, 404);
        }
        if (!$this->access->canRead($adminId, 'ai', $row->message_created_at ?? null, (int) $row->conversation_id)) {
            return $this->apiError('This content is locked. Open it with the secret key first.', null, 403);
        }

        $path = \App\Support\SafePath::publicPath($row->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File no longer exists.', null, 404);
        }

        $this->auditLog->record($adminId, 'admin.ai_attachment_viewed', 'AiMessageAttachment', (int) $id, null, ['conversation_id' => (int) $row->conversation_id]);

        $mime = strtolower((string) ($row->mime_type ?: 'application/octet-stream'));
        // أي نوع ممكن يشغّل سكريبت (html / svg / xml) بيتنزّل بس ومبيتعرضش inline.
        $unsafe = preg_match('#^(text/html|application/xhtml|image/svg|text/xml|application/xml)#', $mime) === 1;
        if ($unsafe) {
            $mime = 'application/octet-stream';
        }
        $name = (string) $row->original_name;

        // response()->file = streaming + HTTP Range (الفيديو / الصوت بيتقدّم ويتأخّر).
        return response()->file($path, [
            'Content-Type'                 => $mime,
            'Content-Disposition'          => ($unsafe ? 'attachment' : 'inline') . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control'                => 'private, no-store',
        ]);
    }
}
