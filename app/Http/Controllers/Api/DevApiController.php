<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Developer\DevScopes;
use App\Services\Developer\SandboxMailService;
use App\Services\Developer\WebhookService;
use App\Services\Mail\MailboxService;
use App\Services\Mail\MailIntegrationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * /api/dev/v1 — Developer API العام. الاعتماد (Bearer uipk_/uipo_) والـ scope بيتفحصوا في DevAuthMiddleware ('dev.auth:scope').
 * mode=sandbox → كل شيء بيروح لصندوق Sandbox (مفيش بريد حقيقي بيتلمس). mode=live → صندوق المستخدم الحقيقي.
 */
class DevApiController extends Controller
{
    use RunsDomain;

    public function __construct(private MailboxService $mail, private SandboxMailService $sandbox, private MailIntegrationService $integ, private WebhookService $hooks)
    {
    }

    private function ctx(Request $r): array
    {
        return (array) $r->attributes->get('dev_ctx');
    }

    private function sb(Request $r): bool
    {
        return $this->ctx($r)['mode'] === 'sandbox';
    }

    /** يشغّل $fn($account) على صندوق المستخدم الحقيقي. بيرفض في sandbox. */
    private function live(Request $r, callable $fn, int $ok = 200, bool $write = false)
    {
        $c = $this->ctx($r);
        if ($c['mode'] === 'sandbox') {
            return $this->apiError('This endpoint is not available in sandbox mode.', ['code' => 'sandbox_unsupported'], 422);
        }
        return $this->dom(function () use ($c, $fn, $write) {
            if (!$c['mail_account_id']) {
                throw new \DomainException('No mailbox is linked to this credential.', 404);
            }
            $a = $this->mail->accountFor((int) $c['user_id'], (int) $c['mail_account_id']);
            if ($write && ($reason = $this->mail->blockReason($a))) {
                throw new \DomainException($reason, 403);
            }
            return $fn($a);
        }, $ok);
    }

    /** يختار sandbox أو live. */
    private function either(Request $r, callable $sandbox, callable $live, int $ok = 200, bool $write = false)
    {
        if ($this->sb($r)) {
            return $this->dom(fn () => $sandbox($this->ctx($r)['user_id']), $ok);
        }
        return $this->live($r, $live, $ok, $write);
    }

    /* ------------------------------------------------------------ me */

    public function me(Request $r)
    {
        $c = $this->ctx($r);
        return $this->dom(function () use ($c) {
            $u = DB::table('users')->where('id', $c['user_id'])->first(['id', 'full_name']);
            $out = ['user' => ['id' => (int) $u->id, 'name' => $u->full_name], 'mode' => $c['mode'], 'kind' => $c['kind'], 'scopes' => $c['scopes'], 'api_version' => config('developer.api_version'),
                'rate_limit' => ['limit' => $c['limit'], 'remaining' => $c['remaining']]];
            if ($c['mode'] === 'sandbox') {
                $out['mailbox'] = $this->sandbox->me((int) $c['user_id']);
            } elseif ($c['mail_account_id']) {
                $out['mailbox'] = $this->mail->accountPayload($this->mail->accountFor((int) $c['user_id'], (int) $c['mail_account_id']));
            }
            return $out;
        });
    }

    public function scopes()
    {
        return $this->apiSuccess(['scopes' => DevScopes::catalog()]);
    }

    /* ------------------------------------------------------------ mail */

    public function folders(Request $r)
    {
        return $this->either($r, fn ($u) => $this->sandbox->folderCounts($u), fn ($a) => $this->mail->folderCounts($a));
    }

    public function messages(Request $r)
    {
        $f = ['folder' => (string) $r->query('folder', 'inbox'), 'label_id' => $r->query('label_id'), 'q' => (string) $r->query('q', ''), 'starred' => $r->boolean('starred'),
            'unread' => $r->boolean('unread'), 'view' => 'messages', 'page' => $r->query('page', 1), 'per_page' => min(50, (int) $r->query('per_page', 25))];
        return $this->either($r, fn ($u) => $this->sandbox->list($u, $f), fn ($a) => $this->mail->list($a, $f));
    }

    public function message(Request $r, int $id)
    {
        $c = $this->ctx($r);
        return $this->either($r, fn ($u) => $this->sandbox->show($u, $id, !$r->boolean('keep_unread')),
            function ($a) use ($r, $id) {
                $m = $this->mail->show($a, $id, !$r->boolean('keep_unread'));
                if (!empty($m['confidential'])) { // الرسائل السرية مش بتتقرا من الـ API
                    throw new \DomainException('Confidential messages are not available through the API.', 403);
                }
                return $m;
            });
    }

    public function thread(Request $r, int $threadId)
    {
        return $this->live($r, function ($a) use ($threadId) {
            $msgs = array_values(array_filter((array) json_decode((string) json_encode($this->mail->thread($a, $threadId)), true), fn ($m) => empty($m['confidential'])));
            return ['messages' => $msgs];
        });
    }

    private function composeRules(): array
    {
        return ['subject' => 'nullable|string|max:250', 'body' => 'nullable|string|max:200000', 'to' => 'nullable|array|max:50', 'cc' => 'nullable|array|max:50', 'bcc' => 'nullable|array|max:50',
            'to.*' => 'nullable', 'cc.*' => 'nullable', 'bcc.*' => 'nullable', 'in_reply_to_item_id' => 'nullable|integer', 'schedule_at' => 'nullable|date'];
    }

    public function send(Request $r)
    {
        $in = $r->validate($this->composeRules());
        return $this->either($r, fn ($u) => $this->sandbox->send($u, $in), fn ($a) => $this->mail->send($a, $in), 201, true);
    }

    public function saveDraft(Request $r)
    {
        $in = $r->validate($this->composeRules());
        return $this->either($r, fn ($u) => $this->sandbox->send($u, $in, true), fn ($a) => $this->mail->saveDraft($a, $in), 201, true);
    }

    public function updateDraft(Request $r, int $id)
    {
        $in = $r->validate($this->composeRules());
        return $this->live($r, fn ($a) => $this->mail->saveDraft($a, $in, $id), 200, true);
    }

    public function sendDraft(Request $r, int $id)
    {
        $in = $r->validate($this->composeRules());
        return $this->live($r, fn ($a) => $this->mail->send($a, $in, $id), 201, true);
    }

    public function update(Request $r, int $id)
    {
        $in = $r->validate(['is_read' => 'sometimes|boolean', 'is_starred' => 'sometimes|boolean', 'folder' => 'sometimes|string|in:inbox,archive,spam,trash']);
        return $this->either($r, fn ($u) => $this->sandbox->update($u, $id, $in), function ($a) use ($in, $id) {
            if (array_key_exists('is_read', $in)) { $this->mail->bulk($a, [$id], $in['is_read'] ? 'read' : 'unread'); }
            if (array_key_exists('is_starred', $in)) { $this->mail->bulk($a, [$id], $in['is_starred'] ? 'star' : 'unstar'); }
            if (isset($in['folder'])) { $this->mail->bulk($a, [$id], $in['folder']); }
            return $this->mail->show($a, $id, false);
        }, 200, true);
    }

    public function destroy(Request $r, int $id)
    {
        return $this->either($r, fn ($u) => ['affected' => $this->sandbox->delete($u, $id, $r->boolean('permanent'))],
            fn ($a) => ['affected' => $this->mail->bulk($a, [$id], $r->boolean('permanent') ? 'delete_forever' : 'trash')], 200, true);
    }

    public function labels(Request $r)
    {
        return $this->live($r, fn ($a) => ['labels' => $this->mail->labels($a)]);
    }

    public function storeLabel(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:9']);
        return $this->live($r, fn ($a) => $this->mail->saveLabel($a, $in), 201, true);
    }

    public function updateLabel(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|max:60', 'color' => 'nullable|string|max:9']);
        return $this->live($r, fn ($a) => $this->mail->saveLabel($a, $in, $id), 200, true);
    }

    public function destroyLabel(Request $r, int $id)
    {
        return $this->live($r, function ($a) use ($id) { $this->mail->deleteLabel($a, $id); return null; }, 200, true);
    }

    /* ------------------------------------------------------------ ربط البريد بالمنصة */

    public function shareChat(Request $r, int $id)
    {
        $in = $r->validate(['conversation_id' => 'nullable|integer', 'email' => 'nullable|email|max:190', 'note' => 'nullable|string|max:500']);
        return $this->live($r, fn ($a) => $this->integ->shareToChat($a, $id, $in), 201, true);
    }

    public function meeting(Request $r, int $id)
    {
        $in = $r->validate(['title' => 'nullable|string|max:200', 'description' => 'nullable|string|max:5000', 'scheduled_start_at' => 'nullable|date', 'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'invite' => 'nullable|boolean', 'message' => 'nullable|string|max:1000']);
        return $this->live($r, fn ($a) => $this->integ->createMeeting($a, $id, $in), 201, true);
    }

    public function saveDrive(Request $r, int $id)
    {
        $in = $r->validate(['what' => 'nullable|string|in:message,attachments,all', 'folder_id' => 'nullable|integer']);
        return $this->live($r, fn ($a) => $this->integ->saveToDrive($a, $id, $in), 201, true);
    }

    public function ai(Request $r, string $action)
    {
        $in = $r->validate(['item_id' => 'nullable|integer', 'text' => 'nullable|string|max:20000', 'tone' => 'nullable|string|max:20', 'language' => 'nullable|string|max:5', 'instruction' => 'nullable|string|max:500']);
        return $this->live($r, fn ($a) => $this->integ->ai($a, $action, $in));
    }

    /** إشعار للمستخدم صاحب الاعتماد (scope notifications.write). محدود 30/ساعة لكل اعتماد. */
    public function notify(Request $r)
    {
        $in = $r->validate(['title' => 'required|string|max:120', 'body' => 'nullable|string|max:500']);
        $c = $this->ctx($r);
        if ($c['mode'] === 'sandbox') {
            return $this->apiSuccess(['delivered' => false, 'sandbox' => true]);
        }
        $rk = 'dev:notify:' . $c['rate_key'];
        if (RateLimiter::tooManyAttempts($rk, 30)) {
            return $this->apiError('Too many notifications. Try again later.', ['code' => 'rate_limited'], 429);
        }
        RateLimiter::hit($rk, 3600);
        $app = $c['app_id'] ? (string) DB::table('dev_apps')->where('id', $c['app_id'])->value('name') : 'API key';
        app(NotificationService::class)->notify((int) $c['user_id'], 'developer_app', mb_substr($app . ': ' . $in['title'], 0, 190), $in['body'] ?? null);
        return $this->apiSuccess(['delivered' => true]);
    }

    /* ------------------------------------------------------------ webhooks (scope webhooks.manage) */

    public function webhooks(Request $r)
    {
        return $this->dom(fn () => ['webhooks' => $this->hooks->list((int) $this->ctx($r)['user_id'])]);
    }

    public function createWebhook(Request $r)
    {
        $in = $r->validate(['name' => 'nullable|string|max:80', 'url' => 'required|string|max:500', 'events' => 'required|array|min:1', 'events.*' => 'string|max:60']);
        $c = $this->ctx($r);
        $in['mode'] = $c['mode'];                         // الـ webhook بياخد mode الاعتماد نفسه
        $in['mail_account_id'] = $c['mail_account_id'];
        return $this->dom(fn () => $this->hooks->create((int) $c['user_id'], $in, $c['app_id']), 201);
    }

    public function deleteWebhook(Request $r, int $id)
    {
        return $this->dom(function () use ($r, $id) { $this->hooks->delete((int) $this->ctx($r)['user_id'], $id); return null; });
    }

    /* ------------------------------------------------------------ sandbox helpers */

    public function sandboxSimulate(Request $r)
    {
        if (!$this->sb($r)) {
            return $this->apiError('Only available with a sandbox key.', ['code' => 'sandbox_only'], 422);
        }
        $in = $r->validate(['from' => 'nullable|email|max:190', 'from_name' => 'nullable|string|max:120', 'subject' => 'nullable|string|max:250', 'body' => 'nullable|string|max:50000']);
        return $this->dom(fn () => $this->sandbox->simulateIncoming((int) $this->ctx($r)['user_id'], $in), 201);
    }

    public function sandboxReset(Request $r)
    {
        if (!$this->sb($r)) {
            return $this->apiError('Only available with a sandbox key.', ['code' => 'sandbox_only'], 422);
        }
        return $this->dom(function () use ($r) { $this->sandbox->reset((int) $this->ctx($r)['user_id']); return null; });
    }
}
