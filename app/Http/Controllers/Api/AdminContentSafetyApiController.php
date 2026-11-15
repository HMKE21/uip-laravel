<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentSafetyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Content Safety (فحص المحتوى الإباحي / المخلّ / الحسّاس).
 *
 *   GET  /api/v1/admin/content-safety?page=&kind=&category=   حالة الفاحص + إحصائيات + سجل المحاولات
 *   POST /api/v1/admin/content-safety/test-text  { text, strict? }   اختبار نص على القاموس (من غير ما يتسجّل)
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
                ->selectRaw('u.id, u.full_name, u.email, COUNT(*) c')->groupBy('u.id', 'u.full_name', 'u.email')->orderByDesc('c')->limit(8)->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'full_name' => $r->full_name, 'email' => $r->email, 'count' => (int) $r->c])->all();

            $q = DB::table('content_safety_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id');
            if ($k = (string) $request->query('kind', '')) {
                $q->where('e.kind', $k);
            }
            if ($c = (string) $request->query('category', '')) {
                $q->where('e.category', $c);
            }
            $rows = $q->orderByDesc('e.id')->offset(($page - 1) * $per)->limit($per + 1)
                ->get(['e.id', 'e.user_id', 'u.full_name', 'u.email', 'e.kind', 'e.category', 'e.detail', 'e.route', 'e.ip', 'e.created_at'])->all();
            $hasMore = count($rows) > $per;
            $items = array_map(fn ($r) => [
                'id' => (int) $r->id, 'user_id' => $r->user_id ? (int) $r->user_id : null, 'full_name' => $r->full_name, 'email' => $r->email,
                'kind' => $r->kind, 'category' => $r->category, 'detail' => $r->detail, 'route' => $r->route, 'ip' => $r->ip, 'created_at' => $r->created_at,
            ], array_slice($rows, 0, $per));
        } catch (\Throwable $e) {
            // الجدول لسه معمولش migrate — الصفحة تفتح بحالة الفاحص بس
        }

        return $this->apiSuccess([
            'status' => $this->safety->status(),
            'stats' => $stats,
            'items' => $items,
            'has_more' => $hasMore,
        ], 'Content safety retrieved.');
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
}
