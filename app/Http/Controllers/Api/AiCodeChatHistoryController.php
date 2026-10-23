<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\CodeChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * محادثات شات الكود المحفوظة — /api/v1/ai-code/chats/*  (نفس حماية باقي /ai-code: uip.auth + uip.exam_lock + ai.code_assist).
 * كل صف مملوك لصاحبه: أي uuid مش بتاعه بيرجع 404 (مش 403 عشان ما نكشفش وجوده).
 */
class AiCodeChatHistoryController extends Controller
{
    /** GET /chats?q= — محادثات المستخدم، الأحدث أولًا (من غير الرسايل). */
    public function index(Request $request)
    {
        $q = DB::table('ai_code_chats')->where('user_id', $this->uid($request));
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $q->where('title', 'like', '%' . addcslashes(mb_substr($term, 0, 60), '%_\\') . '%');
        }
        $rows = $q->orderByDesc('updated_at')->limit(CodeChatHistory::MAX_CHATS_PER_USER)
            ->get(['uuid', 'title', 'mode', 'scope', 'label', 'message_count', 'created_at', 'updated_at']);

        return $this->apiSuccess(['chats' => $rows->map(fn ($r) => $this->summary($r))->all()]);
    }

    /** GET /chats/{uuid} — محادثة كاملة. */
    public function show(Request $request, string $uuid)
    {
        $row = $this->find($request, $uuid);
        if (!$row) {
            return $this->apiError('Chat not found.', null, 404);
        }
        $messages = json_decode((string) $row->messages, true);

        return $this->apiSuccess(['chat' => $this->summary($row) + ['messages' => is_array($messages) ? $messages : []]]);
    }

    /** PUT /chats/{uuid} — upsert. body: messages[], title?, mode?, scope?, label? */
    public function save(Request $request, string $uuid)
    {
        if (!CodeChatHistory::isUuid($uuid)) {
            return $this->apiError('Invalid chat id.', null, 422);
        }
        $uid = $this->uid($request);
        try {
            $messages = CodeChatHistory::cleanMessages($request->input('messages'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, in_array((int) $e->getCode(), [413, 422], true) ? (int) $e->getCode() : 422);
        }

        $uuid = strtolower($uuid);
        $existing = DB::table('ai_code_chats')->where('uuid', $uuid)->first(['id', 'user_id', 'title']);
        if ($existing && (int) $existing->user_id !== $uid) {
            return $this->apiError('Chat not found.', null, 404);
        }

        $title = CodeChatHistory::cleanTitle($request->input('title'));
        if ($title === '') {
            $title = $existing && $existing->title !== '' ? (string) $existing->title : CodeChatHistory::deriveTitle($messages);
        }
        $mode = $request->input('mode');
        $data = [
            'title'         => $title !== '' ? $title : 'New chat',
            'mode'          => in_array($mode, ['tutor', 'assistant'], true) ? $mode : null,
            'message_count' => count($messages),
            'messages'      => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'updated_at'    => now(),
        ];

        if ($existing) {
            DB::table('ai_code_chats')->where('id', $existing->id)->update($data);
        } else {
            DB::table('ai_code_chats')->insert($data + [
                'uuid'       => strtolower($uuid),
                'user_id'    => $uid,
                'scope'      => mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('scope', '')) ?? '', 0, 64),
                'label'      => mb_substr(trim(preg_replace('/[^\P{C}]/u', '', (string) $request->input('label', '')) ?? ''), 0, 160),
                'created_at' => now(),
            ]);
            $this->trim($uid);
        }

        return $this->apiSuccess(['chat' => $this->summary($this->find($request, $uuid))]);
    }

    /** PATCH /chats/{uuid} — إعادة تسمية. body: title */
    public function rename(Request $request, string $uuid)
    {
        $title = CodeChatHistory::cleanTitle($request->input('title'));
        if ($title === '') {
            return $this->apiError('Enter a title.', null, 422);
        }
        $n = DB::table('ai_code_chats')->where('uuid', strtolower($uuid))->where('user_id', $this->uid($request))->update(['title' => $title]);

        return $n ? $this->apiSuccess(['title' => $title]) : $this->apiError('Chat not found.', null, 404);
    }

    /** DELETE /chats/{uuid} */
    public function destroy(Request $request, string $uuid)
    {
        $n = DB::table('ai_code_chats')->where('uuid', strtolower($uuid))->where('user_id', $this->uid($request))->delete();

        return $n ? $this->apiSuccess(['deleted' => 1]) : $this->apiError('Chat not found.', null, 404);
    }

    /** DELETE /chats — كل محادثات المستخدم. */
    public function destroyAll(Request $request)
    {
        return $this->apiSuccess(['deleted' => DB::table('ai_code_chats')->where('user_id', $this->uid($request))->delete()]);
    }

    // -------------------------------------------------------------------------

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function find(Request $request, string $uuid): ?object
    {
        return CodeChatHistory::isUuid($uuid)
            ? DB::table('ai_code_chats')->where('uuid', strtolower($uuid))->where('user_id', $this->uid($request))->first()
            : null;
    }

    /** @return array<string,mixed> */
    private function summary(object $r): array
    {
        return [
            'id'            => $r->uuid,
            'title'         => $r->title,
            'mode'          => $r->mode,
            'scope'         => $r->scope,
            'label'         => $r->label,
            'message_count' => (int) $r->message_count,
            'created_at'    => $this->iso($r->created_at),
            'updated_at'    => $this->iso($r->updated_at),
        ];
    }

    /** ISO-8601 بالـ offset (الداتابيز بتخزّن من غير timezone، والفرونت محتاج وقت صح من أي جهاز). */
    private function iso($v): ?string
    {
        try {
            return $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** بنحتفظ بآخر MAX_CHATS_PER_USER محادثة بس لكل مستخدم. */
    private function trim(int $uid): void
    {
        $keep = DB::table('ai_code_chats')->where('user_id', $uid)->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(CodeChatHistory::MAX_CHATS_PER_USER)->pluck('id');
        if ($keep->count() >= CodeChatHistory::MAX_CHATS_PER_USER) {
            DB::table('ai_code_chats')->where('user_id', $uid)->whereNotIn('id', $keep->all())->delete();
        }
    }
}
