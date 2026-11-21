<?php

namespace App\Services\Developer;

/**
 * OpenAPI 3.1 للـ Developer API (/api/dev/v1) + OAuth (/api/oauth) + أحداث الـ Webhooks.
 *
 * الـ spec بيتولّد من الـ routes الفعلية (كل route عليه middleware `dev.auth[:scope]`)، فالمسار والـ method والـ scope المطلوب مصدرهم الحقيقي هو routes/developer.php.
 * الوصف والـ schemas في `catalog()` تحت. لو حد أضاف route من غير ما يكتب له مدخل هنا، `unmapped()` بترجّعه واختبار DevOpenApiTest بيفشل — عشان التوثيق ميتأخرش عن الكود.
 * البناء نفسه (build) مبيلمسش DB ولا Laravel إلا config() و DevScopes، فبيتختبر لوحده.
 */
final class DevOpenApi
{
    public const OAUTH_ROUTES = ['POST /oauth/token', 'POST /oauth/revoke'];

    /* ------------------------------------------------------------------ schema helpers */

    private static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/' . $name];
    }

    private static function obj(array $props, array $required = [], bool $extra = false): array
    {
        $o = ['type' => 'object', 'properties' => $props];
        if ($required) {
            $o['required'] = $required;
        }
        if ($extra) {
            $o['additionalProperties'] = true;
        }
        return $o;
    }

    private static function str(?string $desc = null, array $more = []): array
    {
        return array_filter(['type' => 'string', 'description' => $desc] + $more, fn ($v) => $v !== null);
    }

    private static function int(?string $desc = null, array $more = []): array
    {
        return array_filter(['type' => 'integer', 'description' => $desc] + $more, fn ($v) => $v !== null);
    }

    private static function bool(?string $desc = null): array
    {
        return array_filter(['type' => 'boolean', 'description' => $desc], fn ($v) => $v !== null);
    }

    private static function arr(array $items, ?string $desc = null, array $more = []): array
    {
        return array_filter(['type' => 'array', 'items' => $items, 'description' => $desc] + $more, fn ($v) => $v !== null);
    }

    /** غلاف الرد الناجح: { success, message, data, errors:null, meta }. */
    private static function envelope(array $data): array
    {
        return ['allOf' => [self::ref('Envelope'), ['type' => 'object', 'properties' => ['data' => $data]]]];
    }

    private static function nullData(): array
    {
        return ['allOf' => [self::ref('Envelope')]];
    }

    /* ------------------------------------------------------------------ catalog */

    /**
     * مفتاح المدخل: "METHOD /path" (المسار نسبةً لـ /api/dev/v1، ومسارات OAuth نسبةً لـ /api).
     * الحقول: id (operationId) · tag · summary · description · modes (both|live|sandbox) · query[] · body (schema) · bodyRequired · ok (status) · data (schema) · errors[] (أكواد خاصة بالـ endpoint).
     */
    public static function catalog(): array
    {
        $compose = self::obj([
            'subject' => self::str(null, ['maxLength' => 250]), 'body' => self::str('HTML or plain text.', ['maxLength' => 200000]),
            'to' => self::arr(['oneOf' => [self::str('Email address.'), self::obj(['address' => self::str(), 'name' => self::str()])]], null, ['maxItems' => 50]),
            'cc' => self::arr(['oneOf' => [self::str(), self::obj(['address' => self::str(), 'name' => self::str()])]], null, ['maxItems' => 50]),
            'bcc' => self::arr(['oneOf' => [self::str(), self::obj(['address' => self::str(), 'name' => self::str()])]], null, ['maxItems' => 50]),
            'in_reply_to_item_id' => self::int('Reply to this message id.'), 'schedule_at' => self::str('Send later (ISO 8601).', ['format' => 'date-time']),
        ]);
        $label = self::obj(['name' => self::str(null, ['maxLength' => 60]), 'color' => self::str('Hex color, e.g. #1a73e8.', ['maxLength' => 9])], ['name']);
        $extParam = ['name' => 'external_id', 'in' => 'path', 'required' => true, 'schema' => self::str('Your own id for the user (letters, digits and . _ : @ -).', ['maxLength' => 128])];
        $idParam = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => self::int()];

        return [
            'GET /me' => ['id' => 'getMe', 'tag' => 'Account', 'summary' => 'Who am I', 'modes' => 'both',
                'description' => 'The user behind the credential, its mode (`live` or `sandbox`), granted scopes and the remaining rate limit.', 'data' => self::ref('Me')],
            'GET /scopes' => ['id' => 'listScopes', 'tag' => 'Account', 'summary' => 'List available scopes', 'modes' => 'both',
                'description' => 'Every scope the platform defines. Needs a valid credential but no particular scope.', 'data' => self::obj(['scopes' => self::arr(self::ref('Scope'))], ['scopes'])],

            'GET /mail/folders' => ['id' => 'listFolders', 'tag' => 'Mail', 'summary' => 'Folder counts', 'modes' => 'both',
                'description' => 'Total and unread counts per folder. Live mailboxes also have `archive`, `spam`, `scheduled` and `snoozed`; sandbox mailboxes have `inbox`, `sent`, `drafts`, `trash` and `starred`.',
                'data' => ['type' => 'object', 'additionalProperties' => self::ref('FolderCount')]],
            'GET /mail/messages' => ['id' => 'listMessages', 'tag' => 'Mail', 'summary' => 'List messages', 'modes' => 'both',
                'query' => [
                    ['name' => 'folder', 'schema' => self::str(null, ['default' => 'inbox']), 'description' => 'inbox, sent, drafts, archive, spam, trash … (sandbox: inbox, sent, drafts, trash).'],
                    ['name' => 'q', 'schema' => self::str(), 'description' => 'Free-text search.'],
                    ['name' => 'label_id', 'schema' => self::int()],
                    ['name' => 'starred', 'schema' => self::bool()], ['name' => 'unread', 'schema' => self::bool()],
                    ['name' => 'page', 'schema' => self::int(null, ['minimum' => 1, 'default' => 1])],
                    ['name' => 'per_page', 'schema' => self::int(null, ['minimum' => 1, 'maximum' => 50, 'default' => 25])],
                ], 'data' => self::ref('MessagePage')],
            'GET /mail/messages/{id}' => ['id' => 'getMessage', 'tag' => 'Mail', 'summary' => 'Get a message', 'modes' => 'both', 'path' => [$idParam],
                'description' => 'Reading marks the message as read unless `keep_unread=1`. Confidential messages are never available through the API (403).',
                'query' => [['name' => 'keep_unread', 'schema' => self::bool(), 'description' => 'Do not mark the message as read.']], 'data' => self::ref('Message')],
            'GET /mail/threads/{threadId}' => ['id' => 'getThread', 'tag' => 'Mail', 'summary' => 'Get a conversation', 'modes' => 'live',
                'path' => [['name' => 'threadId', 'in' => 'path', 'required' => true, 'schema' => self::int()]],
                'description' => 'All messages of a thread (confidential ones are left out).', 'data' => self::obj(['messages' => self::arr(self::ref('Message'))], ['messages'])],
            'POST /mail/messages' => ['id' => 'sendMessage', 'tag' => 'Mail', 'summary' => 'Send a message', 'modes' => 'both', 'ok' => 201,
                'description' => 'Sandbox keys deliver to the sandbox mailbox only; nothing real is sent.', 'body' => $compose, 'data' => self::ref('Message')],
            'POST /mail/drafts' => ['id' => 'createDraft', 'tag' => 'Mail', 'summary' => 'Save a draft', 'modes' => 'both', 'ok' => 201, 'body' => $compose, 'data' => self::ref('Message')],
            'PUT /mail/drafts/{id}' => ['id' => 'updateDraft', 'tag' => 'Mail', 'summary' => 'Update a draft', 'modes' => 'live', 'path' => [$idParam], 'body' => $compose, 'data' => self::ref('Message')],
            'POST /mail/drafts/{id}/send' => ['id' => 'sendDraft', 'tag' => 'Mail', 'summary' => 'Send a saved draft', 'modes' => 'live', 'ok' => 201, 'path' => [$idParam], 'body' => $compose, 'data' => self::ref('Message')],
            'PATCH /mail/messages/{id}' => ['id' => 'updateMessage', 'tag' => 'Mail', 'summary' => 'Mark, star or move a message', 'modes' => 'both', 'path' => [$idParam],
                'body' => self::obj(['is_read' => self::bool(), 'is_starred' => self::bool(), 'folder' => self::str(null, ['enum' => ['inbox', 'archive', 'spam', 'trash']])]), 'data' => self::ref('Message')],
            'DELETE /mail/messages/{id}' => ['id' => 'deleteMessage', 'tag' => 'Mail', 'summary' => 'Move a message to trash', 'modes' => 'both', 'path' => [$idParam],
                'description' => 'Moves to trash, or deletes forever with `permanent=1`.', 'query' => [['name' => 'permanent', 'schema' => self::bool()]],
                'data' => self::obj(['affected' => self::int()], ['affected'])],

            'GET /mail/labels' => ['id' => 'listLabels', 'tag' => 'Labels', 'summary' => 'List labels', 'modes' => 'live', 'data' => self::obj(['labels' => self::arr(self::ref('Label'))], ['labels'])],
            'POST /mail/labels' => ['id' => 'createLabel', 'tag' => 'Labels', 'summary' => 'Create a label', 'modes' => 'live', 'ok' => 201, 'body' => $label, 'bodyRequired' => true, 'data' => self::ref('Label')],
            'PUT /mail/labels/{id}' => ['id' => 'updateLabel', 'tag' => 'Labels', 'summary' => 'Rename or recolor a label', 'modes' => 'live', 'path' => [$idParam], 'body' => $label, 'bodyRequired' => true, 'data' => self::ref('Label')],
            'DELETE /mail/labels/{id}' => ['id' => 'deleteLabel', 'tag' => 'Labels', 'summary' => 'Delete a label', 'modes' => 'live', 'path' => [$idParam], 'data' => null],

            'POST /mail/messages/{id}/share-chat' => ['id' => 'shareMessageToChat', 'tag' => 'Integrations', 'summary' => 'Share a message into a chat', 'modes' => 'live', 'ok' => 201, 'path' => [$idParam],
                'description' => 'Give either `conversation_id` or `email` (opens a direct conversation).',
                'body' => self::obj(['conversation_id' => self::int(), 'email' => self::str(null, ['format' => 'email', 'maxLength' => 190]), 'note' => self::str(null, ['maxLength' => 500])]),
                'data' => self::obj(['conversation_id' => self::int(), 'message' => ['type' => 'object', 'additionalProperties' => true]], [], true)],
            'POST /mail/messages/{id}/meeting' => ['id' => 'createMeetingFromMessage', 'tag' => 'Integrations', 'summary' => 'Create a meeting from a message', 'modes' => 'live', 'ok' => 201, 'path' => [$idParam],
                'body' => self::obj(['title' => self::str(null, ['maxLength' => 200]), 'description' => self::str(null, ['maxLength' => 5000]), 'scheduled_start_at' => self::str(null, ['format' => 'date-time']),
                    'duration_minutes' => self::int(null, ['minimum' => 5, 'maximum' => 1440]), 'invite' => self::bool('Invite the participants of the message.'), 'message' => self::str(null, ['maxLength' => 1000])]),
                'data' => self::obj(['uuid' => self::str(), 'code' => self::str(), 'title' => self::str(), 'type' => self::str(), 'scheduled_start_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'url' => self::str(), 'invited' => self::int()], [], true)],
            'POST /mail/messages/{id}/save-drive' => ['id' => 'saveMessageToDrive', 'tag' => 'Integrations', 'summary' => 'Save a message or its attachments to Drive', 'modes' => 'live', 'ok' => 201, 'path' => [$idParam],
                'body' => self::obj(['what' => self::str(null, ['enum' => ['message', 'attachments', 'all']]), 'folder_id' => self::int()]),
                'data' => self::obj(['folder' => self::obj(['id' => self::int(), 'name' => self::str()]), 'saved' => self::arr(['type' => 'object', 'additionalProperties' => true]), 'failed' => self::arr(['type' => 'object', 'additionalProperties' => true])], [], true)],
            'POST /mail/ai/{action}' => ['id' => 'runMailAi', 'tag' => 'Integrations', 'summary' => 'AI assistant on a message or text', 'modes' => 'live',
                'description' => 'Limited to 20 requests per minute. Give `item_id` (a message) or `text`.',
                'path' => [['name' => 'action', 'in' => 'path', 'required' => true, 'schema' => self::str(null, ['enum' => ['summarize', 'reply', 'translate', 'improve']])]],
                'body' => self::obj(['item_id' => self::int(), 'text' => self::str(null, ['maxLength' => 20000]), 'tone' => self::str(null, ['maxLength' => 20]), 'language' => self::str(null, ['maxLength' => 5]), 'instruction' => self::str(null, ['maxLength' => 500])]),
                'data' => self::obj(['action' => self::str(), 'text' => self::str()], [], true)],
            'POST /notifications' => ['id' => 'sendNotification', 'tag' => 'Integrations', 'summary' => 'Notify the credential owner', 'modes' => 'both',
                'description' => 'At most 30 per hour per credential. Sandbox credentials get `{delivered:false, sandbox:true}`.',
                'body' => self::obj(['title' => self::str(null, ['maxLength' => 120]), 'body' => self::str(null, ['maxLength' => 500])], ['title']), 'bodyRequired' => true,
                'data' => self::obj(['delivered' => self::bool(), 'sandbox' => self::bool()], ['delivered'])],

            'GET /webhooks' => ['id' => 'listWebhooks', 'tag' => 'Webhooks', 'summary' => 'List webhooks', 'modes' => 'both',
                'description' => 'Only the webhooks of the credential\'s own mode: a live credential never sees sandbox webhooks, and the other way round.',
                'data' => self::obj(['webhooks' => self::arr(self::ref('Webhook'))], ['webhooks'])],
            'POST /webhooks' => ['id' => 'createWebhook', 'tag' => 'Webhooks', 'summary' => 'Create a webhook', 'modes' => 'both', 'ok' => 201,
                'description' => 'The webhook takes the mode of the credential that creates it. The signing `secret` is returned once, here. See the `webhooks` section for the events and how to verify a delivery.',
                'body' => self::obj(['name' => self::str(null, ['maxLength' => 80]), 'url' => self::str('Public HTTPS URL.', ['maxLength' => 500, 'format' => 'uri']),
                    'events' => self::arr(self::str(null, ['enum' => array_keys((array) config('developer.events', []))]), null, ['minItems' => 1])], ['url', 'events']), 'bodyRequired' => true,
                'data' => self::ref('WebhookWithSecret')],
            'DELETE /webhooks/{id}' => ['id' => 'deleteWebhook', 'tag' => 'Webhooks', 'summary' => 'Delete a webhook', 'modes' => 'both', 'path' => [$idParam],
                'description' => 'Only webhooks of the credential\'s own mode can be deleted (`404` otherwise).', 'data' => null],

            'GET /participants' => ['id' => 'listParticipants', 'tag' => 'Participants', 'summary' => 'List participants', 'modes' => 'live',
                'description' => 'The end users of your workspace (employees, students…), identified by your own `external_id`. They do not need a UIPE account. Needs an API key that belongs to a workspace (`403 tenant_required` otherwise).',
                'query' => [
                    ['name' => 'q', 'schema' => self::str(null, ['maxLength' => 100]), 'description' => 'Search name, external_id or email.'],
                    ['name' => 'role', 'schema' => self::str(null, ['enum' => array_values((array) config('tenancy.participant_roles'))])],
                    ['name' => 'page', 'schema' => self::int(null, ['minimum' => 1, 'default' => 1])],
                    ['name' => 'per_page', 'schema' => self::int(null, ['minimum' => 1, 'maximum' => 100, 'default' => 25])],
                ], 'data' => self::ref('ParticipantPage')],
            'GET /participants/{external_id}' => ['id' => 'getParticipant', 'tag' => 'Participants', 'summary' => 'Get a participant', 'modes' => 'live', 'path' => [$extParam],
                'description' => 'Look a participant up by the id you use in your own system (`404` if unknown).', 'data' => self::ref('Participant')],
            'PUT /participants/{external_id}' => ['id' => 'upsertParticipant', 'tag' => 'Participants', 'summary' => 'Create or update a participant', 'modes' => 'live', 'path' => [$extParam],
                'description' => 'Idempotent upsert keyed by `external_id`. Returns `201` when created and `200` when updated. On update only the fields you send change. `name` is required on create.',
                'body' => self::obj(['name' => self::str(null, ['maxLength' => 160]), 'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                    'role' => self::str(null, ['enum' => array_values((array) config('tenancy.participant_roles'))]), 'meta' => ['type' => ['object', 'null'], 'description' => 'Free-form JSON, at most 4 KB.']]),
                'data' => self::ref('Participant')],
            'DELETE /participants/{external_id}' => ['id' => 'deleteParticipant', 'tag' => 'Participants', 'summary' => 'Delete a participant', 'modes' => 'live', 'path' => [$extParam],
                'description' => 'Deletes the participant and ends every embed session they hold.', 'data' => null],

            'POST /embed/sessions' => ['id' => 'createEmbedSession', 'tag' => 'Embed', 'summary' => 'Create a join token for a participant', 'modes' => 'live', 'ok' => 201,
                'description' => "Call this from **your server** (never from the browser) when one of your users opens a meeting page. It creates the participant if needed (send `name` the first time) and returns a short-lived, single-use `token` plus a ready `embed_url`.\n\n"
                    . "Pass the result to `meetings.embed({ getToken })` in the browser, or open `embed_url` directly. The token is stored hashed, expires after `ttl` seconds (default " . (int) config('tenancy.embed.join_ttl', 120) . ", at most " . (int) config('tenancy.embed.join_ttl_max', 900) . ") and stops working after its first use.\n\n"
                    . "The page that shows the iframe must be in the workspace's allowed origins, otherwise the browser refuses to display it. Send `origin` to also lock this token to one site.",
                'body' => self::obj([
                    'external_id' => self::str('Your id for the user.', ['maxLength' => 128]), 'name' => self::str('Required the first time this `external_id` is seen.', ['maxLength' => 160]),
                    'email' => ['type' => ['string', 'null'], 'format' => 'email'], 'role' => self::str(null, ['enum' => array_values((array) config('tenancy.participant_roles'))]),
                    'meta' => ['type' => ['object', 'null']], 'room' => self::str('Your meeting reference.', ['maxLength' => 128]),
                    'origin' => self::str('Lock the token to this site, e.g. `https://app.example.com`. Must be an allowed origin.', ['maxLength' => 190]),
                    'ttl' => self::int('Seconds the token stays valid.', ['minimum' => 15, 'maximum' => 3600]),
                ], ['external_id']), 'bodyRequired' => true, 'data' => self::ref('EmbedSession')],

            'POST /sandbox/simulate-incoming' => ['id' => 'sandboxSimulateIncoming', 'tag' => 'Sandbox', 'summary' => 'Simulate an incoming message', 'modes' => 'sandbox', 'ok' => 201,
                'description' => 'Drops a message in the sandbox inbox and fires `mail.message.received` to your sandbox webhooks. Sandbox credentials only.',
                'body' => self::obj(['from' => self::str(null, ['format' => 'email']), 'from_name' => self::str(null, ['maxLength' => 120]), 'subject' => self::str(null, ['maxLength' => 250]), 'body' => self::str(null, ['maxLength' => 50000])]),
                'data' => self::ref('Message')],
            'POST /sandbox/reset' => ['id' => 'sandboxReset', 'tag' => 'Sandbox', 'summary' => 'Reset the sandbox mailbox', 'modes' => 'sandbox', 'description' => 'Sandbox credentials only.', 'data' => null],

            'POST /oauth/token' => ['id' => 'oauthToken', 'tag' => 'OAuth', 'summary' => 'Get or refresh an access token', 'oauth' => true],
            'POST /oauth/revoke' => ['id' => 'oauthRevoke', 'tag' => 'OAuth', 'summary' => 'Revoke a token', 'oauth' => true],
        ];
    }

    /* ------------------------------------------------------------------ routes → [{method, path, scope}] */

    /**
     * يطلّع الـ routes الموثّقة من Laravel: كل route تحت dev/v1 وعليه dev.auth، + مسارات oauth. (استدعاء واحد من برّه عشان build() يفضل نقي.)
     * @return array<int,array{method:string,path:string,scope:?string}>
     */
    public static function routesFromLaravel(): array
    {
        $out = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = '/' . ltrim($route->uri(), '/');
            $scope = null;
            $auth = false;
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && ($mw === 'dev.auth' || str_starts_with($mw, 'dev.auth:'))) {
                    $auth = true;
                    $scope = str_contains($mw, ':') ? substr($mw, strpos($mw, ':') + 1) : null;
                }
            }
            $path = null;
            if ($auth && str_starts_with($uri, '/api/dev/v1/')) {
                $path = substr($uri, strlen('/api/dev/v1'));
            } elseif (in_array($uri, ['/api/oauth/token', '/api/oauth/revoke'], true)) {
                $path = substr($uri, strlen('/api'));
            }
            if ($path === null) {
                continue;
            }
            foreach ($route->methods() as $m) {
                if (in_array($m, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = ['method' => $m, 'path' => $path, 'scope' => $scope];
            }
        }
        usort($out, fn ($a, $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);
        return $out;
    }

    /**
     * الـ mode المسموح للـ endpoint: both | live | sandbox | unknown (مش في الكتالوج).
     * @param string $path زي '/mail/messages/{id}' (من غير /api/dev/v1)
     */
    public static function modeOf(string $method, string $path): string
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::catalog() as $key => $e) {
                if (empty($e['oauth'])) {
                    $index[$key] = (string) ($e['modes'] ?? 'unknown');
                }
            }
        }
        $m = $index[strtoupper($method) . ' ' . $path] ?? 'unknown';
        return in_array($m, ['both', 'live', 'sandbox'], true) ? $m : 'unknown';
    }

    /** مفاتيح "METHOD /path" للـ routes اللي ملهاش مدخل في الكتالوج. */
    public static function unmapped(array $routes): array
    {
        $cat = self::catalog();
        return array_values(array_filter(array_map(fn ($r) => $r['method'] . ' ' . $r['path'], $routes), fn ($k) => !isset($cat[$k])));
    }

    /** مدخلات الكتالوج اللي ملهاش route (اتمسح أو اتغيّر اسمه). */
    public static function orphans(array $routes): array
    {
        $have = array_flip(array_map(fn ($r) => $r['method'] . ' ' . $r['path'], $routes));
        return array_values(array_filter(array_keys(self::catalog()), fn ($k) => !isset($have[$k])));
    }

    /* ------------------------------------------------------------------ build */

    /** @param array<int,array{method:string,path:string,scope:?string}> $routes */
    public static function build(array $routes, string $baseUrl): array
    {
        $cat = self::catalog();
        $scopes = [];
        foreach (DevScopes::all() as $name => $d) {
            $scopes[$name] = ($d['en'] ?? $name) . (!empty($d['sensitive']) ? ' (sensitive)' : '');
        }
        $base = rtrim($baseUrl, '/');
        $paths = [];

        foreach ($routes as $r) {
            $key = $r['method'] . ' ' . $r['path'];
            $e = $cat[$key] ?? ['id' => strtolower($r['method']) . preg_replace('/[^A-Za-z0-9]+/', '', ucwords($r['path'], '/-_{}')), 'tag' => 'Other', 'summary' => $key];
            $method = strtolower($r['method']);
            $isOauth = !empty($e['oauth']);
            $op = ['operationId' => $e['id'], 'tags' => [$e['tag']], 'summary' => $e['summary']];
            $desc = $e['description'] ?? '';
            if (!$isOauth) {
                $mode = $e['modes'] ?? 'both';
                $op['x-uipe-mode'] = $mode; // الـ API Explorer بيعرضها وبيحذّر لو المفتاح من mode مش مسموح
                if ($mode === 'live') {
                    $desc .= ($desc ? "\n\n" : '') . 'Live credentials only. With a sandbox key this returns `422 sandbox_unsupported`.';
                } elseif ($mode === 'sandbox') {
                    $desc .= ($desc ? "\n\n" : '') . 'Sandbox credentials only (`422 sandbox_only` otherwise).';
                }
                if ($r['scope']) {
                    $desc .= ($desc ? "\n\n" : '') . 'Required scope: `' . $r['scope'] . '`.';
                    $op['x-required-scope'] = $r['scope'];
                }
                $op['security'] = [['bearerAuth' => []], ['oauth2' => $r['scope'] ? [$r['scope']] : []]];
            }
            if ($desc !== '') {
                $op['description'] = $desc;
            }

            if ($isOauth) {
                $op = self::oauthOperation($op, $key);
                $paths[$r['path']][$method] = $op;
                continue;
            }

            $params = [];
            foreach ($e['path'] ?? [] as $p) {
                $params[] = $p;
            }
            foreach ($e['query'] ?? [] as $q) {
                $params[] = array_filter(['name' => $q['name'], 'in' => 'query', 'required' => false, 'description' => $q['description'] ?? null, 'schema' => $q['schema']], fn ($v) => $v !== null);
            }
            if ($params) {
                $op['parameters'] = $params;
            }
            if (isset($e['body'])) {
                $op['requestBody'] = ['required' => !empty($e['bodyRequired']), 'content' => ['application/json' => ['schema' => $e['body']]]];
            }
            $ok = (string) ($e['ok'] ?? 200);
            $data = array_key_exists('data', $e) ? $e['data'] : null;
            $op['responses'] = [
                $ok => ['description' => 'Success.', 'headers' => self::rateHeaders(), 'content' => ['application/json' => ['schema' => $data ? self::envelope($data) : self::nullData()]]],
                '401' => self::errorResponse('Unauthorized'), '403' => self::errorResponse('Forbidden'), '422' => self::errorResponse('Unprocessable'), '429' => self::errorResponse('RateLimited'),
            ];
            $paths[$r['path']][$method] = $op;
        }
        ksort($paths);

        $events = (array) config('developer.events', []);
        $webhooks = [];
        foreach ($events as $name => $d) {
            $webhooks[$name] = ['post' => [
                'operationId' => 'webhook_' . str_replace('.', '_', $name), 'tags' => ['Webhooks'], 'summary' => $name, 'description' => $d . '.' . "\n\n" . self::verifyText(),
                'x-codeSamples' => WebhookSamples::codeSamples(),
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => self::ref('WebhookEvent')]]],
                'responses' => ['2XX' => ['description' => 'Return any 2xx within 8 seconds to acknowledge. Anything else is retried with back-off, then the webhook is disabled after repeated failures.']],
            ]];
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'UIPE Developer API', 'version' => (string) config('developer.api_version', '1'),
                'description' => self::intro(),
            ],
            'servers' => [['url' => $base . '/api/dev/v1', 'description' => 'Developer API'], ['url' => $base . '/api', 'description' => 'OAuth endpoints (/oauth/token, /oauth/revoke)']],
            'tags' => array_map(fn ($t) => ['name' => $t], array_values(array_unique(array_merge(array_column($cat, 'tag'), ['Other'])))),
            'paths' => $paths,
            'webhooks' => $webhooks,
            'components' => [
                'securitySchemes' => [
                    'clientBasic' => ['type' => 'http', 'scheme' => 'basic', 'description' => 'The app\'s `client_id` and `client_secret` (confidential apps). Public apps send `client_id` in the body instead.'],
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key (`uipk_live_…` or `uipk_test_…`) or an OAuth access token (`uipo_…`). Test keys only ever touch the sandbox mailbox.'],
                    'oauth2' => ['type' => 'oauth2', 'description' => 'Authorization Code (PKCE S256 required for public apps) and Client Credentials (acts as the app owner).', 'flows' => [
                        'authorizationCode' => ['authorizationUrl' => $base . '/oauth/authorize', 'tokenUrl' => $base . '/api/oauth/token', 'refreshUrl' => $base . '/api/oauth/token', 'scopes' => $scopes],
                        'clientCredentials' => ['tokenUrl' => $base . '/api/oauth/token', 'scopes' => $scopes],
                    ]],
                ],
                'headers' => self::headers(),
                'responses' => self::responses(),
                'schemas' => self::schemas(),
            ],
        ];
    }

    private static function rateHeaders(): array
    {
        return [
            'X-RateLimit-Limit' => ['$ref' => '#/components/headers/RateLimitLimit'],
            'X-RateLimit-Remaining' => ['$ref' => '#/components/headers/RateLimitRemaining'],
            'X-UIPE-Mode' => ['$ref' => '#/components/headers/Mode'],
        ];
    }

    private static function errorResponse(string $name): array
    {
        return ['$ref' => '#/components/responses/' . $name];
    }

    private static function headers(): array
    {
        return [
            'RateLimitLimit' => ['schema' => self::int(), 'description' => 'Requests allowed per minute for this credential.'],
            'RateLimitRemaining' => ['schema' => self::int(), 'description' => 'Requests left in the current window.'],
            'Mode' => ['schema' => self::str(null, ['enum' => ['live', 'sandbox']]), 'description' => 'The mode of the credential that made the request.'],
            'RetryAfter' => ['schema' => self::int(), 'description' => 'Seconds until you may retry.'],
        ];
    }

    private static function responses(): array
    {
        $env = fn (string $d) => ['description' => $d, 'content' => ['application/json' => ['schema' => self::ref('ErrorEnvelope')]]];
        return [
            'Unauthorized' => $env('Missing, invalid, revoked or expired credential. `errors.code`: missing_credentials, invalid_token, invalid_key, key_expired, token_expired, account_inactive.'),
            'Forbidden' => $env('Not allowed. `errors.code`: insufficient_scope, ip_not_allowed, app_suspended, app_not_approved, sandbox_only, tenant_disabled, or a policy reason.'),
            'Unprocessable' => $env('Validation failed, or the endpoint is not available for this credential mode (`sandbox_unsupported`, `sandbox_only`).'),
            'RateLimited' => ['description' => 'Rate limit exceeded (`errors.code` = rate_limited). Wait `Retry-After` seconds.',
                'headers' => ['Retry-After' => ['$ref' => '#/components/headers/RetryAfter'], 'X-RateLimit-Limit' => ['$ref' => '#/components/headers/RateLimitLimit'], 'X-RateLimit-Remaining' => ['$ref' => '#/components/headers/RateLimitRemaining']],
                'content' => ['application/json' => ['schema' => self::ref('ErrorEnvelope')]]],
        ];
    }

    private static function intro(): string
    {
        return "Build on top of a UIPE mailbox with API keys or OAuth.\n\n"
            . "**Responses** use one envelope: `{ success, message, data, errors, meta }`. On errors `data` is null and `errors.code` is a stable machine-readable code.\n\n"
            . "**Sandbox**: a `uipk_test_…` key works on a separate fake mailbox. No real mail is read or sent, so you can build and test freely.\n\n"
            . "**Rate limit**: " . (int) config('developer.limits.rate_per_min', 300) . " requests per minute per key/token by default (your university or app may set another number). Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; on `429` wait `Retry-After` seconds.\n\n"
            . "**Apps**: an app that asks for sensitive scopes may need your university's approval before other users can authorize it (App Review).";
    }

    private static function verifyText(): string
    {
        $tol = WebhookSignature::tolerance();
        return "**Verifying a delivery.** Each request has `X-UIPE-Signature: t=<unix seconds>,v1=<hex>`, where `v1 = HMAC-SHA256(secret, t + \".\" + rawBody)` as lowercase hex. "
            . "`t` is set when the request is sent, so every retry carries a fresh timestamp and signature. "
            . "Recompute it over the **raw** body with your webhook secret, compare in constant time, and reject the delivery when `|now - t|` is more than {$tol} seconds, in either direction (replay protection). "
            . "A valid signature can still arrive twice (retries, manual redelivery): keep `X-UIPE-Event-Id` for at least " . ($tol * 2) . " seconds and ignore ids you already processed. "
            . "Ready-made `verifySignature` code for PHP, Node.js, Python and Go is in `x-codeSamples` below and at `GET /api/dev/v1/docs/webhook-verification` (with a test vector for your unit tests). "
            . "Other headers: `X-UIPE-Event`, `X-UIPE-Event-Id` (stable across retries), `X-UIPE-Delivery`.";
    }

    private static function oauthOperation(array $op, string $key): array
    {
        $err = ['description' => 'OAuth error (RFC 6749).', 'content' => ['application/json' => ['schema' => self::ref('OAuthError')]]];
        if ($key === 'POST /oauth/token') {
            $op['description'] = "Authenticate the app with HTTP Basic (`client_id:client_secret`) or `client_id`/`client_secret` in the body. Public apps send only `client_id` and must use PKCE.\n\n"
                . "- `authorization_code`: `code`, `redirect_uri`, `code_verifier` (PKCE).\n- `refresh_token`: `refresh_token`. Refresh tokens rotate: every refresh returns a new one, and re-using an old one revokes the whole grant.\n- `client_credentials`: acts as the app owner, no refresh token.";
            $op['security'] = [['clientBasic' => []], (object) []]; // {} = تطبيق public من غير secret
            $op['requestBody'] = ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => self::obj([
                'grant_type' => self::str(null, ['enum' => ['authorization_code', 'refresh_token', 'client_credentials']]), 'client_id' => self::str(), 'client_secret' => self::str(),
                'code' => self::str(), 'redirect_uri' => self::str(), 'code_verifier' => self::str(), 'refresh_token' => self::str(), 'scope' => self::str('Space-separated; client_credentials only.'),
            ], ['grant_type'])]]];
            $op['responses'] = ['200' => ['description' => 'Token issued.', 'content' => ['application/json' => ['schema' => self::ref('TokenResponse')]]], '400' => $err, '401' => $err];
        } else {
            $op['description'] = 'RFC 7009. Always answers 200, even when the token is unknown. Revoking a refresh token also stops the access token that came with it.';
            $op['security'] = [['clientBasic' => []], (object) []]; // {} = تطبيق public من غير secret
            $op['requestBody'] = ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => self::obj(['token' => self::str(), 'client_id' => self::str(), 'client_secret' => self::str()], ['token'])]]];
            $op['responses'] = ['200' => ['description' => 'Done.', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]], '401' => $err];
        }
        return $op;
    }

    private static function schemas(): array
    {
        $addr = self::obj(['address' => self::str(), 'name' => ['type' => ['string', 'null']]], ['address']);
        return [
            'Envelope' => self::obj([
                'success' => self::bool(), 'message' => self::str(), 'data' => new \stdClass(), 'errors' => ['type' => 'null'], 'meta' => ['type' => 'object', 'additionalProperties' => true],
            ], ['success', 'data']),
            'ErrorEnvelope' => self::obj([
                'success' => ['const' => false], 'message' => self::str('Human-readable, English.'), 'data' => ['type' => 'null'],
                'errors' => self::obj(['code' => self::str('Stable machine-readable code.', ['enum' => ['missing_credentials', 'invalid_token', 'invalid_key', 'key_expired', 'token_expired', 'account_inactive', 'insufficient_scope', 'ip_not_allowed', 'app_suspended', 'app_not_approved', 'sandbox_only', 'sandbox_unsupported', 'tenant_required', 'origin_not_allowed', 'rate_limited', 'disabled'] ])], [], true),
                'meta' => ['type' => 'object'],
            ], ['success', 'message']),
            'OAuthError' => self::obj(['error' => self::str(null, ['enum' => ['invalid_request', 'invalid_client', 'invalid_grant', 'invalid_scope', 'unsupported_grant_type', 'temporarily_unavailable']]), 'error_description' => self::str()], ['error']),
            'TokenResponse' => self::obj(['access_token' => self::str(), 'token_type' => self::str(null, ['enum' => ['Bearer']]), 'expires_in' => self::int('Seconds.'), 'scope' => self::str('Space-separated.'),
                'refresh_token' => self::str('Not returned for client_credentials.')], ['access_token', 'token_type', 'expires_in', 'scope']),
            'Scope' => self::obj(['scope' => self::str(), 'sensitive' => self::bool(), 'en' => self::str(), 'ar' => self::str()], ['scope', 'sensitive']),
            'Me' => self::obj([
                'user' => self::obj(['id' => self::int(), 'name' => self::str()], ['id', 'name']), 'mode' => self::str(null, ['enum' => ['live', 'sandbox']]), 'kind' => self::str('`key` or `oauth`.'),
                'scopes' => self::arr(self::str()), 'api_version' => self::str(), 'rate_limit' => self::obj(['limit' => self::int(), 'remaining' => self::int()]),
                'mailbox' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The linked mailbox (id, address, kind, display_name, status …). Sandbox: a fake address and folder counts.'],
            ], ['user', 'mode', 'scopes']),
            'Address' => $addr,
            'FolderCount' => self::obj(['total' => self::int(), 'unread' => self::int()], ['total', 'unread']),
            'Message' => self::obj([
                'id' => self::int(), 'message_id' => self::int(), 'thread_id' => self::int(), 'folder' => self::str(), 'subject' => self::str(), 'snippet' => self::str(),
                'is_read' => self::bool(), 'is_starred' => self::bool(), 'is_draft' => self::bool(), 'date' => self::str(null, ['format' => 'date-time']),
                'from' => self::ref('Address'), 'to' => self::arr(self::ref('Address')), 'has_attachments' => self::bool(), 'labels' => self::arr(['type' => 'object', 'additionalProperties' => true]),
                'sandbox' => self::bool('Present (true) on sandbox messages.'),
                'body_text' => self::str('Only when fetching a single message.'), 'body_html' => ['type' => ['string', 'null'], 'description' => 'Only when fetching a single message.'],
                'cc' => self::arr(self::ref('Address')), 'bcc' => self::arr(self::ref('Address')), 'attachments' => self::arr(['type' => 'object', 'additionalProperties' => true]),
            ], ['id', 'folder', 'subject', 'is_read'], true),
            'MessagePage' => self::obj(['items' => self::arr(self::ref('Message')), 'meta' => self::obj(['total' => self::int(), 'page' => self::int(), 'per_page' => self::int(), 'pages' => self::int()])], ['items'], true),
            'Label' => self::obj(['id' => self::int(), 'name' => self::str(), 'color' => ['type' => ['string', 'null']]], ['id', 'name'], true),
            'Webhook' => self::obj([
                'id' => self::int(), 'name' => ['type' => ['string', 'null']], 'url' => self::str(), 'events' => self::arr(self::str()), 'mode' => self::str(null, ['enum' => ['live', 'sandbox']]),
                'app_id' => ['type' => ['integer', 'null']], 'mail_account_id' => ['type' => ['integer', 'null']], 'is_active' => self::bool(), 'failure_count' => self::int(),
                'last_status' => ['type' => ['string', 'null']], 'last_delivery_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'disabled_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'disabled_reason' => ['type' => ['string', 'null']], 'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ], ['id', 'url', 'events', 'mode', 'is_active']),
            'Participant' => self::obj([
                'external_id' => self::str(), 'name' => self::str(), 'email' => ['type' => ['string', 'null']], 'role' => self::str(null, ['enum' => array_values((array) config('tenancy.participant_roles'))]),
                'meta' => ['type' => ['object', 'null'], 'additionalProperties' => true], 'last_seen_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ], ['external_id', 'name', 'role']),
            'ParticipantPage' => self::obj(['items' => self::arr(self::ref('Participant')), 'meta' => self::obj(['total' => self::int(), 'page' => self::int(), 'per_page' => self::int(), 'pages' => self::int()])], ['items']),
            'EmbedSession' => self::obj([
                'token' => self::str('Single-use join token (`ejt_…`). Shown once.'), 'expires_at' => self::str(null, ['format' => 'date-time']),
                'embed_url' => self::str('Open this in an iframe (or let the loader do it). Uses the workspace primary domain when it has one.'),
                'participant' => self::ref('Participant'), 'room' => ['type' => ['string', 'null']], 'origin' => ['type' => ['string', 'null']],
            ], ['token', 'expires_at', 'embed_url', 'participant']),
            'WebhookWithSecret' => ['allOf' => [self::ref('Webhook'), self::obj(['secret' => self::str('Signing secret. Shown once — store it now.')], ['secret'])]],
            'WebhookEvent' => self::obj([
                'id' => self::str('Event id, same as `X-UIPE-Event-Id`. Stable across retries.'), 'type' => self::str(null, ['enum' => array_keys((array) config('developer.events', []))]),
                'api_version' => self::str(), 'created_at' => self::str(null, ['format' => 'date-time']), 'mode' => self::str(null, ['enum' => ['live', 'sandbox']]),
                'data' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Event details: ids and a short summary, never full message bodies. `mail.message.*`: item_id, message_id, thread_id, subject, from/to, snippet. `app.authorized`: app_id, scopes. `app.revoked`: app_id, grant_id.'],
            ], ['id', 'type', 'api_version', 'created_at', 'mode', 'data']),
        ];
    }
}
