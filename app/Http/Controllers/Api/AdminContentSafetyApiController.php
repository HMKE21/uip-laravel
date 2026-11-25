<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentSafetyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Content Safety (فحص المحتوى غير اللائق / الحسّاس).
 *
 *   GET  /api/v1/admin/content-safety?page=&kind=&category=   حالة الفاحص + إحصائيات + سجل المحاولات
 *   POST /api/v1/admin/content-safety/test-text  { text, strict? }   اختبار نص على القاموس (من غير ما يتسجّل)
 *   POST /api/v1/admin/content-safety/test-file  multipart { file }   اختبار صورة/فيديو/GIF (حكم + درجات الفاحص) من غير تخزين أو تسجيل
 *   GET  /api/v1/admin/content-safety/events/{id}/file   معاينة نسخة الملف المحجوب
 *   POST /api/v1/admin/content-safety/events/{id}/allow  السماح بالمحتوى ده بالظبط (بالهاش)
 *   DELETE /api/v1/admin/content-safety/allowlist/{id}   إلغاء السماح
 *   DELETE /api/v1/admin/content-safety/events/{id}       حذف محاولة واحدة من السجل
 *   DELETE /api/v1/admin/content-safety/events?kind=&category=   حذف كل المحاولات (أو المطابقة للفلتر الحالي)
 *
 * RBAC: uip.auth + uip.admin على الـ group + نفس حارس uip_role === 'admin'.
 */
class AdminContentSafetyApiController extends Controller
{
    public function __construct(private ContentSafetyService $safety)
    {
    }

    private function requireAdmin(Request $request)
    {
        return $request->attributes->get('uip_role') === 'admin' ? null : $this->apiError('Only admin accounts can view content safety.', null, 403);
    }

    public function index(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;
        $stats = ['last_24h' => 0, 'last_7d' => 0, 'by_category' => [], 'by_kind' => [], 'top_users' => []];
        $items = [];
        $allowlist = [];
        $hasMore = false;
        try {
            $stats['last_24h'] = (int) DB::table('content_safety_events')->where('created_at', '>=', now()->subDay())->count();
            $stats['last_7d'] = (int) DB::table('content_safety_events')->where('created_at', '>=', now()->subDays(7))->count();
            $stats['by_category'] = DB::table('content_safety_events')->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('category, COUNT(*) c')->groupBy('category')->pluck('c', 'category')->all();
            $stats['by_kind'] = DB::table('content_safety_events')->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('kind, COUNT(*) c')->groupBy('kind')->pluck('c', 'kind')->all();
            $stats['top_users'] = DB::table('content_safety_events as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.created_at', '>=', now()->subDays(7))
                ->selectRaw('u.id, u.uuid, u.full_name, u.email, COUNT(*) c')->groupBy('u.id', 'u.uuid', 'u.full_name', 'u.email')->orderByDesc('c')->limit(8)->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'uuid' => $r->uuid, 'full_name' => $r->full_name, 'email' => $r->email, 'count' => (int) $r->c])->all();

            $q = DB::table('content_safety_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id');
            if ($k = (string) $request->query('kind', '')) {
                $q->where('e.kind', $k);
            }
            if ($c = (string) $request->query('category', '')) {
                $q->where('e.category', $c);
            }
            $rows = $q->orderByDesc('e.id')->offset(($page - 1) * $per)->limit($per + 1)
                ->get($this->eventColumns())->all();
            $hasMore = count($rows) > $per;
            $items = array_map(fn ($r) => [
                'id' => (int) $r->id, 'user_id' => $r->user_id ? (int) $r->user_id : null, 'user_uuid' => $r->user_uuid, 'full_name' => $r->full_name, 'email' => $r->email,
                'kind' => $r->kind, 'category' => $r->category, 'detail' => $r->detail, 'route' => $r->route, 'ip' => $r->ip, 'created_at' => $r->created_at,
                'sample' => $r->sample ?? null, 'file_name' => $r->file_name ?? null, 'file_mime' => $r->file_mime ?? null,
                'file_size' => isset($r->file_size) ? (int) $r->file_size : null, 'has_file' => (bool) ($r->has_file ?? false),
                'can_allow' => !empty($r->content_hash ?? null), 'status' => $r->status ?? 'blocked',
            ], array_slice($rows, 0, $per));
            $allowlist = DB::table('content_safety_allowlist')->orderByDesc('id')->limit(100)->get(['id', 'type', 'label', 'event_id', 'created_at'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'type' => $r->type, 'label' => $r->label, 'event_id' => $r->event_id ? (int) $r->event_id : null, 'created_at' => $r->created_at])->all();
        } catch (\Throwable $e) {
            // الجدول لسه معمولش migrate — الصفحة تفتح بحالة الفاحص بس
        }

        return $this->apiSuccess([
            'status' => $this->safety->status(),
            'stats' => $stats,
            'items' => $items,
            'allowlist' => $allowlist,
            'has_more' => $hasMore,
        ], 'Content safety retrieved.');
    }

    private function eventColumns(): array
    {
        $cols = ['e.id', 'e.user_id', 'u.uuid as user_uuid', 'u.full_name', 'u.email', 'e.kind', 'e.category', 'e.detail', 'e.route', 'e.ip', 'e.created_at'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('content_safety_events', 'sample')) {
            array_push($cols, 'e.sample', 'e.content_hash', 'e.file_name', 'e.file_mime', 'e.file_size', 'e.has_file', 'e.status');
        }

        return $cols;
    }

    /** نسخة الملف المحجوب (أدمن بس). */
    public function file(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $e = DB::table('content_safety_events')->where('id', (int) $id)->first();
        $path = $e && !empty($e->content_hash) ? $this->safety->quarantinePath((string) $e->content_hash) : null;
        if (!$path) {
            return $this->apiError('The blocked file copy is not available (expired or too large).', null, 404);
        }
        $this->audit($request, 'content_safety.file_viewed', ['event_id' => (int) $id]);

        return response()->file($path, [
            'Content-Type' => (string) ($e->file_mime ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="blocked-' . (int) $id . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; media-src 'self' blob:; img-src 'self' data: blob:",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** السماح بالمحتوى ده بالظبط (نفس الملف/النص) — مش بيفتح الكلمة لكل الناس. */
    public function allow(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $e = DB::table('content_safety_events')->where('id', (int) $id)->first();
        if (!$e) {
            return $this->apiError('Event not found.', null, 404);
        }
        $hash = (string) ($e->content_hash ?? '');
        if ($hash === '') {
            return $this->apiError('This entry has no stored content to approve (logged before review was enabled). Ask the user to try again, then approve the new entry.', null, 422);
        }
        $type = in_array($e->kind, ['text', 'svg_text', 'gif_query', 'gif_title'], true) ? 'text' : 'file';
        $uid = (int) $request->attributes->get('uip_user_id');
        $label = mb_substr((string) ($e->file_name ?: $e->sample ?: $e->detail), 0, 190);
        DB::table('content_safety_allowlist')->updateOrInsert(
            ['type' => $type, 'hash' => $hash],
            ['label' => $label, 'event_id' => (int) $e->id, 'created_by' => $uid ?: null, 'created_at' => now()]
        );
        DB::table('content_safety_events')->where('content_hash', $hash)->update(['status' => 'allowed', 'reviewed_by' => $uid ?: null, 'reviewed_at' => now()]);
        $this->safety->forgetAllowlist();
        \Illuminate\Support\Facades\Cache::forget('csafe:file:' . $this->safety->provider() . ':' . $hash);
        $this->audit($request, 'content_safety.allowed', ['event_id' => (int) $e->id, 'type' => $type, 'kind' => $e->kind]);

        return $this->apiSuccess(['allowed' => true, 'type' => $type], 'Allowed. The user can upload it again.');
    }

    /** إلغاء السماح. */
    public function revoke(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $row = DB::table('content_safety_allowlist')->where('id', (int) $id)->first();
        if (!$row) {
            return $this->apiError('Entry not found.', null, 404);
        }
        DB::table('content_safety_allowlist')->where('id', (int) $id)->delete();
        DB::table('content_safety_events')->where('content_hash', $row->hash)->where('status', 'allowed')->update(['status' => 'blocked', 'reviewed_by' => null, 'reviewed_at' => null]);
        $this->safety->forgetAllowlist();
        $this->audit($request, 'content_safety.allow_revoked', ['allowlist_id' => (int) $id, 'type' => $row->type]);

        return $this->apiSuccess(['revoked' => true], 'Approval removed.');
    }

    public function testText(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $text = mb_substr((string) $request->input('text', ''), 0, 2000);
        $hit = $this->safety->checkText($text, (bool) $request->input('strict', false));

        return $this->apiSuccess(['blocked' => $hit !== null, 'match' => $hit], 'Tested.');
    }

    /** اختبار ملف (صورة/فيديو/GIF/أي ملف) — الملف بيتفحص من الملف المؤقت وبيتمسح، مفيش تخزين ولا تسجيل في السجل. */
    public function testFile(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $file = $request->file('file');
        if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid() || !$file->getRealPath()) {
            return $this->apiError('Choose a valid file to test.', null, 422);
        }
        $report = $this->safety->analyzeFile($file->getRealPath(), $file->getClientOriginalName());
        $report['name'] = $file->getClientOriginalName();
        $report['size'] = (int) $file->getSize();

        return $this->apiSuccess($report, 'Tested.');
    }

    /** حذف محاولة واحدة من سجل المحاولات المحجوبة. */
    public function destroy(Request $request, $id)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $hash = (string) (DB::table('content_safety_events')->where('id', (int) $id)->value('content_hash') ?? '');
        $n = DB::table('content_safety_events')->where('id', (int) $id)->delete();
        if ($n === 0) {
            return $this->apiError('Event not found.', null, 404);
        }
        if ($hash !== '' && !DB::table('content_safety_events')->where('content_hash', $hash)->exists()) {
            $this->safety->dropQuarantine($hash);
        }
        $this->audit($request, 'content_safety.event_deleted', ['event_id' => (int) $id]);

        return $this->apiSuccess(['deleted' => $n], 'Event deleted.');
    }

    /** حذف كل السجل، أو بس المطابق للفلتر (kind / category) لو اتبعت. */
    public function clear(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $q = DB::table('content_safety_events');
        $kind = (string) $request->input('kind', '');
        $category = (string) $request->input('category', '');
        if ($kind !== '') {
            $q->where('kind', $kind);
        }
        if ($category !== '') {
            $q->where('category', $category);
        }
        $hashes = (clone $q)->whereNotNull('content_hash')->pluck('content_hash')->unique()->all();
        $n = $q->delete();
        foreach ($hashes as $h) {
            if (!DB::table('content_safety_events')->where('content_hash', $h)->exists()) {
                $this->safety->dropQuarantine((string) $h);
            }
        }
        $this->audit($request, 'content_safety.events_cleared', ['deleted' => $n, 'kind' => $kind ?: null, 'category' => $category ?: null]);

        return $this->apiSuccess(['deleted' => $n], 'Events deleted.');
    }

    /** حذف سجل أمني لازم يتسجّل في الـ audit (best effort). */
    private function audit(Request $request, string $action, array $meta): void
    {
        try {
            $uid = (int) $request->attributes->get('uip_user_id');
            app(\App\Services\AuditLogService::class)->record($uid ?: null, $action, 'ContentSafety', null, null, $meta, (string) $request->ip());
        } catch (\Throwable $e) {
            // التسجيل مايكسرش الحذف
        }
    }
}
