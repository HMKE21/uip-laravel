<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * /api/v1/mail — UIPE Mail (المرحلة 1): الحساب، الصناديق، القوائم، القراءة، الإرسال، المسودات، التصنيفات، المرفقات.
 * كل شيء بيتحدد بحساب البريد بتاع المستخدم الحالي (JWT) — مفيش طريقة لقراءة صندوق حد تاني.
 */
class MailApiController extends Controller
{
    public function __construct(protected MailboxService $mail, protected MailSettingsService $settings)
    {
    }

    /** الصندوق المطلوب: ?account_id= أو الهيدر X-Mail-Account (لازم يكون بتاع نفس المستخدم، وإلا 404). من غيرهم → الصندوق الافتراضي. */
    protected function accountOf(Request $r)
    {
        $want = (int) ($r->query('account_id') ?: $r->header('X-Mail-Account'));
        return $this->mail->accountFor((int) $r->attributes->get('uip_user_id'), $want ?: null);
    }

    protected function run(Request $request, callable $fn, int $okStatus = 200)
    {
        try {
            $acc = $this->accountOf($request);
            if (!$request->isMethod('GET') && ($reason = $this->mail->blockReason($acc))) {
                return $this->apiError($reason, null, 403);
            }
            return $this->apiSuccess($fn($acc), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    public function account(Request $r)
    {
        return $this->run($r, function ($a) {
            $this->mail->processDue((int) $a->id);   // رسائل صندوقك اللي وقتها جه (مجدولة / Undo) تتسلّم حتى لو الـ scheduler واقف
            $this->mail->wakeSnoozed((int) $a->id);  // المؤجّلات اللي وقتها جه ترجع للوارد
            return ['account' => $this->mail->accountPayload($a), 'accounts' => $this->mail->accountsFor((int) $a->user_id),
                'quota' => $this->mail->quotaPayload($a), 'folders' => $this->mail->folderCounts($a), 'labels' => $this->mail->labels($a), 'settings' => $this->settings->settingsPayload($a)];
        });
    }

    public function index(Request $r)
    {
        return $this->run($r, function ($a) use ($r) {
            $this->mail->wakeSnoozed((int) $a->id);
            return $this->mail->list($a, [
                'folder' => (string) $r->query('folder', 'inbox'), 'label_id' => $r->query('label_id'), 'q' => (string) $r->query('q', ''),
                'starred' => $r->boolean('starred'), 'unread' => $r->boolean('unread'), 'view' => (string) $r->query('view', 'messages'),
                'page' => $r->query('page', 1), 'per_page' => $r->query('per_page'),
            ]);
        });
    }

    public function show(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => $this->mail->show($a, $id, !$r->boolean('keep_unread')));
    }

    public function thread(Request $r, int $threadId)
    {
        return $this->run($r, fn ($a) => ['messages' => $this->mail->thread($a, $threadId)]);
    }

    private function composeRules(): array
    {
        return [
            'subject' => 'nullable|string|max:250', 'body' => 'nullable|string',
            'to' => 'nullable|array', 'cc' => 'nullable|array', 'bcc' => 'nullable|array',
            'to.*' => 'nullable', 'cc.*' => 'nullable', 'bcc.*' => 'nullable',
            'attachment_ids' => 'nullable|array', 'attachment_ids.*' => 'integer',
            'in_reply_to_item_id' => 'nullable|integer', 'forward_of_item_id' => 'nullable|integer', 'draft_id' => 'nullable|integer',
            'schedule_at' => 'nullable|date',
            'confidential' => 'nullable|array', 'confidential.expires_days' => 'nullable|integer|min:1|max:365',
            'confidential.passcode' => 'nullable|string|max:40', 'confidential.keep_passcode' => 'nullable|boolean',
        ];
    }

    public function send(Request $r)
    {
        $in = $r->validate($this->composeRules());
        return $this->run($r, fn ($a) => $this->mail->send($a, $in, $in['draft_id'] ?? null), 201);
    }

    public function saveDraft(Request $r)
    {
        $in = $r->validate($this->composeRules());
        return $this->run($r, fn ($a) => $this->mail->saveDraft($a, $in, $in['draft_id'] ?? null), 201);
    }

    public function updateDraft(Request $r, int $id)
    {
        $in = $r->validate($this->composeRules());
        return $this->run($r, fn ($a) => $this->mail->saveDraft($a, $in, $id));
    }

    public function sendDraft(Request $r, int $id)
    {
        $in = $r->validate($this->composeRules());
        return $this->run($r, fn ($a) => $this->mail->send($a, $in, $id), 201);
    }

    /** PATCH /messages/{id} — { is_read?, is_starred?, folder? } لنسخة واحدة. */
    public function update(Request $r, int $id)
    {
        $in = $r->validate(['is_read' => 'sometimes|boolean', 'is_starred' => 'sometimes|boolean', 'folder' => 'sometimes|string|in:inbox,archive,spam,trash']);
        return $this->run($r, function ($a) use ($in, $id) {
            if (array_key_exists('is_read', $in)) { $this->mail->bulk($a, [$id], $in['is_read'] ? 'read' : 'unread'); }
            if (array_key_exists('is_starred', $in)) { $this->mail->bulk($a, [$id], $in['is_starred'] ? 'star' : 'unstar'); }
            if (isset($in['folder'])) { $this->mail->bulk($a, [$id], $in['folder']); }
            return $this->mail->show($a, $id, false);
        });
    }

    /** POST /messages/bulk — { ids[]? , thread_ids[]? , action, label_id? } (thread_ids = المحادثة كاملة) */
    public function bulk(Request $r)
    {
        $in = $r->validate(['ids' => 'nullable|array|max:200', 'ids.*' => 'integer', 'thread_ids' => 'nullable|array|max:200', 'thread_ids.*' => 'integer',
            'action' => 'required|string|max:20', 'label_id' => 'nullable|integer', 'until' => 'nullable|date']);
        if (empty($in['ids']) && empty($in['thread_ids'])) {
            return $this->apiError('Select at least one message.', null, 422);
        }
        return $this->run($r, function ($a) use ($in) {
            $ids = array_merge($in['ids'] ?? [], $this->mail->itemIdsForThreads($a, $in['thread_ids'] ?? []));
            return ['affected' => $this->mail->bulk($a, $ids, $in['action'], $in['label_id'] ?? null, $in['until'] ?? null), 'folders' => $this->mail->folderCounts($a)];
        });
    }

    /** POST /messages/{id}/release — فترة Undo خلصت: سلّم الرسالة دلوقتي (idempotent). */
    public function release(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => $this->mail->release($a, $id));
    }

    /** POST /messages/{id}/cancel-send — تراجع عن إرسال محجوز أو إلغاء رسالة مجدولة → بترجع مسودة. */
    public function cancelSend(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => $this->mail->cancelSend($a, $id));
    }

    /** POST /messages/{id}/unlock { passcode } — فتح رسالة سرية محمية بكلمة سر (30 دقيقة). */
    public function unlock(Request $r, int $id)
    {
        $in = $r->validate(['passcode' => 'required|string|max:40']);
        return $this->run($r, fn ($a) => $this->mail->unlock($a, $id, $in['passcode']));
    }

    /** POST /messages/{id}/revoke { revoke?: bool } — صاحب الرسالة السرية يسحب (أو يرجّع) صلاحية القراءة. */
    public function revoke(Request $r, int $id)
    {
        $in = $r->validate(['revoke' => 'sometimes|boolean']);
        return $this->run($r, fn ($a) => $this->mail->revoke($a, $id, $in['revoke'] ?? true));
    }

    public function destroy(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => ['affected' => $this->mail->bulk($a, [$id], $r->boolean('permanent') ? 'delete_forever' : 'trash')]);
    }

    public function emptyTrash(Request $r)
    {
        return $this->run($r, fn ($a) => ['deleted' => $this->mail->emptyTrash($a)]);
    }

    /* labels */
    public function labels(Request $r)
    {
        return $this->run($r, fn ($a) => ['labels' => $this->mail->labels($a)]);
    }

    public function storeLabel(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:9']);
        return $this->run($r, fn ($a) => $this->mail->saveLabel($a, $in), 201);
    }

    public function updateLabel(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:9']);
        return $this->run($r, fn ($a) => $this->mail->saveLabel($a, $in, $id));
    }

    public function destroyLabel(Request $r, int $id)
    {
        return $this->run($r, function ($a) use ($id) { $this->mail->deleteLabel($a, $id); return null; });
    }

    /* attachments */
    public function upload(Request $r)
    {
        $r->validate(['file' => 'required|file']);
        return $this->run($r, fn ($a) => $this->mail->uploadAttachment($a, $r->file('file')), 201);
    }

    public function deleteAttachment(Request $r, int $id)
    {
        return $this->run($r, function ($a) use ($id) { $this->mail->deleteAttachment($a, $id); return null; });
    }

    public function download(Request $r, int $id)
    {
        try {
            $a = $this->accountOf($r);
            $att = $this->mail->attachmentForDownload($a, $id);
            $disk = Storage::disk($att->disk);
            if (!$disk->exists($att->path)) {
                return $this->apiError('File is missing.', null, 404);
            }
            return $disk->download($att->path, $att->name, ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'application/octet-stream']);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }
}
