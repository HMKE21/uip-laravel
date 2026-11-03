<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * قواعد "مين يشوف إيه" للمنشورات — مكان واحد بيستخدمه الفيد والتعليقات والميديا والتفاعل، فمفيش مسار بيتجاوز الفحص.
 *
 * صاحب المنشور بيشوف منشوراته. غيره بيشوفها لو: مفيش حظر بينهم (أي اتجاه) + الجمهور يسمح:
 *   public     أي مستخدم نشط
 *   followers  اللي بيتابعوا صاحب المنشور (post_follows)
 *   contacts   ناس بينهم وبين صاحب المنشور محادثة مباشرة (نفس تعريف الاستوري)
 *   connections اتصالاته المقبولة (user_connections — صفحة الشبكة)
 *   university نفس جامعة صاحب المنشور وقت النشر
 *   faculty    نفس كلية صاحب المنشور وقت النشر
 *   selected   المختارين بس (post_audience)
 *   private    صاحبه بس
 * + لو صاحب المنشور قافل بروفايله: بس أصدقاؤه ومتابعوه (حتى لو المنشور عام).
 * ولازم المنشور يكون published ووقته وصل ومخلصش expires_at، وصاحبه حساب نشط ومش محذوف.
 */
class PostVisibility
{
    private const NOT_BLOCKED = 'NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id = ? AND b.blocked_user_id = p.user_id)
                    OR (b.blocker_user_id = p.user_id AND b.blocked_user_id = ?))';
    private const FOLLOWS = 'EXISTS (SELECT 1 FROM post_follows f WHERE f.follower_id = ? AND f.followee_id = p.user_id)';
    private const CONNECTED = 'EXISTS (SELECT 1 FROM user_connections k WHERE k.status = \'accepted\' AND ((k.requester_id = ? AND k.addressee_id = p.user_id) OR (k.addressee_id = ? AND k.requester_id = p.user_id)))';
    private const CONTACT = 'EXISTS (SELECT 1 FROM conversations c
                    JOIN conversation_participants pa ON pa.conversation_id = c.id AND pa.user_id = ?
                    JOIN conversation_participants pb ON pb.conversation_id = c.id AND pb.user_id = p.user_id
                    WHERE c.is_group = 0)';
    private const IN_AUDIENCE = 'EXISTS (SELECT 1 FROM post_audience a WHERE a.post_id = p.id AND a.user_id = ?)';

    public function __construct(private ChatSafetyService $safety)
    {
    }

    /** @return array{id:int,university_id:?int,faculty_id:?int,is_moderator:bool} */
    public function viewer(int $uid): array
    {
        $aff = $this->safety->affiliationFor($uid);
        $moderator = (bool) DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $uid)
            ->where('r.slug', 'admin')
            ->exists();
        return [
            'id'            => $uid,
            'university_id' => $aff['university_id'] ?? null,
            'faculty_id'    => $aff['faculty_id'] ?? null,
            'is_moderator'  => $moderator,
        ];
    }

    /** استعلام على posts as p (+ users as u) فيه كل شروط الرؤية. الكولر بيضيف select / order / limit. */
    public function visibleQuery(array $viewer): Builder
    {
        $uid = (int) $viewer['id'];
        $uni = $viewer['university_id'] ?? null;
        $fac = $viewer['faculty_id'] ?? null;

        return DB::table('posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNull('p.deleted_at')
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->where('p.status', 'published')
            ->where('p.published_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('p.expires_at')->orWhere('p.expires_at', '>', now()))
            ->where(function ($w) use ($uid, $uni, $fac) {
                $w->where('p.user_id', $uid)->orWhere(function ($x) use ($uid, $uni, $fac) {
                    // بروفايل مقفول: غير الأصدقاء/المتابعين مايشوفوش أي منشور (حتى العام) — ProfilePrivacyService.
                    $x->whereRaw(self::NOT_BLOCKED, [$uid, $uid])->whereRaw(ProfilePrivacyService::SQL_NOT_LOCKED_OUT, [$uid, $uid, $uid])->where(function ($a) use ($uid, $uni, $fac) {
                        $a->where('p.visibility', 'public')
                            ->orWhere(fn ($c) => $c->where('p.visibility', 'followers')->whereRaw(self::FOLLOWS, [$uid]))
                            ->orWhere(fn ($c) => $c->where('p.visibility', 'contacts')->whereRaw(self::CONTACT, [$uid]))
                            ->orWhere(fn ($c) => $c->where('p.visibility', 'connections')->whereRaw(self::CONNECTED, [$uid, $uid]))
                            ->orWhere(fn ($c) => $c->where('p.visibility', 'selected')->whereRaw(self::IN_AUDIENCE, [$uid]));
                        if ($uni) {
                            $a->orWhere(fn ($c) => $c->where('p.visibility', 'university')->where('p.university_id', $uni));
                        }
                        if ($fac) {
                            $a->orWhere(fn ($c) => $c->where('p.visibility', 'faculty')->where('p.faculty_id', $fac));
                        }
                    });
                });
            });
    }

    /** يشيل اللي المستخدم أخفاه ("مش مهتم") + منشورات اللي كتمهم — للفيد بس، مش للبروفايل الشخصي ولا الصفحة المباشرة. */
    public function excludeHidden(Builder $q, int $uid): Builder
    {
        return $q
            ->whereRaw('NOT EXISTS (SELECT 1 FROM post_hides h WHERE h.user_id = ? AND h.post_id = p.id)', [$uid])
            ->whereRaw('NOT EXISTS (SELECT 1 FROM post_author_mutes m WHERE m.user_id = ? AND m.muted_user_id = p.user_id)', [$uid]);
    }

    /**
     * منشور واحد لو المستخدم يقدر يشوفه، وإلا null.
     * صاحبه بيشوفه بأي حالة (ماعدا removed)، والأدمن (مودريتور) بيشوف أي حاجة للمراجعة.
     */
    public function find(array $viewer, int $postId): ?object
    {
        $uid = (int) $viewer['id'];
        $own = DB::table('posts')->where('id', $postId)->whereNull('deleted_at')->first();
        if (!$own) {
            return null;
        }
        if ((int) $own->user_id === $uid && $own->status !== 'removed') {
            return $own;
        }
        if (!empty($viewer['is_moderator'])) {
            return $own;
        }
        $row = $this->visibleQuery($viewer)->where('p.id', $postId)->first(['p.*']);
        return $row ?: null;
    }
}
