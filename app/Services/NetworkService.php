<?php

namespace App\Services;

use App\Repositories\NotificationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "الشبكة" — إدارة الاتصالات وطلبات الصداقة/المتابعة والبحث عن الناس، مربوطة بالمنشورات (post_follows + visibility=connections)
 * والمراسلات (can_message / فتح محادثة مباشرة من الواجهة).
 *
 * المفاهيم:
 *  - اتصال (Connection): متبادل ويحتاج قبول — زي LinkedIn. القبول بيعمل متابعة متبادلة تلقائيًا (فيد "المتابَعون" يشتغل).
 *  - متابعة (Follow): من طرف واحد؛ لو صاحب الحساب فعّل follow_approval بتبقى طلب لازم يوافق عليه.
 *  - الحظر بيشيل كل شيء بين الطرفين (purgeBetween).
 * كل خطأ بيتحوّل لـ RuntimeException برسالة آمنة للعرض (الكنترولر بيحوّلها 400/404).
 */
class NetworkService
{
    public function __construct(
        private PostFollowService $follows,
        private PostNotifier $notifier,
        private NotificationService $notifications,
        private ChatSafetyService $safety,
        private ChatSettingsService $chat,
        private MessagingService $messaging,
        private RoleService $roles,
        private ProfilePrivacyService $privacy
    ) {
    }

    // ---------------------------------------------------------------- summary / settings

    public function summary(int $uid): array
    {
        return [
            'connections'       => $this->connectedCount($uid),
            'followers'         => (int) DB::table('post_follows')->where('followee_id', $uid)->count(),
            'following'         => (int) DB::table('post_follows')->where('follower_id', $uid)->count(),
            'requests_received' => (int) DB::table('user_connections')->where('addressee_id', $uid)->where('status', 'pending')->count(),
            'requests_sent'     => (int) DB::table('user_connections')->where('requester_id', $uid)->where('status', 'pending')->count(),
            'follow_requests'   => (int) DB::table('post_follow_requests')->where('followee_id', $uid)->count(),
            'settings'          => $this->settings($uid),
        ];
    }

    public function settings(int $uid): array
    {
        $r = DB::table('user_network_settings')->where('user_id', $uid)->first();
        return [
            'follow_approval'            => $r ? (bool) $r->follow_approval : false,
            'accept_connection_requests' => $r ? (bool) $r->accept_connection_requests : true,
            'profile_locked'             => $this->privacy->isLocked($uid),
            'lists_visibility'           => $this->privacy->forUser($uid)['lists'],
            'photo_download'             => $this->privacy->forUser($uid)['download'],
        ];
    }

    public function saveSettings(int $uid, array $in): array
    {
        $cur = $this->settings($uid);
        foreach (['follow_approval', 'accept_connection_requests'] as $k) {
            if (array_key_exists($k, $in)) {
                $cur[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('profile_locked', $in)) {
            $cur['profile_locked'] = filter_var($in['profile_locked'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($in['lists_visibility']) && in_array($in['lists_visibility'], ProfilePrivacyService::LISTS, true)) {
            $cur['lists_visibility'] = $in['lists_visibility'];
        }
        if (isset($in['photo_download']) && in_array($in['photo_download'], ProfilePrivacyService::DOWNLOAD, true)) {
            $cur['photo_download'] = $in['photo_download'];
        }
        DB::table('user_network_settings')->updateOrInsert(['user_id' => $uid], [
            'follow_approval'            => $cur['follow_approval'] ? 1 : 0,
            'accept_connection_requests' => $cur['accept_connection_requests'] ? 1 : 0,
            'profile_locked'             => $cur['profile_locked'] ? 1 : 0,
            'lists_visibility'           => $cur['lists_visibility'],
            'photo_download'             => $cur['photo_download'],
            'updated_at'                 => now(),
        ]);
        $this->privacy->forget($uid);
        // لو قفل الموافقة اليدوية: كل الطلبات المعلقة تتقبل (مش منطقي تفضل معلقة).
        if (!$cur['follow_approval'] && !$cur['profile_locked']) {
            $pending = DB::table('post_follow_requests')->where('followee_id', $uid)->pluck('follower_id')->all();
            foreach ($pending as $fid) {
                $this->follows->acceptRequest($uid, (int) $fid, false);
            }
        }
        return $cur;
    }

    // ---------------------------------------------------------------- lists

    /** @return array{items:array,has_more:bool} */
    public function connections(int $uid, string $q, int $page, int $per): array
    {
        $base = DB::table('user_connections as c')
            ->join('users as u', 'u.id', '=', DB::raw('(CASE WHEN c.requester_id = ' . (int) $uid . ' THEN c.addressee_id ELSE c.requester_id END)'))
            ->where('c.status', 'accepted')
            ->where(fn ($w) => $w->where('c.requester_id', $uid)->orWhere('c.addressee_id', $uid));
        return $this->page($uid, $base, 'COALESCE(c.responded_at, c.created_at)', $q, $page, $per);
    }

    /** direction: received | sent */
    public function requests(int $uid, string $direction, int $page, int $per): array
    {
        $recv = $direction !== 'sent';
        $base = DB::table('user_connections as c')
            ->join('users as u', 'u.id', '=', $recv ? 'c.requester_id' : 'c.addressee_id')
            ->where('c.status', 'pending')
            ->where($recv ? 'c.addressee_id' : 'c.requester_id', $uid);
        return $this->page($uid, $base, 'c.created_at', '', $page, $per, ['c.id as request_id', 'c.message as message']);
    }

    /** طلبات متابعة معلّقة وصلتني (لما حسابي بموافقة). */
    public function followRequests(int $uid, int $page, int $per): array
    {
        $base = DB::table('post_follow_requests as r')->join('users as u', 'u.id', '=', 'r.follower_id')->where('r.followee_id', $uid);
        return $this->page($uid, $base, 'r.created_at', '', $page, $per);
    }

    public function followers(int $uid, string $q, int $page, int $per): array
    {
        $base = DB::table('post_follows as f')->join('users as u', 'u.id', '=', 'f.follower_id')->where('f.followee_id', $uid);
        return $this->page($uid, $base, 'f.created_at', $q, $page, $per);
    }

    public function following(int $uid, string $q, int $page, int $per): array
    {
        $base = DB::table('post_follows as f')->join('users as u', 'u.id', '=', 'f.followee_id')->where('f.follower_id', $uid);
        return $this->page($uid, $base, 'f.created_at', $q, $page, $per);
    }

    // ---------------------------------------------------------------- search / suggestions / profile

    /**
     * بحث عن ناس بالاسم. filters: role، same_university (bool)، relation: all|connections|following|followers.
     * اللي قافل "أظهر في البحث" (chat discoverable) مبيظهرش إلا لجهات اتصاله أو اتصالاته.
     */
    public function search(int $uid, string $q, array $filters, int $page, int $per): array
    {
        $q = trim($q);
        $role = isset($filters['role']) ? trim((string) $filters['role']) : '';
        $rel = (string) ($filters['relation'] ?? 'all');
        $sameUni = !empty($filters['same_university']);
        if (mb_strlen($q) < 2 && $role === '' && !$sameUni && $rel === 'all') {
            return ['items' => [], 'has_more' => false];
        }
        $per = max(1, min(30, $per));
        $query = DB::table('users as u')
            ->leftJoin('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('universities as uni', 'uni.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at')->where('u.status', 'active')->where('u.id', '!=', $uid)
            ->whereRaw($this->notBlockedSql(), [$uid, $uid]);
        if ($q !== '') {
            $query->where('u.full_name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }
        if ($role !== '') {
            $query->where('r.slug', $role);
        }
        if ($sameUni) {
            $uniId = $this->safety->affiliationFor($uid)['university_id'];
            if ($uniId) {
                $query->whereRaw($this->sameUniversitySql(), [$uniId, $uniId, $uniId, $uniId, $uniId]);
            }
        }
        if ($rel === 'connections') {
            $query->whereRaw('EXISTS (SELECT 1 FROM user_connections k WHERE k.status = \'accepted\' AND ((k.requester_id = ? AND k.addressee_id = u.id) OR (k.addressee_id = ? AND k.requester_id = u.id)))', [$uid, $uid]);
        } elseif ($rel === 'following') {
            $query->whereRaw('EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = u.id)', [$uid]);
        } elseif ($rel === 'followers') {
            $query->whereRaw('EXISTS (SELECT 1 FROM post_follows f WHERE f.followee_id = ? AND f.follower_id = u.id)', [$uid]);
        }
        $rows = $query->selectRaw('u.id, u.full_name, u.avatar_path, MIN(r.slug) AS role_slug, MIN(COALESCE(uni.official_name_en, s.faculty)) AS org_en, MIN(COALESCE(uni.official_name_ar, s.faculty)) AS org_ar')
            ->groupBy('u.id', 'u.full_name', 'u.avatar_path')
            ->orderBy('u.full_name')->offset(($page - 1) * $per)->limit($per + 1)->get()->all();
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $rows = array_values(array_filter($rows, fn ($r) => $this->chat->discoverable((int) $r->id) || $this->chat->isContact((int) $r->id, $uid) || $this->isConnected($uid, (int) $r->id)));
        return ['items' => $this->shape($uid, $rows), 'has_more' => $hasMore];
    }

    /** "أشخاص قد تعرفهم": أصدقاء الأصدقاء (بعدد المشترَكين) ثم نفس الجامعة. */
    public function suggestions(int $uid, int $limit = 12): array
    {
        $limit = max(1, min(30, $limit));
        $mine = $this->connectedIds($uid);
        $skip = array_merge([$uid], $mine, $this->pendingIds($uid), $this->blockedEitherWay($uid));
        $ids = [];

        if ($mine) {
            $a = DB::table('user_connections')->where('status', 'accepted')->whereIn('requester_id', $mine)->pluck('addressee_id')->all();
            $b = DB::table('user_connections')->where('status', 'accepted')->whereIn('addressee_id', $mine)->pluck('requester_id')->all();
            $count = array_count_values(array_map('intval', array_merge($a, $b)));
            foreach ($skip as $s) {
                unset($count[(int) $s]);
            }
            arsort($count);
            $ids = array_slice(array_keys($count), 0, $limit);
        }
        if (count($ids) < $limit) {
            $uniId = $this->safety->affiliationFor($uid)['university_id'];
            if ($uniId) {
                $extra = DB::table('users as u')->whereNull('u.deleted_at')->where('u.status', 'active')
                    ->whereNotIn('u.id', array_merge($skip, $ids))
                    ->whereRaw($this->sameUniversitySql(), [$uniId, $uniId, $uniId, $uniId, $uniId])
                    ->whereRaw('NOT EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = u.id)', [$uid])
                    ->orderByDesc('u.id')->limit(($limit - count($ids)) * 3)->pluck('u.id')->all();
                foreach ($extra as $id) {
                    if (count($ids) >= $limit) {
                        break;
                    }
                    if ($this->chat->discoverable((int) $id)) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }
        if (!$ids) {
            return [];
        }
        $rows = DB::table('users as u')->whereIn('u.id', $ids)->whereNull('u.deleted_at')->get(['u.id', 'u.full_name', 'u.avatar_path'])->keyBy('id');
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
            }
        }
        return $this->shape($uid, $ordered);
    }

    /** بروفايل شخص: عدّادات المنشورات والمتابعة والاتصالات + علاقتي بيه + إمكانية المراسلة. */
    public function person(int $uid, int $userId): array
    {
        $base = $this->follows->profile($uid, $userId); // بيتأكد إنه موجود وغير محظور
        $row = DB::table('users')->where('id', $userId)->first(['id', 'full_name', 'avatar_path', 'created_at']);
        $shaped = $this->shape($uid, [$row])[0] ?? [];
        $shaped['posts_count'] = $base['posts_count'];
        $shaped['followers_count'] = $base['followers_count'];
        $shaped['following_count'] = $base['following_count'];
        $shaped['connections_count'] = $this->connectedCount($userId);
        $shaped['university_id'] = $base['university_id'];
        $shaped['faculty_id'] = $base['faculty_id'];
        $shaped['is_muted'] = $base['is_muted'];
        $shaped['locked'] = $base['locked'];
        $shaped['restricted'] = $base['restricted'];
        $shaped['lists_hidden'] = $base['lists_hidden'];
        $shaped['bio'] = $uid === $userId ? ($this->chat->bio($uid) ?: null) : $this->messaging->bioFor($userId, $uid);
        $shaped['bio_style'] = $shaped['bio'] !== null ? $this->chat->bioStyle($userId) : null;
        $shaped['cover_path'] = $this->coverPath($userId);
        $shaped['photo_can_download'] = $this->privacy->canDownloadPhoto($uid, $userId);
        if ($base['restricted']) { // بروفايل مقفول: الاسم والصورة المصغّرة بس
            $shaped['bio'] = null;
            $shaped['bio_style'] = null;
            $shaped['cover_path'] = null;
        }
        if ($base['lists_hidden']) {
            $shaped['connections_count'] = null;
            $shaped['mutual_connections'] = $shaped['mutual_connections'] ?? 0;
        }
        $shaped['joined_at'] = $row->created_at;
        // الجامعة / الكلية بالاسم (كانت ناقصة في الدروار القديم)
        $uni = $base['university_id'] ? DB::table('universities')->where('id', $base['university_id'])->first(['official_name_en', 'official_name_ar']) : null;
        $fac = $base['faculty_id'] ? DB::table('faculties')->where('id', $base['faculty_id'])->first(['name_en', 'name_ar']) : null;
        $shaped['org_en'] = $uni->official_name_en ?? null;
        $shaped['org_ar'] = $uni->official_name_ar ?? null;
        $shaped['faculty_en'] = $fac->name_en ?? null;
        $shaped['faculty_ar'] = $fac->name_ar ?? null;
        return $shaped;
    }

    /** أصدقاء (اتصالات) أي شخص. بيرجّع علاقتي أنا بكل واحد منهم. */
    public function friendsOf(int $uid, int $userId, string $q, int $page, int $per): array
    {
        $this->follows->profile($uid, $userId); // موجود وغير محظور
        if (!$this->privacy->canSeeLists($uid, $userId)) {
            return ['items' => [], 'has_more' => false, 'restricted' => true];
        }
        $base = DB::table('user_connections as c')
            ->join('users as u', 'u.id', '=', DB::raw('(CASE WHEN c.requester_id = ' . (int) $userId . ' THEN c.addressee_id ELSE c.requester_id END)'))
            ->where('c.status', 'accepted')
            ->where(fn ($w) => $w->where('c.requester_id', $userId)->orWhere('c.addressee_id', $userId));
        return $this->page($uid, $base, 'COALESCE(c.responded_at, c.created_at)', $q, $page, $per);
    }

    // ---------------------------------------------------------------- cover photo

    public function coverPath(int $userId): ?string
    {
        try {
            $p = DB::table('user_profile_covers')->where('user_id', $userId)->value('cover_path');
        } catch (\Throwable $e) {
            return null; // الـ migration لسه متشغلتش
        }
        return $p ?: null;
    }

    /** يرفع صورة غلاف جديدة (jpg/png/webp حتى 8MB) ويمسح القديمة. */
    public function setCover(int $uid, $file): string
    {
        $stored = app(FileUploadService::class)->store($file, 'avatars', 'covers/' . $uid, 8192, ['jpg', 'jpeg', 'png', 'webp']);
        $old = $this->coverPath($uid);
        DB::table('user_profile_covers')->updateOrInsert(['user_id' => $uid], ['cover_path' => $stored['stored_path'], 'updated_at' => now()]);
        if ($old && $old !== $stored['stored_path']) {
            app(FileUploadService::class)->delete($old);
        }
        return $stored['stored_path'];
    }

    public function removeCover(int $uid): void
    {
        $old = $this->coverPath($uid);
        DB::table('user_profile_covers')->where('user_id', $uid)->delete();
        if ($old) {
            app(FileUploadService::class)->delete($old);
        }
    }

    // ---------------------------------------------------------------- connection actions

    public function connect(int $uid, int $targetId, ?string $message): array
    {
        if ($uid === $targetId) {
            throw new \RuntimeException('You cannot connect with yourself.');
        }
        $this->assertReachable($uid, $targetId);
        $row = $this->pair($uid, $targetId);

        if ($row && $row->status === 'accepted') {
            return $this->relationFor($uid, $targetId);
        }
        if ($row && $row->status === 'pending') {
            if ((int) $row->addressee_id === $uid) { // هو طالب اتصال معايا → قبول فوري
                $this->accept($uid, (int) $row->id);
            }
            return $this->relationFor($uid, $targetId);
        }
        if (!$this->settings($targetId)['accept_connection_requests']) {
            throw new \RuntimeException('This person is not accepting connection requests.');
        }
        $message = $message !== null ? trim(mb_substr($message, 0, 300)) : null;
        if ($row) { // declined سابقًا (من أي طرف): ممنوع التكرار قبل 30 يوم من رد الرفض
            if ((int) $row->requester_id !== $uid && $row->status === 'declined') {
                DB::table('user_connections')->where('id', $row->id)->delete(); // أنا اللي رفضت طلبه قبل كده؛ مسموح أبدأ من عندي
            } elseif ($row->responded_at && strtotime((string) $row->responded_at) > time() - 30 * 86400) {
                throw new \RuntimeException('You can send another request later.');
            } else {
                DB::table('user_connections')->where('id', $row->id)->delete();
            }
        }
        DB::table('user_connections')->insert([
            'requester_id' => $uid, 'addressee_id' => $targetId, 'status' => 'pending',
            'message' => $message !== '' ? $message : null, 'created_at' => now(),
        ]);
        $this->notify($targetId, $uid, 'connection_request', 'sent you a connection request', $message);
        return $this->relationFor($uid, $targetId);
    }

    public function accept(int $uid, int $requestId): array
    {
        $row = DB::table('user_connections')->where('id', $requestId)->where('addressee_id', $uid)->where('status', 'pending')->first();
        if (!$row) {
            throw new \RuntimeException('Request not found.');
        }
        $other = (int) $row->requester_id;
        DB::table('user_connections')->where('id', $requestId)->update(['status' => 'accepted', 'responded_at' => now()]);
        // اتصال = متابعة متبادلة (الفيد بيشتغل فورًا) ومفيش طلب متابعة معلّق بينهم.
        foreach ([[$uid, $other], [$other, $uid]] as [$a, $b]) {
            DB::table('post_follows')->insertOrIgnore(['follower_id' => $a, 'followee_id' => $b, 'created_at' => now()]);
            DB::table('post_follow_requests')->where('follower_id', $a)->where('followee_id', $b)->delete();
        }
        $this->notify($other, $uid, 'connection_accepted', 'accepted your connection request');
        return $this->relationFor($uid, $other);
    }

    public function decline(int $uid, int $requestId): array
    {
        $row = DB::table('user_connections')->where('id', $requestId)->where('addressee_id', $uid)->where('status', 'pending')->first();
        if (!$row) {
            throw new \RuntimeException('Request not found.');
        }
        DB::table('user_connections')->where('id', $requestId)->update(['status' => 'declined', 'responded_at' => now()]);
        return $this->relationFor($uid, (int) $row->requester_id);
    }

    public function withdraw(int $uid, int $requestId): array
    {
        $row = DB::table('user_connections')->where('id', $requestId)->where('requester_id', $uid)->where('status', 'pending')->first();
        if (!$row) {
            throw new \RuntimeException('Request not found.');
        }
        DB::table('user_connections')->where('id', $requestId)->delete();
        return $this->relationFor($uid, (int) $row->addressee_id);
    }

    public function removeConnection(int $uid, int $userId): array
    {
        DB::table('user_connections')->where('status', 'accepted')->where(function ($w) use ($uid, $userId) {
            $w->where(fn ($x) => $x->where('requester_id', $uid)->where('addressee_id', $userId))
                ->orWhere(fn ($x) => $x->where('requester_id', $userId)->where('addressee_id', $uid));
        })->delete();
        return $this->relationFor($uid, $userId);
    }

    // ---------------------------------------------------------------- follow actions (approval)

    public function acceptFollow(int $uid, int $followerId): array
    {
        $this->follows->acceptRequest($uid, $followerId, true);
        return $this->relationFor($uid, $followerId);
    }

    public function declineFollow(int $uid, int $followerId): array
    {
        DB::table('post_follow_requests')->where('followee_id', $uid)->where('follower_id', $followerId)->delete();
        return $this->relationFor($uid, $followerId);
    }

    public function removeFollower(int $uid, int $followerId): array
    {
        DB::table('post_follows')->where('followee_id', $uid)->where('follower_id', $followerId)->delete();
        return $this->relationFor($uid, $followerId);
    }

    /** بيتنادى من الحظر: كل علاقة بين الطرفين تتشال (اتصالات، طلبات، متابعات). */
    public function purgeBetween(int $a, int $b): void
    {
        DB::table('user_connections')->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('requester_id', $a)->where('addressee_id', $b))->orWhere(fn ($x) => $x->where('requester_id', $b)->where('addressee_id', $a));
        })->delete();
        DB::table('post_follow_requests')->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('follower_id', $a)->where('followee_id', $b))->orWhere(fn ($x) => $x->where('follower_id', $b)->where('followee_id', $a));
        })->delete();
        DB::table('post_follows')->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('follower_id', $a)->where('followee_id', $b))->orWhere(fn ($x) => $x->where('follower_id', $b)->where('followee_id', $a));
        })->delete();
    }

    // ---------------------------------------------------------------- internals

    /** حالة العلاقة بيني وبين شخص (بترجع للواجهة بعد أي إجراء عشان الكارت يتحدّث). */
    public function relationFor(int $uid, int $userId): array
    {
        $shaped = $this->shape($uid, [(object) ['id' => $userId, 'full_name' => '', 'avatar_path' => null]], true)[0] ?? [];
        return array_intersect_key($shaped, array_flip(['id', 'connection', 'connection_id', 'is_following', 'follows_me', 'follow_requested', 'follow_request_incoming', 'mutual_connections', 'can_message']));
    }

    private function pair(int $a, int $b)
    {
        return DB::table('user_connections')->where(function ($w) use ($a, $b) {
            $w->where(fn ($x) => $x->where('requester_id', $a)->where('addressee_id', $b))->orWhere(fn ($x) => $x->where('requester_id', $b)->where('addressee_id', $a));
        })->first();
    }

    private function isConnected(int $a, int $b): bool
    {
        $r = $this->pair($a, $b);
        return $r && $r->status === 'accepted';
    }

    private function connectedCount(int $uid): int
    {
        return (int) DB::table('user_connections')->where('status', 'accepted')->where(fn ($w) => $w->where('requester_id', $uid)->orWhere('addressee_id', $uid))->count();
    }

    /** @return int[] */
    private function connectedIds(int $uid): array
    {
        $a = DB::table('user_connections')->where('status', 'accepted')->where('requester_id', $uid)->pluck('addressee_id')->all();
        $b = DB::table('user_connections')->where('status', 'accepted')->where('addressee_id', $uid)->pluck('requester_id')->all();
        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }

    /** @return int[] */
    private function pendingIds(int $uid): array
    {
        $a = DB::table('user_connections')->where('status', 'pending')->where('requester_id', $uid)->pluck('addressee_id')->all();
        $b = DB::table('user_connections')->where('status', 'pending')->where('addressee_id', $uid)->pluck('requester_id')->all();
        return array_map('intval', array_merge($a, $b));
    }

    /** @return int[] */
    private function blockedEitherWay(int $uid): array
    {
        $a = DB::table('user_blocks')->where('blocker_user_id', $uid)->pluck('blocked_user_id')->all();
        $b = DB::table('user_blocks')->where('blocked_user_id', $uid)->pluck('blocker_user_id')->all();
        return array_map('intval', array_merge($a, $b));
    }

    private function notBlockedSql(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = u.id) OR (b.blocker_user_id = u.id AND b.blocked_user_id = ?))';
    }

    private function sameUniversitySql(): string
    {
        return 'u.id IN (SELECT user_id FROM students WHERE university_id = ? UNION SELECT user_id FROM academic_staff WHERE university_id = ? UNION SELECT user_id FROM supervisors WHERE university_id = ? UNION SELECT user_id FROM universities WHERE id = ? UNION SELECT user_id FROM faculties WHERE university_id = ?)';
    }

    private function assertReachable(int $uid, int $targetId): void
    {
        $ok = DB::table('users')->where('id', $targetId)->whereNull('deleted_at')->where('status', 'active')->exists();
        if (!$ok || $this->notifier->blocked($uid, $targetId)) {
            throw new \RuntimeException('User not found.');
        }
    }

    /**
     * يطبّق فلاتر المستخدم (نشط/مش محذوف/مش محظور/اسم) وترتيب بأحدث وقت وترقيم بصفحات، ثم يشكّل الناس.
     * $extra: أعمدة إضافية تتنسخ على كل شخص (request_id, message) + at = وقت العلاقة.
     *
     * @return array{items:array,has_more:bool}
     */
    private function page(int $uid, $base, string $atExpr, string $q, int $page, int $per, array $extra = []): array
    {
        $per = max(1, min(50, $per));
        $base->whereNull('u.deleted_at')->where('u.status', 'active')->whereRaw($this->notBlockedSql(), [$uid, $uid]);
        if (($q = trim($q)) !== '') {
            $base->where('u.full_name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }
        $rows = $base->selectRaw('u.id, u.full_name, u.avatar_path, ' . $atExpr . ' AS rel_at' . ($extra ? ', ' . implode(', ', $extra) : ''))
            ->orderByRaw($atExpr . ' DESC')->offset(($page - 1) * $per)->limit($per + 1)->get()->all();
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        return ['items' => $this->shape($uid, $rows), 'has_more' => $hasMore];
    }

    /**
     * يحوّل صفوف users لعناصر واجهة بعلاقتي بكل واحد (علاقات الاتصال/المتابعة بـ استعلامات bulk؛ can_message بيمرّ على إعدادات الخصوصية لكل شخص).
     *
     * @param array<int,object> $rows
     * @return array<int,array>
     */
    private function shape(int $uid, array $rows, bool $relationOnly = false): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        if (!$ids) {
            return [];
        }
        $conn = [];
        $cq = DB::table('user_connections')->whereIn('status', ['pending', 'accepted'])->where(function ($w) use ($uid, $ids) {
            $w->where(fn ($x) => $x->where('requester_id', $uid)->whereIn('addressee_id', $ids))
                ->orWhere(fn ($x) => $x->where('addressee_id', $uid)->whereIn('requester_id', $ids));
        })->get();
        foreach ($cq as $c) {
            $other = (int) $c->requester_id === $uid ? (int) $c->addressee_id : (int) $c->requester_id;
            $conn[$other] = ['state' => $c->status === 'accepted' ? 'connected' : ((int) $c->requester_id === $uid ? 'pending_sent' : 'pending_received'), 'id' => (int) $c->id];
        }
        $following = array_flip(array_map('intval', DB::table('post_follows')->where('follower_id', $uid)->whereIn('followee_id', $ids)->pluck('followee_id')->all()));
        $followsMe = array_flip(array_map('intval', DB::table('post_follows')->where('followee_id', $uid)->whereIn('follower_id', $ids)->pluck('follower_id')->all()));
        $requested = array_flip(array_map('intval', DB::table('post_follow_requests')->where('follower_id', $uid)->whereIn('followee_id', $ids)->pluck('followee_id')->all()));
        $incoming = array_flip(array_map('intval', DB::table('post_follow_requests')->where('followee_id', $uid)->whereIn('follower_id', $ids)->pluck('follower_id')->all()));
        $mutual = $this->mutualCounts($uid, $ids);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $p = [
                'id'                      => $id,
                'connection'              => $conn[$id]['state'] ?? 'none',
                'connection_id'           => $conn[$id]['id'] ?? null,
                'is_following'            => isset($following[$id]),
                'follows_me'              => isset($followsMe[$id]),
                'follow_requested'        => isset($requested[$id]),
                'follow_request_incoming' => isset($incoming[$id]),
                'mutual_connections'      => $mutual[$id] ?? 0,
                'can_message'             => $id === $uid ? false : (bool) $this->chat->reach($id, $uid, 'messages')['ok'],
            ];
            if (!$relationOnly) {
                $p += [
                    'full_name'   => $r->full_name,
                    'avatar_path' => $this->messaging->isAvatarHidden($id, $uid) ? null : $r->avatar_path,
                    'role'        => $r->role_slug ?? $this->roles->primaryRoleFor($id),
                    'org_en'      => $r->org_en ?? null,
                    'org_ar'      => $r->org_ar ?? null,
                    'is_me'       => $id === $uid,
                ];
                if (property_exists($r, 'rel_at')) {
                    $p['at'] = $r->rel_at;
                }
                foreach (['request_id', 'message'] as $k) {
                    if (property_exists($r, $k)) {
                        $p[$k] = $r->{$k};
                    }
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    /** @return array<int,int> عدد الاتصالات المشتركة بيني وبين كل id */
    private function mutualCounts(int $uid, array $ids): array
    {
        $mine = $this->connectedIds($uid);
        if (!$mine) {
            return [];
        }
        $counts = [];
        $a = DB::table('user_connections')->where('status', 'accepted')->whereIn('requester_id', $ids)->whereIn('addressee_id', $mine)->pluck('requester_id')->all();
        $b = DB::table('user_connections')->where('status', 'accepted')->whereIn('addressee_id', $ids)->whereIn('requester_id', $mine)->pluck('addressee_id')->all();
        foreach (array_merge($a, $b) as $id) {
            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
        }
        return $counts;
    }

    private function notify(int $toId, int $fromId, string $type, string $verb, ?string $snippet = null): void
    {
        try {
            if ($this->notifier->blocked($toId, $fromId)) {
                return;
            }
            $name = (string) DB::table('users')->where('id', $fromId)->value('full_name');
            $portal = NotificationRepository::portalsForRole($this->roles->primaryRoleFor($toId))[0] ?? 'student';
            $tab = $type === 'connection_accepted' ? 'connections' : 'requests';
            $this->notifications->notify($toId, $type, trim($name . ' ' . $verb), $snippet !== null && $snippet !== '' ? mb_substr($snippet, 0, 140) : null, "/{$portal}/network?tab={$tab}");
        } catch (\Throwable $e) {
            Log::debug('network notification failed: ' . $e->getMessage());
        }
    }
}
