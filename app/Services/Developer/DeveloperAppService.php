<?php

namespace App\Services\Developer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Developer Center: تطبيقات OAuth + API Keys + الموافقات (Grants) + سياسة الجامعة.
 * الأسرار (client_secret / API key) بتتعرض مرة واحدة وقت الإنشاء وبتتخزن sha256 بس.
 * أي خطأ استخدام بيترمي \DomainException(msg, http).
 */
class DeveloperAppService
{
    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ users & policy */

    public function userActive(int $userId): bool
    {
        $u = DB::table('users')->where('id', $userId)->first(['status', 'deleted_at']);
        return $u && $u->deleted_at === null && !in_array($u->status, ['suspended', 'banned'], true);
    }

    public function __construct(private DevAccessService $access)
    {
    }

    public function universityOf(int $userId): ?int
    {
        return $this->access->universityOf($userId);
    }

    /** سياسة جامعة واحدة (أو المنصة لو null/0) زي ما اتحفظت — للإدارة. */
    public function policyForUniversity(?int $universityId): array
    {
        return $this->access->rawPolicy((int) $universityId);
    }

    /**
     * السياسة الفعلية لمستخدم (المنصة + الجامعة + القواعد + استثناء المطوّر) بنفس الشكل القديم + حقول جديدة.
     * apps_enabled هنا = "المستخدم ده مسموح له يستخدم أدوات المطورين دلوقتي"، فكل الأماكن القديمة اللي بتفحصه اتحمت أوتوماتيك.
     */
    public function policyFor(int $userId): array
    {
        $e = $this->access->effective($userId);
        return [
            'university_id' => $e['university_id'], 'apps_enabled' => $e['allowed'], 'webhooks_enabled' => $e['allowed'] && $e['webhooks_enabled'],
            'max_apps_per_user' => $e['limits']['apps'], 'max_keys_per_user' => $e['limits']['keys'], 'max_webhooks_per_user' => $e['limits']['webhooks'],
            'rate_limit_per_min' => $e['limits']['rate_per_min'], 'allowed_scopes' => $e['scopes'], 'allowed_events' => $e['events'], 'sandbox_only' => $e['sandbox_only'],
            'access' => $e,
        ];
    }

    public function savePolicy(int $universityId, array $in, ?int $by = null): array
    {
        return $this->access->savePolicy($universityId, $in, $by);
    }

    /** scopes اللي مسموح للمستخدم يطلبها. */
    private function allowedScopesFor(int $userId): array
    {
        return $this->policyFor($userId)['allowed_scopes'];
    }

    private function assertDevAllowed(int $userId): array
    {
        $p = $this->policyFor($userId);
        if (!$p['apps_enabled']) {
            $a = $p['access'];
            $this->fail($a['message'] ?? 'Developer access is not available for your account.', $a['reason'] === 'disabled' ? 503 : 403);
        }
        return $p;
    }

    private function cleanScopes(int $userId, $in, bool $required = true): array
    {
        $scopes = DevScopes::parse($in);
        if ($required && !$scopes) {
            $this->fail('Choose at least one scope.');
        }
        if ($bad = DevScopes::unknown($scopes)) {
            $this->fail('Unknown scope: ' . implode(', ', $bad));
        }
        $denied = array_values(array_diff($scopes, $this->allowedScopesFor($userId)));
        if ($denied) {
            $this->fail('Your account is not allowed these scopes: ' . implode(', ', $denied), 403);
        }
        return $scopes;
    }

    /* ------------------------------------------------------------------ apps */

    private function cleanRedirect(string $u): string
    {
        $u = trim($u);
        if ($u === '' || strlen($u) > 400 || preg_match('/[\s\x00-\x1f]/', $u)) {
            $this->fail('Invalid redirect URI.');
        }
        $p = parse_url($u);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            $this->fail('Invalid redirect URI (no fragments or credentials allowed).');
        }
        if (str_contains($u, '*')) {
            $this->fail('Wildcards are not allowed in redirect URIs.');
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local && config('developer.oauth.allow_http_localhost'))) {
            $this->fail('Redirect URIs must use https (http is allowed for localhost only).');
        }
        return $u;
    }

    private function cleanRedirects($in): array
    {
        $list = array_values(array_unique(array_map(fn ($u) => $this->cleanRedirect((string) $u), array_filter((array) $in, fn ($u) => trim((string) $u) !== ''))));
        if (count($list) > (int) config('developer.limits.redirect_uris', 10)) {
            $this->fail('Too many redirect URIs.');
        }
        return $list;
    }

    private function cleanHomepage($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (strlen($v) > 300 || !preg_match('#^https?://[^\s]+$#i', $v) || !filter_var($v, FILTER_VALIDATE_URL)) {
            $this->fail('Invalid homepage URL.');
        }
        return $v;
    }

    public function appPayload(object $a, ?string $secret = null): array
    {
        $users = (int) DB::table('dev_oauth_grants')->where('app_id', $a->id)->whereNull('revoked_at')->count();
        $out = [
            'id' => (int) $a->id, 'name' => $a->name, 'description' => $a->description, 'homepage_url' => $a->homepage_url,
            'client_id' => $a->client_id, 'client_type' => $a->client_type, 'client_secret_hint' => $a->client_secret_hint,
            'redirect_uris' => (array) json_decode((string) $a->redirect_uris, true), 'scopes' => (array) json_decode((string) $a->scopes, true),
            'status' => $a->status, 'suspended_reason' => $a->suspended_reason, 'suspended_by' => $a->suspended_by,
            'rate_limit_per_min' => $a->rate_limit_per_min !== null ? (int) $a->rate_limit_per_min : null,
            'authorized_users' => $users, 'last_used_at' => DevTokens::iso($a->last_used_at), 'created_at' => DevTokens::iso($a->created_at),
            'owner_user_id' => (int) $a->owner_user_id, 'university_id' => $a->university_id ? (int) $a->university_id : null,
            'review' => $this->reviewInfo($a),
        ];
        if ($secret !== null) {
            $out['client_secret'] = $secret; // بيظهر مرة واحدة بس
        }
        return $out;
    }

    public function listApps(int $userId): array
    {
        return DB::table('dev_apps')->where('owner_user_id', $userId)->orderByDesc('id')->get()->map(fn ($a) => $this->appPayload($a))->values()->all();
    }

    public function ownApp(int $userId, int $id): object
    {
        $a = DB::table('dev_apps')->where('id', $id)->where('owner_user_id', $userId)->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        return $a;
    }

    public function getApp(int $userId, int $id): array
    {
        return $this->appPayload($this->ownApp($userId, $id));
    }

    /** @return array{app:array} + client_secret مرة واحدة للـ confidential */
    public function createApp(int $userId, array $in): array
    {
        $policy = $this->assertDevAllowed($userId);
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            $this->fail('App name must be 3 to 80 characters.');
        }
        $type = (string) ($in['client_type'] ?? 'confidential');
        if (!in_array($type, ['confidential', 'public'], true)) {
            $this->fail('Invalid client type.');
        }
        $max = (int) $policy['max_apps_per_user'];
        if (DB::table('dev_apps')->where('owner_user_id', $userId)->count() >= $max) {
            $this->fail('You reached the maximum number of apps.', 403);
        }
        $scopes = $this->cleanScopes($userId, $in['scopes'] ?? ['profile.read']);
        $redirects = $this->cleanRedirects($in['redirect_uris'] ?? []);
        $secret = $type === 'confidential' ? DevTokens::make('uips_', 40) : null;
        $id = DB::table('dev_apps')->insertGetId([
            'owner_user_id' => $userId, 'university_id' => $policy['university_id'], 'name' => $name,
            'description' => isset($in['description']) ? Str::limit(trim((string) $in['description']), 500, '') : null,
            'homepage_url' => $this->cleanHomepage($in['homepage_url'] ?? null), 'client_id' => 'uipc_' . Str::lower(Str::random(24)),
            'client_secret_hash' => $secret ? DevTokens::hash($secret) : null, 'client_secret_hint' => $secret ? substr($secret, 0, 9) : null,
            'client_type' => $type, 'redirect_uris' => json_encode($redirects), 'scopes' => json_encode($scopes), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['app' => $this->appPayload(DB::table('dev_apps')->find($id), $secret)];
    }

    public function updateApp(int $userId, int $id, array $in): array
    {
        $this->assertDevAllowed($userId);
        $a = $this->ownApp($userId, $id);
        $upd = ['updated_at' => now()];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
                $this->fail('App name must be 3 to 80 characters.');
            }
            $upd['name'] = $name;
        }
        if (array_key_exists('description', $in)) {
            $upd['description'] = $in['description'] !== null ? Str::limit(trim((string) $in['description']), 500, '') : null;
        }
        if (array_key_exists('homepage_url', $in)) {
            $upd['homepage_url'] = $this->cleanHomepage($in['homepage_url']);
        }
        if (array_key_exists('redirect_uris', $in)) {
            $upd['redirect_uris'] = json_encode($this->cleanRedirects($in['redirect_uris']));
        }
        if (array_key_exists('scopes', $in)) {
            $upd['scopes'] = json_encode($this->cleanScopes($userId, $in['scopes']));
        }
        DB::table('dev_apps')->where('id', $a->id)->update($upd);
        $this->reviewAfterChange($a, $userId, 'owner');
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function rotateSecret(int $userId, int $id): array
    {
        $this->assertDevAllowed($userId);
        $a = $this->ownApp($userId, $id);
        if ($a->client_type !== 'confidential') {
            $this->fail('Public apps have no client secret.');
        }
        $secret = DevTokens::make('uips_', 40);
        DB::table('dev_apps')->where('id', $a->id)->update(['client_secret_hash' => DevTokens::hash($secret), 'client_secret_hint' => substr($secret, 0, 9), 'updated_at' => now()]);
        return $this->appPayload(DB::table('dev_apps')->find($a->id), $secret);
    }

    /** صاحب التطبيق يوقفه/يشغّله. لو الجامعة/الأدمن هما اللي أوقفوه، صاحبه ميقدرش يشغّله. */
    public function setOwnerStatus(int $userId, int $id, bool $active): array
    {
        $a = $this->ownApp($userId, $id);
        if ($active) {
            if ($a->status === 'suspended' && $a->suspended_by && $a->suspended_by !== 'owner') {
                $this->fail('This app was suspended by your university or the platform.', 403);
            }
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'active', 'suspended_reason' => null, 'suspended_by' => null, 'updated_at' => now()]);
        } else {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'suspended', 'suspended_by' => 'owner', 'suspended_reason' => 'Paused by the owner', 'updated_at' => now()]);
        }
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function deleteApp(int $userId, int $id): void
    {
        $a = $this->ownApp($userId, $id);
        DB::transaction(function () use ($a) {
            DB::table('dev_oauth_tokens')->where('app_id', $a->id)->delete();
            DB::table('dev_oauth_codes')->where('app_id', $a->id)->delete();
            DB::table('dev_oauth_grants')->where('app_id', $a->id)->delete();
            DB::table('dev_api_keys')->where('app_id', $a->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $hooks = DB::table('dev_webhooks')->where('app_id', $a->id)->pluck('id')->all();
            if ($hooks) {
                DB::table('dev_webhook_deliveries')->whereIn('webhook_id', $hooks)->delete();
                DB::table('dev_webhooks')->whereIn('id', $hooks)->delete();
            }
            DB::table('dev_apps')->where('id', $a->id)->delete();
        });
    }

    /* ------------------------------------------------------------------ App Review */

    private function appScopes(object $a): array
    {
        return array_values((array) json_decode((string) ($a->scopes ?? '[]'), true));
    }

    private function appRedirects(object $a): array
    {
        return array_values((array) json_decode((string) ($a->redirect_uris ?? '[]'), true));
    }

    private function reviewInput(object $a): array
    {
        $approved = $a->review_scopes ?? null;
        return ['review_status' => $a->review_status ?? 'none', 'scopes' => $this->appScopes($a), 'redirects' => $this->appRedirects($a),
            'review_scopes' => $approved !== null ? (array) json_decode((string) $approved, true) : null, 'review_redirects_hash' => $a->review_redirects_hash ?? null];
    }

    /** الوضع الفعلي لجامعة التطبيق (الأشد بين المنصة والجامعة). */
    public function reviewMode(object $a): string
    {
        return $this->access->reviewModeFor(!empty($a->university_id) ? (int) $a->university_id : null);
    }

    /** @return array{state:string,required:bool,allows:bool,covered:bool} */
    public function reviewEvaluate(object $a): array
    {
        return DevAppReview::evaluate($this->reviewInput($a), $this->reviewMode($a));
    }

    public function reviewState(object $a): string
    {
        return $this->reviewEvaluate($a)['state'];
    }

    /** مستخدم غير صاحب التطبيق يقدر يوافق عليه / يستخدم توكناته؟ (صاحبه دايمًا يقدر يجرّبه). */
    public function reviewAllows(object $a): bool
    {
        return $this->reviewEvaluate($a)['allows'];
    }

    public function reviewInfo(object $a): array
    {
        $ev = $this->reviewEvaluate($a);
        $scopes = $this->appScopes($a);
        $approved = ($a->review_scopes ?? null) !== null ? (array) json_decode((string) $a->review_scopes, true) : null;
        return [
            'state' => $ev['state'], 'required' => $ev['required'], 'mode' => $this->reviewMode($a), 'status' => $a->review_status ?? 'none',
            'verified' => $ev['state'] === 'approved', 'can_submit' => in_array($ev['state'], ['needs_review', 'rejected'], true),
            'sensitive_scopes' => DevAppReview::sensitiveScopes($scopes), 'scopes_described' => DevScopes::describe($scopes),
            'approved_scopes' => $approved, 'message' => $a->review_message ?? null, 'note' => $a->review_note ?? null,
            'requested_at' => DevTokens::iso($a->review_requested_at ?? null), 'reviewed_at' => DevTokens::iso($a->reviewed_at ?? null), 'reviewed_by_role' => $a->reviewed_by_role ?? null,
            'fingerprint' => DevAppReview::fingerprint($scopes, $this->appRedirects($a)),
        ];
    }

    private function reviewEvent(int $appId, ?int $actorId, string $role, string $action, ?string $note, ?array $scopes = null): void
    {
        DB::table('dev_app_review_events')->insert(['app_id' => $appId, 'actor_user_id' => $actorId, 'actor_role' => $role, 'action' => $action,
            'note' => $note !== null ? Str::limit($note, 500, '') : null, 'scopes' => $scopes === null ? null : json_encode(array_values($scopes)), 'created_at' => now()]);
    }

    /** سجل المراجعة لتطبيق. $withActors = الإدارة بس (بيظهر مين اتخذ القرار). */
    public function reviewHistory(int $appId, bool $withActors = false): array
    {
        return DB::table('dev_app_review_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')->where('e.app_id', $appId)->orderByDesc('e.id')->limit(50)
            ->get(['e.id', 'e.action', 'e.actor_role', 'e.actor_user_id', 'e.note', 'e.scopes', 'e.created_at', 'u.full_name as actor_name'])
            ->map(function ($e) use ($withActors) {
                $row = ['id' => (int) $e->id, 'action' => $e->action, 'actor_role' => $e->actor_role, 'note' => $e->note, 'scopes' => $e->scopes ? (array) json_decode((string) $e->scopes, true) : null,
                    'created_at' => DevTokens::iso($e->created_at)];
                return $withActors ? $row + ['actor_user_id' => $e->actor_user_id ? (int) $e->actor_user_id : null, 'actor_name' => $e->actor_name] : $row;
            })->values()->all();
    }

    public function ownerReview(int $userId, int $id): array
    {
        $a = $this->ownApp($userId, $id);
        return ['review' => $this->reviewInfo($a), 'history' => $this->reviewHistory((int) $a->id)];
    }

    private function reviewNotify(?int $userId, string $title, string $body): void
    {
        if (!$userId) {
            return;
        }
        try {
            app(\App\Services\NotificationService::class)->notify($userId, 'developer_app_review', $title, $body, null, 'normal');
        } catch (\Throwable $e) {
            Log::warning('App review notice failed', ['error' => $e->getMessage()]);
        }
    }

    /** لو الموافقة القديمة مبقتش تغطي التطبيق بعد تعديل (scope جديد / redirect مختلف) → الحالة ترجع none وتتسجّل. */
    private function reviewAfterChange(object $before, ?int $actorId, string $role): void
    {
        if (($before->review_status ?? 'none') !== 'approved') {
            return;
        }
        $a = DB::table('dev_apps')->where('id', $before->id)->first();
        if (!$a) {
            return;
        }
        $in = $this->reviewInput($a);
        if (DevAppReview::covers($in['review_scopes'], $in['review_redirects_hash'], $in['scopes'], $in['redirects'])) {
            return;
        }
        $n = DB::table('dev_apps')->where('id', $a->id)->where('review_status', 'approved')->update(['review_status' => 'none', 'review_note' => 'Scopes or redirect URIs changed after approval.', 'updated_at' => now()]);
        if ($n) {
            $this->reviewEvent((int) $a->id, $actorId, $role, 'invalidated', 'Scopes or redirect URIs changed after approval.', $in['scopes']);
        }
    }

    /** المطوّر يبعت التطبيق للمراجعة. */
    public function submitForReview(int $userId, int $id, string $message): array
    {
        $this->assertDevAllowed($userId);
        $a = $this->ownApp($userId, $id);
        $message = trim($message);
        if (mb_strlen($message) < 10 || mb_strlen($message) > 500) {
            $this->fail('Explain what the app does and why it needs these permissions (10 to 500 characters).');
        }
        $ev = $this->reviewEvaluate($a);
        if ($ev['state'] === 'pending') {
            $this->fail('This app is already waiting for review.', 409);
        }
        if ($ev['state'] === 'approved') {
            $this->fail('This app is already approved.', 409);
        }
        if (!$ev['required']) {
            $this->fail('This app does not need review.', 422);
        }
        $n = DB::table('dev_apps')->where('id', $a->id)->where('review_status', '!=', 'pending')->update(['review_status' => 'pending', 'review_message' => $message, 'review_note' => null,
            'review_requested_at' => now(), 'reviewed_at' => null, 'reviewed_by' => null, 'reviewed_by_role' => null, 'updated_at' => now()]);
        if (!$n) {
            $this->fail('This app is already waiting for review.', 409);
        }
        $this->reviewEvent((int) $a->id, $userId, 'owner', 'submitted', $message, $this->appScopes($a));
        if ($a->university_id) {
            $this->reviewNotify((int) DB::table('universities')->where('id', $a->university_id)->value('user_id'), 'تطبيق جديد مستني المراجعة',
                'التطبيق "' . $a->name . '" اتبعت لمراجعة الجامعة وبيطلب صلاحيات: ' . implode(', ', $this->appScopes($a)) . '.');
        }
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    /** المطوّر يسحب طلب المراجعة (قبل القرار). */
    public function withdrawReview(int $userId, int $id): array
    {
        $a = $this->ownApp($userId, $id);
        $n = DB::table('dev_apps')->where('id', $a->id)->where('review_status', 'pending')->update(['review_status' => 'none', 'review_requested_at' => null, 'updated_at' => now()]);
        if (!$n) {
            $this->fail('There is no pending review request to withdraw.', 409);
        }
        $this->reviewEvent((int) $a->id, $userId, 'owner', 'withdrawn', null, $this->appScopes($a));
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    /**
     * قرار الإدارة. الموافقة لازم تتبعت معاها $fingerprint (من تفاصيل التطبيق) — لو المطوّر عدّل scopes/redirects بعد ما المراجع فتح الصفحة، القرار بيترفض 409.
     * المراجع ميقدرش يراجع تطبيقه هو. $universityId = null → أدمن المنصة (أي جامعة).
     */
    public function adminReviewDecision(int $appId, ?int $universityId, bool $approve, ?string $note, ?string $fingerprint, int $actorId, string $actorRole): array
    {
        $note = $note !== null ? trim($note) : null;
        if (!$approve && ($note === null || mb_strlen($note) < 3)) {
            $this->fail('Give the developer a reason for the rejection.');
        }
        if ($approve && ($fingerprint === null || $fingerprint === '')) {
            $this->fail('The review fingerprint is required to approve.');
        }
        $role = $actorRole === 'admin' ? 'admin' : 'university';
        $app = $this->adminApp($appId, $universityId);
        if ((int) $app->owner_user_id === $actorId) {
            $this->fail('You cannot review your own app.', 403);
        }
        DB::transaction(function () use ($app, $approve, $note, $fingerprint, $actorId, $role) {
            $a = DB::table('dev_apps')->where('id', $app->id)->lockForUpdate()->first();
            if (!$a || ($a->review_status ?? 'none') !== 'pending') {
                $this->fail('This app is not waiting for review.', 409);
            }
            $scopes = $this->appScopes($a);
            $redirects = $this->appRedirects($a);
            if ($approve && !hash_equals(DevAppReview::fingerprint($scopes, $redirects), (string) $fingerprint)) {
                $this->fail('The app changed after you opened it. Reload it and review the new permissions.', 409);
            }
            $upd = ['review_status' => $approve ? 'approved' : 'rejected', 'review_note' => $note !== null ? Str::limit($note, 500, '') : null,
                'reviewed_at' => now(), 'reviewed_by' => $actorId, 'reviewed_by_role' => $role, 'updated_at' => now()];
            if ($approve) {
                $upd += ['review_scopes' => json_encode($scopes), 'review_redirects_hash' => DevAppReview::redirectsHash($redirects)];
            }
            DB::table('dev_apps')->where('id', $a->id)->update($upd);
            $this->reviewEvent((int) $a->id, $actorId, $role, $approve ? 'approved' : 'rejected', $note, $scopes);
        });
        $this->reviewNotify((int) $app->owner_user_id, $approve ? 'تمت الموافقة على تطبيقك' : 'تم رفض مراجعة تطبيقك',
            $approve ? 'التطبيق "' . $app->name . '" اتوافق عليه، ودلوقتي مستخدمين تانيين يقدروا يربطوه بحساباتهم.'
                : 'التطبيق "' . $app->name . '" اترفض.' . ($note ? ' السبب: ' . $note : ''));
        return $this->appPayload(DB::table('dev_apps')->find($app->id));
    }

    /**
     * "لو الوضع بقى X يتأثر إيه؟" — بيحسب من غير ما يغيّر حاجة. $level = جامعة، أو 0 = المنصة. $mode = الوضع المقترح (null = ورّث).
     * @return array{mode:?string,apps_total:int,apps_blocked:int,apps_blocked_with_users:int,users_affected:int}
     */
    public function reviewImpact(int $level, ?string $mode): array
    {
        if ($mode !== null && !in_array($mode, DevAppReview::MODES, true)) {
            $this->fail('review_mode must be off, sensitive or all.');
        }
        $modes = DB::table('dev_tenant_policies')->pluck('review_mode', 'university_id')->all();
        $modes[$level] = $mode; // الوضع المقترح بدل الحالي
        $platform = $modes[DevAccessService::PLATFORM] ?? null;
        $out = ['mode' => $mode, 'apps_total' => 0, 'apps_blocked' => 0, 'apps_blocked_with_users' => 0, 'users_affected' => 0];
        DB::table('dev_apps')->when($level, fn ($q) => $q->where('university_id', $level))->orderBy('id')->chunkById(500, function ($rows) use (&$out, $modes, $platform) {
            foreach ($rows as $a) {
                $out['apps_total']++;
                $m = DevAppReview::mode($platform, $a->university_id ? ($modes[(int) $a->university_id] ?? null) : null);
                if (DevAppReview::evaluate($this->reviewInput($a), $m)['allows']) {
                    continue;
                }
                $out['apps_blocked']++;
                $users = (int) DB::table('dev_oauth_grants')->where('app_id', $a->id)->whereNull('revoked_at')->count();
                if ($users > 0) {
                    $out['apps_blocked_with_users']++;
                    $out['users_affected'] += $users;
                }
            }
        });
        return $out;
    }

    /* ------------------------------------------------------------------ API keys */

    public function keyPayload(object $k, ?string $raw = null): array
    {
        $out = [
            'id' => (int) $k->id, 'name' => $k->name, 'mode' => $k->mode, 'prefix' => $k->prefix, 'scopes' => (array) json_decode((string) $k->scopes, true),
            'app_id' => $k->app_id ? (int) $k->app_id : null, 'mail_account_id' => $k->mail_account_id ? (int) $k->mail_account_id : null,
            'ip_allowlist' => (array) json_decode((string) ($k->ip_allowlist ?? '[]'), true), 'expires_at' => DevTokens::iso($k->expires_at),
            'last_used_at' => DevTokens::iso($k->last_used_at), 'last_used_ip' => $k->last_used_ip, 'revoked_at' => DevTokens::iso($k->revoked_at),
            'created_at' => DevTokens::iso($k->created_at),
            'rotated_from_id' => ($k->rotated_from_id ?? null) ? (int) $k->rotated_from_id : null, 'rotated_at' => DevTokens::iso($k->rotated_at ?? null),
            'status' => $this->keyStatus($k),
        ];
        if ($raw !== null) {
            $out['key'] = $raw; // بيظهر مرة واحدة بس
        }
        return $out;
    }

    /** active | rotating (اتبدّل وفي فترة السماح) | expired | revoked */
    private function keyStatus(object $k): string
    {
        if ($k->revoked_at !== null) {
            return 'revoked';
        }
        if ($k->expires_at && now()->gte(Carbon::parse($k->expires_at, 'UTC'))) {
            return 'expired';
        }
        return ($k->rotated_at ?? null) ? 'rotating' : 'active';
    }

    public function listKeys(int $userId): array
    {
        return DB::table('dev_api_keys')->where('user_id', $userId)->orderByDesc('id')->get()->map(fn ($k) => $this->keyPayload($k))->values()->all();
    }

    private function cleanIpList($in): array
    {
        $out = [];
        foreach (array_filter((array) $in, fn ($v) => trim((string) $v) !== '') as $v) {
            $v = trim((string) $v);
            [$ip, $bits] = array_pad(explode('/', $v, 2), 2, null);
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || ($bits !== null && (!ctype_digit($bits) || (int) $bits > ($isV6 ? 128 : 32)))) {
                $this->fail('Invalid IP address: ' . $v);
            }
            $out[] = $v;
        }
        if (count($out) > 20) {
            $this->fail('Too many IP addresses (max 20).');
        }
        return array_values(array_unique($out));
    }

    public function createKey(int $userId, array $in): array
    {
        $policy = $this->assertDevAllowed($userId);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $this->fail('Give the key a name (up to 80 characters).');
        }
        $mode = (string) ($in['mode'] ?? ($policy['sandbox_only'] ? 'sandbox' : 'live'));
        if (!in_array($mode, ['live', 'sandbox'], true)) {
            $this->fail('Invalid mode.');
        }
        if ($mode === 'live' && $policy['sandbox_only']) {
            $this->fail('Your account can only create sandbox (test) keys.', 403);
        }
        $this->revokeRotatedExpired($userId);
        if (DB::table('dev_api_keys')->where('user_id', $userId)->whereNull('revoked_at')->count() >= (int) $policy['max_keys_per_user']) {
            $this->fail('You reached the maximum number of API keys.', 403);
        }
        $scopes = $this->cleanScopes($userId, $in['scopes'] ?? []);
        $appId = !empty($in['app_id']) ? (int) $in['app_id'] : null;
        if ($appId) {
            $app = $this->ownApp($userId, $appId);
            if ($extra = array_diff($scopes, (array) json_decode((string) $app->scopes, true))) {
                $this->fail('The app does not have these scopes: ' . implode(', ', $extra));
            }
        }
        $accId = $mode === 'sandbox' || empty($in['mail_account_id']) ? null : (int) $in['mail_account_id']; // مفتاح sandbox مبيتربطش بصندوق حقيقي
        if ($accId && !DB::table('mail_accounts')->where('id', $accId)->where('user_id', $userId)->exists()) {
            $this->fail('Mailbox not found.', 404);
        }
        $expires = null;
        if (!empty($in['expires_in_days'])) {
            $d = (int) $in['expires_in_days'];
            if ($d < 1 || $d > 730) {
                $this->fail('Expiry must be between 1 and 730 days.');
            }
            $expires = now()->addDays($d);
        }
        $raw = DevTokens::make($mode === 'sandbox' ? 'uipk_test_' : 'uipk_live_', 40);
        $id = DB::table('dev_api_keys')->insertGetId([
            'user_id' => $userId, 'app_id' => $appId, 'mail_account_id' => $accId, 'name' => $name, 'mode' => $mode, 'prefix' => substr($raw, 0, 18),
            'key_hash' => DevTokens::hash($raw), 'scopes' => json_encode($scopes), 'ip_allowlist' => json_encode($this->cleanIpList($in['ip_allowlist'] ?? [])),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->keyPayload(DB::table('dev_api_keys')->find($id), $raw);
    }

    private function ownKey(int $userId, int $id): object
    {
        $k = DB::table('dev_api_keys')->where('id', $id)->where('user_id', $userId)->first();
        if (!$k) {
            $this->fail('API key not found.', 404);
        }
        return $k;
    }

    public function updateKey(int $userId, int $id, array $in): array
    {
        $k = $this->ownKey($userId, $id);
        $upd = ['updated_at' => now()];
        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 80) {
                $this->fail('Give the key a name (up to 80 characters).');
            }
            $upd['name'] = $name;
        }
        if (array_key_exists('ip_allowlist', $in)) {
            $upd['ip_allowlist'] = json_encode($this->cleanIpList($in['ip_allowlist']));
        }
        DB::table('dev_api_keys')->where('id', $k->id)->update($upd);
        return $this->keyPayload(DB::table('dev_api_keys')->find($k->id));
    }

    public function revokeKey(int $userId, int $id): void
    {
        $k = $this->ownKey($userId, $id);
        DB::table('dev_api_keys')->where('id', $k->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public const ROTATE_DEFAULT_GRACE_HOURS = 24;
    public const ROTATE_MAX_GRACE_HOURS = 168;

    /**
     * متى بيبطل المفتاح القديم بعد الـ rotate؟ null = فورًا. لو المفتاح القديم كان ليه تاريخ انتهاء أقرب من فترة السماح بنحافظ عليه (السماح مبيطوّلش عمر المفتاح).
     */
    public static function rotationCutoff(?string $currentExpiresAt, int $graceHours, Carbon $now): ?Carbon
    {
        if ($graceHours <= 0) {
            return null;
        }
        $cut = $now->copy()->addHours($graceHours);
        if ($currentExpiresAt) {
            $cur = Carbon::parse($currentExpiresAt, 'UTC');
            if ($cur->lt($cut)) {
                return $cur;
            }
        }
        return $cut;
    }

    /**
     * Rotate: بيطلّع مفتاح جديد بنفس الاسم والـ mode والـ scopes والتطبيق والـ IP allowlist، ويسيب القديم شغال لفترة سماح
     * (grace_hours، افتراضي 24، الأقصى 168، 0 = يبطل فورًا) عشان تبدّل في الـ deployments من غير توقف.
     * المفتاح الجديد بيظهر مرة واحدة. القديم بيبطل لوحده بعد الفترة (expires_at) ويتبطّل نهائيًا بعدها.
     * @param array{grace_hours?:int,expires_in_days?:int} $in
     */
    public function rotateKey(int $userId, int $id, array $in = []): array
    {
        $policy = $this->assertDevAllowed($userId);
        $this->revokeRotatedExpired($userId);
        $old = $this->ownKey($userId, $id);
        if ($old->revoked_at !== null) {
            $this->fail('This key is already revoked. Create a new key instead.');
        }
        if ($old->expires_at && now()->gte(Carbon::parse($old->expires_at, 'UTC'))) {
            $this->fail('This key has expired. Create a new key instead.');
        }
        if (($old->rotated_at ?? null) !== null) {
            $this->fail('This key was already rotated. Rotate its replacement instead.');
        }
        $grace = array_key_exists('grace_hours', $in) && $in['grace_hours'] !== null ? (int) $in['grace_hours'] : self::ROTATE_DEFAULT_GRACE_HOURS;
        if ($grace < 0 || $grace > self::ROTATE_MAX_GRACE_HOURS) {
            $this->fail('grace_hours must be between 0 and ' . self::ROTATE_MAX_GRACE_HOURS . '.');
        }
        if ($grace > 0 && DB::table('dev_api_keys')->where('user_id', $userId)->whereNull('revoked_at')->count() >= (int) $policy['max_keys_per_user']) {
            $this->fail('Rotating with a grace period needs one free key slot. Revoke an unused key, or rotate with grace_hours = 0.', 403);
        }

        $oldScopes = (array) json_decode((string) $old->scopes, true);
        $scopes = array_values(array_intersect($oldScopes, $this->allowedScopesFor($userId))); // اللي لسه مسموح
        $dropped = array_values(array_diff($oldScopes, $scopes));
        $accId = $old->mail_account_id && DB::table('mail_accounts')->where('id', $old->mail_account_id)->where('user_id', $userId)->exists() ? (int) $old->mail_account_id : null;

        return DB::transaction(function () use ($userId, $old, $grace, $scopes, $dropped, $accId, $in) {
            $now = now();
            if ($grace === 0) { // نحرّر الخانة قبل ما ننشئ الجديد
                DB::table('dev_api_keys')->where('id', $old->id)->update(['revoked_at' => $now, 'rotated_at' => $now, 'updated_at' => $now]);
            }
            $created = $this->createKey($userId, [
                'name' => $old->name, 'mode' => $old->mode, 'scopes' => $scopes, 'app_id' => $old->app_id, 'mail_account_id' => $accId,
                'ip_allowlist' => (array) json_decode((string) ($old->ip_allowlist ?? '[]'), true),
                'expires_in_days' => $in['expires_in_days'] ?? null,
            ]);
            $upd = ['rotated_from_id' => $old->id, 'updated_at' => $now];
            if (empty($in['expires_in_days']) && $old->expires_at) {
                $upd['expires_at'] = $old->expires_at; // بدون تحديد: نفس تاريخ انتهاء القديم (صريح ومتوقّع)
            }
            DB::table('dev_api_keys')->where('id', $created['id'])->update($upd);
            if ($grace > 0) {
                $cut = self::rotationCutoff($old->expires_at, $grace, $now);
                DB::table('dev_api_keys')->where('id', $old->id)->update(['rotated_at' => $now, 'expires_at' => $cut, 'updated_at' => $now]);
            }
            return [
                'key' => $this->keyPayload(DB::table('dev_api_keys')->find($created['id']), $created['key']),
                'old_key' => $this->keyPayload(DB::table('dev_api_keys')->find($old->id)),
                'grace_hours' => $grace,
                'dropped_scopes' => $dropped,
            ];
        });
    }

    /** مفاتيح اتبدّلت وانتهت فترة سماحها → تتبطّل نهائيًا (بتحرّر الخانة). بتتنده من dev:maintenance ومن إنشاء/rotate المفاتيح (للمستخدم ده بس). */
    public function revokeRotatedExpired(?int $userId = null): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('dev_api_keys', 'rotated_at')) {
            return 0;
        }
        return DB::table('dev_api_keys')->whereNotNull('rotated_at')->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ grants (التطبيقات اللي وافقت عليها) */

    public function listGrants(int $userId): array
    {
        return DB::table('dev_oauth_grants as g')->join('dev_apps as a', 'a.id', '=', 'g.app_id')->where('g.user_id', $userId)->whereNull('g.revoked_at')
            ->orderByDesc('g.id')->get(['g.id as gid', 'g.scopes as gscopes', 'g.mail_account_id', 'g.created_at as gcreated', 'g.last_used_at as glast', 'a.id as aid', 'a.name', 'a.description', 'a.homepage_url', 'a.client_id', 'a.status',
                'a.owner_user_id', 'a.university_id', 'a.scopes as app_scopes', 'a.redirect_uris', 'a.review_status', 'a.review_scopes', 'a.review_redirects_hash'])
            ->map(function ($r) {
                $scopes = (array) json_decode((string) $r->gscopes, true);
                return ['id' => (int) $r->gid, 'app' => ['id' => (int) $r->aid, 'name' => $r->name, 'description' => $r->description, 'homepage_url' => $r->homepage_url, 'client_id' => $r->client_id, 'status' => $r->status,
                    'review_state' => $this->reviewState((object) ['scopes' => $r->app_scopes, 'redirect_uris' => $r->redirect_uris, 'review_status' => $r->review_status, 'review_scopes' => $r->review_scopes,
                        'review_redirects_hash' => $r->review_redirects_hash, 'university_id' => $r->university_id])],
                    'scopes' => $scopes, 'scopes_described' => DevScopes::describe($scopes), 'mail_account_id' => $r->mail_account_id ? (int) $r->mail_account_id : null,
                    'created_at' => DevTokens::iso($r->gcreated), 'last_used_at' => DevTokens::iso($r->glast)];
            })->values()->all();
    }

    public function revokeGrant(int $userId, int $grantId): void
    {
        $g = DB::table('dev_oauth_grants')->where('id', $grantId)->where('user_id', $userId)->whereNull('revoked_at')->first();
        if (!$g) {
            $this->fail('Authorization not found.', 404);
        }
        DB::transaction(function () use ($g) {
            DB::table('dev_oauth_grants')->where('id', $g->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('dev_oauth_tokens')->where('grant_id', $g->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });
        $app = DB::table('dev_apps')->where('id', $g->app_id)->first(['id', 'owner_user_id', 'name']);
        if ($app) {
            app(DevEventBus::class)->emit('app.revoked', (int) $app->owner_user_id, ['app_id' => (int) $app->id, 'grant_id' => (int) $g->id], null, 'live', (int) $app->id);
        }
    }

    /* ------------------------------------------------------------------ admin (الجامعة / أدمن المنصة) */

    /** @param array{university_id?:int,status?:string,q?:string} $f */
    public function adminApps(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_apps as a')->leftJoin('users as u', 'u.id', '=', 'a.owner_user_id');
        if (!empty($f['university_id'])) {
            $q->where('a.university_id', (int) $f['university_id']);
        }
        if (!empty($f['status'])) {
            $q->where('a.status', (string) $f['status']);
        }
        if (!empty($f['review']) && in_array($f['review'], DevAppReview::STATUSES, true)) {
            $q->where('a.review_status', (string) $f['review']);
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('a.name', 'like', $like)->orWhere('a.client_id', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByRaw("CASE WHEN a.review_status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('a.id')->forPage($page, $per)->get(['a.*', 'u.full_name as owner_name', 'u.email as owner_email'])->map(function ($a) {
            return $this->appPayload($a) + ['owner_name' => $a->owner_name ?? null, 'owner_email' => $a->owner_email ?? null,
                'keys' => (int) DB::table('dev_api_keys')->where('user_id', $a->owner_user_id)->whereNull('revoked_at')->count()];
        })->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** $universityId = null → أدمن المنصة (أي جامعة). */
    public function adminSetStatus(int $appId, ?int $universityId, bool $suspend, string $by, ?string $reason = null): array
    {
        $a = DB::table('dev_apps')->where('id', $appId)->when($universityId, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        if ($suspend) {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'suspended', 'suspended_by' => $by, 'suspended_reason' => Str::limit(trim((string) $reason) ?: 'Suspended by an administrator', 200, ''), 'updated_at' => now()]);
        } else {
            DB::table('dev_apps')->where('id', $a->id)->update(['status' => 'active', 'suspended_by' => null, 'suspended_reason' => null, 'updated_at' => now()]);
        }
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    private function adminApp(int $appId, ?int $universityId): object
    {
        $a = DB::table('dev_apps')->where('id', $appId)->when($universityId, fn ($q) => $q->where('university_id', $universityId))->first();
        if (!$a) {
            $this->fail('App not found.', 404);
        }
        return $a;
    }

    /** تفاصيل تطبيق للإدارة: بياناته + مفاتيحه + Webhooks + عدد المستخدمين. */
    public function adminAppDetail(int $appId, ?int $universityId): array
    {
        $a = $this->adminApp($appId, $universityId);
        $owner = DB::table('users')->where('id', $a->owner_user_id)->first(['full_name', 'email']);
        return ['app' => $this->appPayload($a) + ['owner_name' => $owner->full_name ?? null, 'owner_email' => $owner->email ?? null],
            'keys' => DB::table('dev_api_keys')->where('app_id', $a->id)->orderByDesc('id')->get()->map(fn ($k) => $this->keyPayload($k))->values()->all(),
            'webhooks' => DB::table('dev_webhooks')->where('app_id', $a->id)->orderByDesc('id')->get(['id', 'name', 'url', 'events', 'mode', 'is_active', 'last_status', 'last_delivery_at'])
                ->map(fn ($h) => ['id' => (int) $h->id, 'name' => $h->name, 'url' => $h->url, 'events' => (array) json_decode((string) $h->events, true), 'mode' => $h->mode,
                    'is_active' => (bool) $h->is_active, 'last_status' => $h->last_status, 'last_delivery_at' => DevTokens::iso($h->last_delivery_at)])->values()->all(),
            'authorized_users' => (int) DB::table('dev_oauth_grants')->where('app_id', $a->id)->whereNull('revoked_at')->count(),
            'review_history' => $this->reviewHistory((int) $a->id, true)];
    }

    /** الإدارة تقيّد تطبيق: scopes (بتتقص من المفاتيح كمان) وحد الطلبات. */
    public function adminUpdateApp(int $appId, ?int $universityId, array $in): array
    {
        $a = $this->adminApp($appId, $universityId);
        $upd = ['updated_at' => now()];
        if (array_key_exists('scopes', $in)) {
            $scopes = DevScopes::parse($in['scopes']);
            if (!$scopes) {
                $this->fail('Choose at least one scope.');
            }
            if ($bad = DevScopes::unknown($scopes)) {
                $this->fail('Unknown scope: ' . implode(', ', $bad));
            }
            $upd['scopes'] = json_encode($scopes);
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $upd['rate_limit_per_min'] = ($in['rate_limit_per_min'] === null || $in['rate_limit_per_min'] === '') ? null : max(1, min(100000, (int) $in['rate_limit_per_min']));
        }
        DB::table('dev_apps')->where('id', $a->id)->update($upd);
        $this->reviewAfterChange($a, null, 'admin');
        return $this->appPayload(DB::table('dev_apps')->find($a->id));
    }

    public function adminDeleteApp(int $appId, ?int $universityId): void
    {
        $a = $this->adminApp($appId, $universityId);
        $this->deleteApp((int) $a->owner_user_id, (int) $a->id);
    }

    /** @param array{university_id?:int,status?:string,mode?:string,q?:string,user_id?:int,app_id?:int} $f */
    public function adminKeys(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_api_keys as k')->leftJoin('users as u', 'u.id', '=', 'k.user_id');
        if (!empty($f['university_id'])) {
            $q->whereIn('k.user_id', $this->ownerIdsOfUniversity((int) $f['university_id']) ?: [0]);
        }
        foreach (['user_id' => 'k.user_id', 'app_id' => 'k.app_id'] as $in => $col) {
            if (!empty($f[$in])) {
                $q->where($col, (int) $f[$in]);
            }
        }
        if (!empty($f['mode']) && in_array($f['mode'], ['live', 'sandbox'], true)) {
            $q->where('k.mode', $f['mode']);
        }
        $st = (string) ($f['status'] ?? '');
        if ($st === 'active') {
            $q->whereNull('k.revoked_at')->where(fn ($w) => $w->whereNull('k.expires_at')->orWhere('k.expires_at', '>', now()));
        } elseif ($st === 'revoked') {
            $q->whereNotNull('k.revoked_at');
        } elseif ($st === 'expired') {
            $q->whereNull('k.revoked_at')->whereNotNull('k.expires_at')->where('k.expires_at', '<=', now());
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('k.name', 'like', $like)->orWhere('k.prefix', 'like', $like)->orWhere('u.email', 'like', $like)->orWhere('u.full_name', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('k.id')->forPage($page, $per)->get(['k.*', 'u.full_name as owner_name', 'u.email as owner_email'])
            ->map(fn ($k) => $this->keyPayload($k) + ['user_id' => (int) $k->user_id, 'owner_name' => $k->owner_name, 'owner_email' => $k->owner_email])->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    public function adminRevokeKey(int $keyId, ?int $universityId): array
    {
        $k = DB::table('dev_api_keys')->where('id', $keyId)->when($universityId, fn ($q) => $q->whereIn('user_id', $this->ownerIdsOfUniversity($universityId) ?: [0]))->first();
        if (!$k) {
            $this->fail('API key not found.', 404);
        }
        DB::table('dev_api_keys')->where('id', $k->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        return $this->keyPayload(DB::table('dev_api_keys')->find($k->id)) + ['user_id' => (int) $k->user_id];
    }

    /** IDs أصحاب التطبيقات/المفاتيح في الجامعة (لتصفية السجل في لوحتها). */
    public function ownerIdsOfUniversity(int $universityId): array
    {
        return DB::table('mail_accounts')->where('university_id', $universityId)->distinct()->pluck('user_id')->map(fn ($x) => (int) $x)->all();
    }
}
