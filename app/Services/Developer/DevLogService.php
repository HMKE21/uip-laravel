<?php

namespace App\Services\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** سجل التكاملات: طلبات الـ API، OAuth، الـ Webhooks، وأحداث الإدارة. أي فشل في التسجيل مبيكسرش الطلب. */
class DevLogService
{
    public function record(array $f): void
    {
        try {
            DB::table('dev_request_logs')->insert([
                'user_id' => $f['user_id'] ?? null, 'app_id' => $f['app_id'] ?? null, 'api_key_id' => $f['api_key_id'] ?? null, 'token_id' => $f['token_id'] ?? null,
                'kind' => $f['kind'] ?? 'api', 'method' => isset($f['method']) ? substr((string) $f['method'], 0, 8) : null,
                'path' => isset($f['path']) ? substr((string) $f['path'], 0, 200) : null, 'status' => $f['status'] ?? null,
                'duration_ms' => $f['duration_ms'] ?? null, 'scope' => $f['scope'] ?? null, 'error_code' => $f['error_code'] ?? null,
                'ip' => $f['ip'] ?? null, 'note' => isset($f['note']) ? substr((string) $f['note'], 0, 200) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Dev log failed', ['error' => $e->getMessage()]);
        }
    }

    public function fromRequest(Request $r, int $status, array $ctx, int $ms, ?string $errorCode = null, ?string $scope = null): void
    {
        $this->record([
            'user_id' => $ctx['user_id'] ?? null, 'app_id' => $ctx['app_id'] ?? null, 'api_key_id' => $ctx['key_id'] ?? null, 'token_id' => $ctx['token_id'] ?? null,
            'kind' => ($ctx['mode'] ?? 'live') === 'sandbox' ? 'sandbox' : 'api', 'method' => $r->method(), 'path' => '/' . ltrim($r->path(), '/'),
            'status' => $status, 'duration_ms' => $ms, 'scope' => $scope, 'error_code' => $errorCode, 'ip' => $r->ip(),
        ]);
    }

    /**
     * @param array{user_id?:int,tenant_id?:int,app_id?:int,kind?:string,status?:string,from?:string,to?:string,q?:string,university_id?:int,owner_ids?:int[]} $f
     */
    public function search(array $f, int $page = 1, int $per = 30): array
    {
        $per = max(1, min(100, $per));
        $page = max(1, $page);
        $q = DB::table('dev_request_logs as l');
        if (!empty($f['user_id'])) { $q->where('l.user_id', (int) $f['user_id']); }
        if (!empty($f['app_id'])) { $q->where('l.app_id', (int) $f['app_id']); }
        if (!empty($f['api_key_id'])) { $q->where('l.api_key_id', (int) $f['api_key_id']); }
        if (!empty($f['kind'])) { $q->where('l.kind', (string) $f['kind']); }
        if (!empty($f['owner_ids'])) { $q->whereIn('l.user_id', $f['owner_ids']); }
        if (!empty($f['tenant_id'])) { // سجل الـ Workspace: طلبات مفاتيحه وتطبيقاته (بما فيها توكنز OAuth) وأحداث إدارته
            $tid = (int) $f['tenant_id'];
            $q->where(fn ($w) => $w->whereIn('l.api_key_id', DB::table('dev_api_keys')->where('tenant_id', $tid)->select('id'))
                ->orWhereIn('l.app_id', DB::table('dev_apps')->where('tenant_id', $tid)->select('id'))
                ->orWhere('l.path', 'like', 'tenant/' . $tid . '/%'));
        }
        if (!empty($f['status'])) {
            $s = (string) $f['status'];
            if ($s === 'error') { $q->where('l.status', '>=', 400); }
            elseif ($s === 'ok') { $q->where('l.status', '<', 400); }
            elseif (ctype_digit($s)) { $q->where('l.status', (int) $s); }
        }
        if (!empty($f['from'])) { $q->where('l.created_at', '>=', $this->dt($f['from'])); }
        if (!empty($f['to'])) { $q->where('l.created_at', '<=', $this->dt($f['to'])); }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
            $q->where(fn ($w) => $w->where('l.path', 'like', $like)->orWhere('l.note', 'like', $like)->orWhere('l.error_code', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('l.id')->forPage($page, $per)->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'kind' => $r->kind, 'method' => $r->method, 'path' => $r->path, 'status' => $r->status !== null ? (int) $r->status : null,
            'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null, 'scope' => $r->scope, 'error_code' => $r->error_code, 'ip' => $r->ip, 'note' => $r->note,
            'app_id' => $r->app_id ? (int) $r->app_id : null, 'api_key_id' => $r->api_key_id ? (int) $r->api_key_id : null, 'token_id' => $r->token_id ? (int) $r->token_id : null,
            'user_id' => $r->user_id ? (int) $r->user_id : null, 'created_at' => DevTokens::iso($r->created_at),
        ])->values()->all();
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    private function dt(string $v): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($v)->utc()->toDateTimeString();
        } catch (\Throwable $e) {
            return '1970-01-01 00:00:00';
        }
    }

    public function prune(): array
    {
        $l = DB::table('dev_request_logs')->where('created_at', '<', now()->subDays(max(1, (int) config('developer.logs.retention_days', 30))))->delete();
        $d = DB::table('dev_webhook_deliveries')->where('created_at', '<', now()->subDays(max(1, (int) config('developer.logs.delivery_days', 14))))
            ->whereIn('status', ['success', 'failed'])->delete();
        $c = DB::table('dev_oauth_codes')->where('expires_at', '<', now()->subDay())->delete();
        $t = DB::table('dev_oauth_tokens')->where(function ($q) {
            $q->where('refresh_expires_at', '<', now()->subDays(7))->orWhere(fn ($w) => $w->whereNull('refresh_expires_at')->where('access_expires_at', '<', now()->subDays(7)));
        })->delete();
        return ['logs' => $l, 'deliveries' => $d, 'codes' => $c, 'tokens' => $t];
    }
}
