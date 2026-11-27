<?php

namespace App\Http\Controllers\Api;

use App\Support\SearchLike;
use App\Http\Controllers\Controller;
use App\Services\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/v1/search?q&types=people,projects,universities&limit&page — شريط البحث العلوي + صفحة النتائج (كل الأدوار).
 *
 *  - people: اسم / @username / ID / إيميل (قواعد الخصوصية في NetworkService::searchPeople). متاح لكل مستخدم مسجّل.
 *  - projects, universities: للأدمن بس (بحث إداري على مستوى المنصة).
 *  - types بتقبل "users" كمرادف لـ "people" (الواجهة القديمة بتبعتها).
 * الشكل: data = { people: [...], projects: [...], universities: [...] } + meta = { total, has_more, query }.
 */
class GlobalSearchApiController extends Controller
{
    public function __construct(private NetworkService $network)
    {
    }

    public function index(Request $request)
    {
        $uid = (int) $request->attributes->get('uip_user_id');
        $isAdmin = $request->attributes->get('uip_role') === 'admin';
        $q = trim((string) $request->input('q', ''));
        $limit = max(1, min(30, (int) $request->input('limit', 8)));
        $page = max(1, (int) $request->input('page', 1));

        $types = array_filter(array_map('trim', explode(',', (string) $request->input('types', 'people'))));
        $types = array_map(fn ($t) => $t === 'users' ? 'people' : $t, $types);
        if (!$isAdmin) {
            $types = array_values(array_intersect($types, ['people']));
        }
        $out = ['people' => [], 'projects' => [], 'universities' => []];
        $hasMore = false;

        if ($q === '' || (mb_strlen(ltrim($q, '@#')) < 2 && !preg_match('/^#\d+$/', $q))) {
            return $this->apiSuccess($out, 'Search results retrieved successfully.', 200, ['total' => 0, 'has_more' => false, 'query' => $q]);
        }

        try {
            if (in_array('people', $types, true)) {
                $res = $this->network->searchPeople($uid, $isAdmin, $q, [
                    'role'     => (string) $request->input('role', ''),
                    'relation' => in_array($request->input('relation'), ['connections', 'following', 'followers'], true) ? (string) $request->input('relation') : 'all',
                ], $page, $limit);
                $out['people'] = $res['items'];
                $hasMore = $res['has_more'];
            }
            if ($isAdmin) {
                $core = ltrim($q, '@#');
                if (in_array('projects', $types, true)) {
                    $out['projects'] = DB::table('projects')->whereNull('deleted_at')
                        ->where(function ($w) use ($core, $q) {
                            $w->where('uuid', $q);
                            $w->orWhere(fn ($x) => SearchLike::apply($x, ['title_ar', 'title_en', 'category', 'summary'], $core));
                        })
                        ->orderByDesc('id')->limit($limit)->get(['id', 'uuid', 'title_ar', 'title_en', 'status', 'visibility'])
                        ->map(fn ($r) => ['type' => 'projects', 'id' => (int) $r->id, 'uuid' => $r->uuid, 'title' => $r->title_en ?: $r->title_ar, 'subtitle' => $r->status . ' · ' . $r->visibility])->all();
                }
                if (in_array('universities', $types, true)) {
                    $out['universities'] = DB::table('universities')
                        ->tap(fn ($qb) => SearchLike::apply($qb, ['official_name_en', 'official_name_ar', 'slug', 'city'], $core))
                        ->orderBy('official_name_en')->limit($limit)->get(['id', 'official_name_en', 'official_name_ar', 'slug'])
                        ->map(fn ($r) => ['type' => 'universities', 'id' => (int) $r->id, 'title' => $r->official_name_en ?: $r->official_name_ar, 'subtitle' => $r->official_name_ar, 'slug' => $r->slug])->all();
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Global search DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', null, 500);
        }

        $total = count($out['people']) + count($out['projects']) + count($out['universities']);
        return $this->apiSuccess($out, 'Search results retrieved successfully.', 200, ['total' => $total, 'has_more' => $hasMore, 'query' => $q]);
    }
}
