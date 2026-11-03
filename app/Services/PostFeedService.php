<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * فيد المنشورات. أنماط (mode):
 *   for_you     مرتّب بالخوارزمية (تحت)           following   منشورات اللي بتتابعهم بس (من غير منشوراتك)، بالأحدث
 *   latest      كل المرئي ليك بالأحدث              trending    الأعلى تفاعلًا (velocity) في آخر 7 أيام، ولو قليلة بيوسّع لـ 30 يوم
 *   university  منشورات جامعتك                      saved       المحفوظات
 *   user        بروفايل شخص (المثبّت الأول)         tag         وسم معيّن
 *   search      بحث نصّي (نص/عنوان/وسم/اسم الكاتب)  mine        منشوراتي بكل حالاتها (مسودات/مجدولة/معلّقة/مؤرشفة)
 *
 * خوارزمية for_you (كل الأوزان قابلة للتعديل من الأدمن — PostSettings، مجموعة ranking):
 *   1) مرشحين: أحدث N منشور مرئي (آخر candidate_days يوم) + أعلى N/3 "hot" — مع استبعاد المخفي وكاتبين مكتومين، والرؤية
 *      بتتطبق في الـ SQL نفسه (PostVisibility) فمفيش مرشح المستخدم مايقدرش يشوفه.
 *   2) السكور = w_recency·recency + w_affinity·affinity + w_engagement·engagement + w_interest·interest
 *               + w_network·network + w_media·mediaBoost (+ own_post_boost لمنشوراتك الجديدة)
 *        recency    = 0.5^(العمر بالساعات / half_life_hours)
 *        affinity   = متابعة (0.5) + تفاعلك مع الكاتب آخر 30 يوم (0.35) + محادثة مباشرة (0.15)
 *        engagement = log(تفاعل مرجّح) + معدّل التفاعل لكل مشاهدة (عشان منشور صغير ممتاز يطلع على كبير ممل)
 *        interest   = تطابق وسوم المنشور مع user_post_interests (بتتغذّى من تفاعلك/حفظك/تعليقك/مكوثك)
 *        network    = نفس الجامعة/الكلية
 *        mediaBoost = وسائط غنية (PDF/كود/فيديو) أعلى شوية من نص عادي
 *   3) عقوبة: منشور شفته قبل كده × (1 − seen_penalty).
 *   4) تنويع: إعادة ترتيب جشعة تخصم من اللي نفس كاتبه ظهر في آخر 4 منشورات (diversity_penalty).
 *   القايمة المرتّبة (ids) بتتكاشّى cache_seconds ثانية لكل مستخدم عشان الصفحات التالية تفضل ثابتة (من غير تكرار/تخطي).
 */
class PostFeedService
{
    public const MODES = ['for_you', 'following', 'latest', 'trending', 'university', 'saved', 'user', 'tag', 'search', 'mine'];

    private const HOT = '((p.reactions_count + 3*p.comments_count + 4*p.reposts_count + 3*p.saves_count + 2*p.shares_count + 0.2*p.views_count)
                         / POW(TIMESTAMPDIFF(HOUR, p.published_at, NOW()) + 2, 1.3))';

    private const CANDIDATE_COLS = ['p.id', 'p.user_id', 'p.kind', 'p.visibility', 'p.university_id', 'p.faculty_id', 'p.published_at',
        'p.reactions_count', 'p.comments_count', 'p.reposts_count', 'p.saves_count', 'p.shares_count', 'p.views_count'];

    private const MEDIA_BOOST = ['mixed' => 1.0, 'document' => 1.0, 'code' => 0.9, 'media' => 0.8, 'audio' => 0.7, 'link' => 0.3, 'text' => 0.0];

    public function __construct(private PostVisibility $vis, private PostService $posts)
    {
    }

    /** @return array{items:array,meta:array} */
    public function feed(int $uid, array $opts): array
    {
        $mode = in_array($opts['mode'] ?? '', self::MODES, true) ? $opts['mode'] : 'for_you';
        $page = max(1, (int) ($opts['page'] ?? 1));
        $per = max(1, min((int) config('posts.feed.max_per_page', 30), (int) ($opts['per_page'] ?? 10)));
        $viewer = $this->vis->viewer($uid);

        if ($mode === 'for_you') {
            [$rows, $hasMore] = $this->forYou($viewer, $page, $per, !empty($opts['refresh']));
        } else {
            $q = $this->modeQuery($mode, $viewer, $opts);
            if ($q === null) {
                return ['items' => [], 'meta' => ['mode' => $mode, 'page' => $page, 'per_page' => $per, 'has_more' => false]];
            }
            $rows = $q->offset(($page - 1) * $per)->limit($per + 1)->get(['p.*'])->all();
            $hasMore = count($rows) > $per;
            $rows = array_slice($rows, 0, $per);
        }

        return [
            'items' => $this->posts->present($rows, $uid),
            'meta'  => ['mode' => $mode, 'page' => $page, 'per_page' => $per, 'has_more' => $hasMore],
        ];
    }

    private function modeQuery(string $mode, array $viewer, array $opts): ?Builder
    {
        $uid = (int) $viewer['id'];

        if ($mode === 'mine') {
            $status = (string) ($opts['status'] ?? 'all');
            $q = DB::table('posts as p')->where('p.user_id', $uid)->whereNull('p.deleted_at')->where('p.status', '!=', 'removed');
            if (in_array($status, ['draft', 'scheduled', 'pending', 'published', 'archived'], true)) {
                $q->where('p.status', $status);
            }
            return $q->orderByDesc('p.updated_at')->orderByDesc('p.id');
        }

        if ($mode === 'user') {
            $target = (int) ($opts['user_id'] ?? 0);
            if ($target <= 0) {
                return null;
            }
            return $this->vis->visibleQuery($viewer)->where('p.user_id', $target)
                ->orderByRaw('p.pinned_at IS NULL')->orderByDesc('p.pinned_at')->orderByDesc('p.published_at')->orderByDesc('p.id');
        }

        if ($mode === 'saved') {
            $col = $opts['collection'] ?? null;
            $q = $this->vis->visibleQuery($viewer)
                ->join('post_saves as s', function ($j) use ($uid) {
                    $j->on('s.post_id', '=', 'p.id')->where('s.user_id', '=', $uid);
                });
            if ($col === 'none') {
                $q->whereNull('s.collection_id');
            } elseif ($col !== null && $col !== '' && ctype_digit((string) $col)) {
                $q->where('s.collection_id', (int) $col);
            }
            return $q->orderByDesc('s.created_at');
        }

        $q = $this->vis->excludeHidden($this->vis->visibleQuery($viewer), $uid);

        switch ($mode) {
            case 'following':
                // اللي بتتابعهم بس — منشوراتك إنت ليها تبويب "منشوراتي" (قبل كده كانت داخلة هنا فمن غير متابَعين التبويب كان بيعرض منشوراتك).
                return $q->where('p.user_id', '!=', $uid)
                    ->whereRaw('EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = p.user_id)', [$uid])
                    ->orderByDesc('p.published_at')->orderByDesc('p.id');

            case 'university':
                if (!$viewer['university_id']) {
                    return null;
                }
                return $q->where('p.university_id', $viewer['university_id'])->orderByDesc('p.published_at')->orderByDesc('p.id');

            case 'trending':
                // نافذة 7 أيام (الـ HOT نفسه بيخصم القديم)، ولو أقل من 10 منشورات نوسّعها لـ 30 يوم عشان التبويب ميبقاش فاضي في الجامعات الصغيرة.
                $days = (clone $q)->where('p.published_at', '>=', now()->subDays(7))->limit(10)->get(['p.id'])->count() >= 10 ? 7 : 30;
                return $q->where('p.published_at', '>=', now()->subDays($days))->orderByRaw(self::HOT . ' DESC')->orderByDesc('p.id');

            case 'tag':
                $tag = mb_strtolower(ltrim(trim((string) ($opts['tag'] ?? '')), '#'));
                if ($tag === '') {
                    return null;
                }
                return $q->whereRaw('EXISTS (SELECT 1 FROM post_hashtags h WHERE h.post_id = p.id AND h.tag = ?)', [$tag])
                    ->orderByDesc('p.published_at')->orderByDesc('p.id');

            case 'search':
                $term = trim((string) ($opts['q'] ?? ''));
                if (mb_strlen($term) < 2) {
                    return null;
                }
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
                $tag = mb_strtolower(ltrim($term, '#'));
                return $q->where(function ($w) use ($like, $tag) {
                    $w->where('p.body', 'like', $like)->orWhere('p.title', 'like', $like)->orWhere('u.full_name', 'like', $like)
                        ->orWhereRaw('EXISTS (SELECT 1 FROM post_hashtags h WHERE h.post_id = p.id AND h.tag = ?)', [$tag]);
                })->orderByDesc('p.published_at')->orderByDesc('p.id');

            default: // latest
                return $q->orderByDesc('p.published_at')->orderByDesc('p.id');
        }
    }

    // ---------------------------------------------------------------- for_you

    /** @return array{0:array,1:bool} [rows بالترتيب, has_more] */
    private function forYou(array $viewer, int $page, int $per, bool $refresh): array
    {
        $uid = (int) $viewer['id'];
        $key = "posts:feed:{$uid}";
        $ids = $refresh ? null : Cache::get($key);
        if (!is_array($ids)) {
            $ids = $this->rankIds($viewer);
            Cache::put($key, $ids, (int) config('posts.feed.cache_seconds', 90));
        }
        $slice = array_slice($ids, ($page - 1) * $per, $per);
        $hasMore = count($ids) > $page * $per;
        if (!$slice) {
            return [[], false];
        }
        $rows = $this->vis->visibleQuery($viewer)->whereIn('p.id', $slice)->get(['p.*'])->keyBy('id');
        $ordered = [];
        foreach ($slice as $id) {
            if (isset($rows[$id])) { // اتشال/اتحجب بين وقت الترتيب ودلوقتي => يتخطّى
                $ordered[] = $rows[$id];
            }
        }
        return [$ordered, $hasMore];
    }

    /** @return int[] ids مرتّبة */
    private function rankIds(array $viewer): array
    {
        $uid = (int) $viewer['id'];
        $w = PostSettings::rankingWeights();
        $limit = (int) PostSettings::get('candidate_limit');
        $since = now()->subDays((int) PostSettings::get('candidate_days'));

        $base = fn () => $this->vis->excludeHidden($this->vis->visibleQuery($viewer), $uid)->where('p.published_at', '>=', $since);
        $cands = [];
        foreach ($base()->orderByDesc('p.published_at')->limit($limit)->get(self::CANDIDATE_COLS) as $r) {
            $cands[(int) $r->id] = $r;
        }
        foreach ($base()->orderByRaw(self::HOT . ' DESC')->limit(max(30, (int) ($limit / 3)))->get(self::CANDIDATE_COLS) as $r) {
            $cands[(int) $r->id] = $r;
        }
        // منشورات اللي بتتابعهم مضمونة في المرشحين (مش بتتزاحم مع باقي الجامعة في آخر N منشور)
        foreach ($base()->whereRaw('EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = p.user_id)', [$uid])
            ->orderByDesc('p.published_at')->limit(max(60, (int) ($limit / 2)))->get(self::CANDIDATE_COLS) as $r) {
            $cands[(int) $r->id] = $r;
        }
        // مرشحين قليلين (جامعة صغيرة / محتوى قديم) => وسّع النافذة بدل ما الفيد يطلع فاضي أو يتكرر بسرعة
        if (count($cands) < 40) {
            $wide = fn () => $this->vis->excludeHidden($this->vis->visibleQuery($viewer), $uid);
            foreach ($wide()->orderByDesc('p.published_at')->limit($limit)->get(self::CANDIDATE_COLS) as $r) {
                $cands[(int) $r->id] = $r;
            }
        }
        if (!$cands) {
            return [];
        }

        $postIds = array_keys($cands);
        $authorIds = array_values(array_unique(array_map(fn ($r) => (int) $r->user_id, $cands)));

        // ---- affinity
        $follow = array_flip(DB::table('post_follows')->where('follower_id', $uid)->whereIn('followee_id', $authorIds)->pluck('followee_id')->all());
        $contact = array_flip($this->posts->contactSet($uid, $authorIds));
        $since30 = now()->subDays(30);
        $inter = [];
        $add = function ($rows, int $mult) use (&$inter) {
            foreach ($rows as $a => $c) {
                $inter[(int) $a] = ($inter[(int) $a] ?? 0) + $mult * (int) $c;
            }
        };
        $add(DB::table('post_reactions as r')->join('posts as pp', 'pp.id', '=', 'r.post_id')->where('r.user_id', $uid)->where('r.created_at', '>=', $since30)
            ->whereIn('pp.user_id', $authorIds)->groupBy('pp.user_id')->selectRaw('pp.user_id as a, COUNT(*) as c')->pluck('c', 'a'), 1);
        $add(DB::table('post_comments as c')->join('posts as pp', 'pp.id', '=', 'c.post_id')->where('c.user_id', $uid)->where('c.created_at', '>=', $since30)
            ->whereIn('pp.user_id', $authorIds)->groupBy('pp.user_id')->selectRaw('pp.user_id as a, COUNT(*) as c')->pluck('c', 'a'), 2);
        $add(DB::table('post_saves as s')->join('posts as pp', 'pp.id', '=', 's.post_id')->where('s.user_id', $uid)->where('s.created_at', '>=', $since30)
            ->whereIn('pp.user_id', $authorIds)->groupBy('pp.user_id')->selectRaw('pp.user_id as a, COUNT(*) as c')->pluck('c', 'a'), 2);
        $add(DB::table('post_views as v')->join('posts as pp', 'pp.id', '=', 'v.post_id')->where('v.viewer_id', $uid)->where('v.last_viewed_at', '>=', $since30)->where('v.dwell_ms', '>=', 4000)
            ->whereIn('pp.user_id', $authorIds)->groupBy('pp.user_id')->selectRaw('pp.user_id as a, COUNT(*) as c')->pluck('c', 'a'), 1);

        // ---- interests
        $interests = DB::table('user_post_interests')->where('user_id', $uid)->orderByDesc('weight')->limit(60)->pluck('weight', 'tag')->all();
        $maxW = $interests ? max($interests) : 0;
        $tagsBy = [];
        if ($interests) {
            foreach (DB::table('post_hashtags')->whereIn('post_id', $postIds)->whereIn('tag', array_keys($interests))->get() as $t) {
                $tagsBy[(int) $t->post_id][] = $t->tag;
            }
        }

        // ---- seen
        $seen = array_flip(DB::table('post_views')->where('viewer_id', $uid)->whereIn('post_id', $postIds)->pluck('post_id')->all());

        $half = max(1.0, $w['half_life_hours']);
        $scored = [];
        foreach ($cands as $id => $r) {
            $author = (int) $r->user_id;
            $isOwn = $author === $uid;
            $ageH = max(0.0, (time() - strtotime((string) $r->published_at)) / 3600);
            $recency = pow(0.5, $ageH / $half);

            $affinity = $isOwn ? 0.0 : min(1.0,
                (isset($follow[$author]) ? 0.5 : 0.0)
                + 0.35 * min(1.0, log(1 + ($inter[$author] ?? 0)) / log(16))
                + (isset($contact[$author]) ? 0.15 : 0.0));

            $eng = (int) $r->reactions_count + 3 * (int) $r->comments_count + 4 * (int) $r->reposts_count + 3 * (int) $r->saves_count + 2 * (int) $r->shares_count;
            $rate = $eng / max(20, (int) $r->views_count);
            $velocity = $eng / pow($ageH + 2, 0.8); // تفاعل لكل ساعة (بيخصم العمر) => منشور جديد بيطلع بسرعة يعلى على قديم كبير
            $engagement = 0.4 * min(1.0, log(1 + $eng) / log(61)) + 0.3 * min(1.0, $rate / 0.15) + 0.3 * min(1.0, log(1 + $velocity) / log(9));

            $interest = 0.0;
            if ($maxW > 0 && !empty($tagsBy[$id])) {
                $sum = 0.0;
                foreach ($tagsBy[$id] as $tag) {
                    $sum += ((float) $interests[$tag]) / $maxW;
                }
                $interest = min(1.0, $sum / 2);
            }

            $network = 0.0;
            if ($viewer['university_id'] && (int) $r->university_id === (int) $viewer['university_id']) {
                $network += 0.4;
            }
            if ($viewer['faculty_id'] && (int) $r->faculty_id === (int) $viewer['faculty_id']) {
                $network += 0.4;
            }
            if (in_array($r->visibility, ['university', 'faculty'], true)) {
                $network += 0.2;
            }
            $network = min(1.0, $network);

            $score = $w['w_recency'] * $recency
                + $w['w_affinity'] * $affinity
                + $w['w_engagement'] * $engagement
                + $w['w_interest'] * $interest
                + $w['w_network'] * $network
                + $w['w_media'] * (self::MEDIA_BOOST[$r->kind] ?? 0.0)
                + ($isOwn ? $w['own_post_boost'] * $recency : 0.0);
            if (isset($seen[$id])) {
                $score *= (1 - $w['seen_penalty']);
            }
            $scored[$id] = ['score' => $score, 'author' => $author];
        }

        return $this->diversify($scored, $w['diversity_penalty']);
    }

    /** إعادة ترتيب جشعة: اللي نفس كاتبه ظهر في آخر 4 منشورات بياخد خصم متراكم. */
    private function diversify(array $scored, float $penalty): array
    {
        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $pool = $scored;
        $out = [];
        $recent = [];
        while ($pool) {
            $bestId = null;
            $best = -INF;
            $i = 0;
            foreach ($pool as $id => $s) {
                if ($i++ >= 30) {
                    break;
                }
                $dupes = count(array_filter($recent, fn ($a) => $a === $s['author']));
                $adj = $s['score'] * pow(1 - $penalty, $dupes);
                if ($adj > $best) {
                    $best = $adj;
                    $bestId = $id;
                }
            }
            $out[] = $bestId;
            $recent[] = $pool[$bestId]['author'];
            $recent = array_slice($recent, -4);
            unset($pool[$bestId]);
        }
        return $out;
    }

    // ---------------------------------------------------------------- tags

    /** الوسوم الرائجة (آخر 7 أيام) على المنشورات المرئية للمستخدم. */
    public function trendingTags(int $uid, int $limit = 12): array
    {
        $viewer = $this->vis->viewer($uid);
        $rows = $this->vis->visibleQuery($viewer)
            ->join('post_hashtags as h', 'h.post_id', '=', 'p.id')
            ->where('p.published_at', '>=', now()->subDays(7))
            ->groupBy('h.tag')
            ->selectRaw('h.tag, COUNT(DISTINCT p.id) as posts, SUM(p.reactions_count + 3 * p.comments_count) as engagement')
            ->orderByRaw('(COUNT(DISTINCT p.id) + SUM(p.reactions_count + 3 * p.comments_count) / 10) DESC')
            ->limit(max(1, min(30, $limit)))->get();
        return $rows->map(fn ($r) => ['tag' => $r->tag, 'posts' => (int) $r->posts])->all();
    }

    /** اقتراح وسوم أثناء الكتابة (prefix). */
    public function suggestTags(int $uid, string $prefix): array
    {
        $prefix = mb_strtolower(ltrim(trim($prefix), '#'));
        if ($prefix === '') {
            return [];
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
        return $this->vis->visibleQuery($this->vis->viewer($uid))
            ->join('post_hashtags as h', 'h.post_id', '=', 'p.id')->where('h.tag', 'like', $like)
            ->groupBy('h.tag')->selectRaw('h.tag, COUNT(DISTINCT p.id) as posts')->orderByDesc('posts')->limit(8)->get()
            ->map(fn ($r) => ['tag' => $r->tag, 'posts' => (int) $r->posts])->all();
    }
}
