<?php

namespace App\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content Safety — التحذير الودّي + اعتراض المستخدم على الحجب.
 *
 *  - warnUser()   : لما حد يحاول يبعت/ينشر محتوى غير لائق (ContentSafetyService::record) بيوصله إشعار ودّي بسيط فيه السبب
 *                   (من غير نص المحتوى) ولينك صفحة الاعتراض. مرة كل 10 دقايق كحد أقصى عشان ميتحولش لإزعاج.
 *  - contactAdmin(): حساب الأدمن اللي بيظهر للمستخدم (CONTENT_SAFETY_CONTACT_ADMIN_ID أو أول أدمن نشط).
 *  - create/reply : المستخدم بيكتب اعتراض (محادثة) بتوصل لكل الأدمنز كإشعار؛ الأدمن بيرد والمستخدم بيوصله إشعار.
 */
class ContentAppealService
{
    public const MAX_OPEN = 3;          // أقصى عدد اعتراضات مفتوحة للمستخدم الواحد
    public const MAX_PER_DAY = 6;       // أقصى عدد اعتراضات جديدة في اليوم
    public const MIN_BODY = 15;
    public const MAX_BODY = 2000;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function available(): bool
    {
        static $memo = null;
        try {
            return $memo ??= Schema::hasTable('content_appeals') && Schema::hasTable('content_appeal_messages');
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ contact + friendly warning

    /** @return array{id:int,uuid:?string,full_name:string,avatar_path:?string}|null */
    public function contactAdmin(): ?array
    {
        return Cache::remember('csafe:contact-admin:' . TenantContext::ns(), 300, function () {
            try {
                $base = fn () => DB::table('users as u')
                    ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->where('r.slug', 'admin')->where('u.status', 'active')->whereNull('u.deleted_at')
                    ->select('u.id', 'u.uuid', 'u.full_name', 'u.avatar_path')->orderBy('u.id');
                $want = (int) config('content_safety.contact_admin_id', 0);
                $row = $want > 0 ? $base()->where('u.id', $want)->first() : null;
                $row ??= $base()->first();

                return $row ? ['id' => (int) $row->id, 'uuid' => $row->uuid, 'full_name' => $row->full_name, 'avatar_path' => $row->avatar_path] : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** اللي بيتضاف لـ errors في كل رد حجب (ContentBlockedException) — الواجهة بتعرض منه نافذة التحذير الودّية. */
    public function publicInfo(): array
    {
        return ['available' => $this->available(), 'admin' => $this->contactAdmin()];
    }

    public function reasonFor(string $category, bool $ar): string
    {
        return match ($category) {
            'gore' => $ar ? 'محتوى عنيف أو مؤذٍ' : 'violent or harmful content',
            'child_safety' => $ar ? 'محتوى يمسّ سلامة الأطفال' : 'content that endangers children',
            'profanity' => $ar ? 'ألفاظ نابية' : 'offensive language',
            'custom' => $ar ? 'كلمات غير مسموح بها في المنصة' : 'words that are not allowed on the platform',
            'blocked_link' => $ar ? 'رابط غير مسموح به' : 'a link that is not allowed',
            default => $ar ? 'محتوى غير لائق' : 'inappropriate content',
        };
    }

    /** إشعار ودّي للمستخدم بعد مخالفة. Never throws. */
    public function warnUser(int $userId, string $category, int $strikes = 1): void
    {
        try {
            if ($userId <= 0 || $category === 'scanner_unavailable') {
                return;
            }
            if (!Cache::add('csafe:warned:' . TenantContext::ns() . $userId, 1, 600)) {
                return;
            }
            $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first(['id', 'full_name', 'preferred_language']);
            if (!$u) {
                return;
            }
            $ar = ($u->preferred_language ?? 'ar') === 'ar';
            $first = trim((string) strtok((string) $u->full_name, ' ')) ?: ($ar ? 'صديقنا' : 'there');
            $reason = $this->reasonFor($category, $ar);
            $admin = $this->contactAdmin();

            if ($ar) {
                $title = 'تنبيه ودّي من فريق المنصة';
                $body = "أهلًا {$first}،\nحاولت إرسال أو نشر محتوى ({$reason}) فلم نتمكن من نشره. نحرص جميعًا على مجتمع محترم وآمن، ونثق أنه مجرد سهو — يكفي تعديل المحتوى وإعادة المحاولة.\n"
                    . ($strikes >= 3 ? "نودّ التنبيه بلطف أن هذا تكرر اليوم، والتكرار قد يستدعي مراجعة حسابك.\n" : '')
                    . 'إن كنت ترى أن الحجب خاطئ، يمكنك مراسلة الإدارة مباشرة من صفحة الاعتراض'
                    . ($admin ? " (حساب الإدارة: {$admin['full_name']})" : '') . ' وسنرد عليك في أقرب وقت.';
            } else {
                $title = 'A friendly note from the platform team';
                $body = "Hi {$first},\nYou tried to send or post some content ({$reason}) and we couldn't publish it. We all want a respectful, safe community, and we're sure it was an oversight — please adjust it and try again.\n"
                    . ($strikes >= 3 ? "A gentle heads-up: this has happened a few times today, and repeats may lead to a review of your account.\n" : '')
                    . 'If you think the block was a mistake, you can message the administration directly from the appeal page'
                    . ($admin ? " (administration account: {$admin['full_name']})" : '') . ' and we will reply as soon as we can.';
            }
            $this->notifications->notify($userId, 'content_warning', $title, $body, '/content-appeals', 'normal');
        } catch (\Throwable) {
            // التحذير إضافة — مبيكسرش الحجب.
        }
    }

    // ------------------------------------------------------------------ user side

    /** @return array{ok:bool,error:?string,status:int,id?:int} */
    public function create(int $userId, string $subject, string $body, ?string $category): array
    {
        $subject = trim(preg_replace('/\s+/u', ' ', $subject));
        $body = trim($body);
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 160) {
            return $this->fail('Write a short subject (3–160 characters).', 422);
        }
        if (mb_strlen($body) < self::MIN_BODY || mb_strlen($body) > self::MAX_BODY) {
            return $this->fail('Your message must be between ' . self::MIN_BODY . ' and ' . self::MAX_BODY . ' characters.', 422);
        }
        if (DB::table('content_appeals')->where('user_id', $userId)->where('status', '!=', 'closed')->count() >= self::MAX_OPEN) {
            return $this->fail('You already have open appeals. Please wait for the administration to reply.', 429);
        }
        if (DB::table('content_appeals')->where('user_id', $userId)->where('created_at', '>=', now()->subDay())->count() >= self::MAX_PER_DAY) {
            return $this->fail('You have reached today’s limit for appeals. Please try again tomorrow.', 429);
        }
        $cat = $category !== null && preg_match('/^[a-z_]{2,30}$/', $category) ? $category : null;

        $id = DB::transaction(function () use ($userId, $subject, $body, $cat) {
            $id = (int) DB::table('content_appeals')->insertGetId([
                'user_id' => $userId, 'subject' => $subject, 'category' => $cat, 'status' => 'open', 'last_sender' => 'user',
                'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('content_appeal_messages')->insert(['appeal_id' => $id, 'sender_id' => $userId, 'sender_type' => 'user', 'body' => $body, 'created_at' => now()]);

            return $id;
        });

        $name = (string) DB::table('users')->where('id', $userId)->value('full_name');
        $this->notifyAdmins('New content-block appeal', 'اعتراض جديد على حجب محتوى', $name, $subject, $id);

        return ['ok' => true, 'error' => null, 'status' => 200, 'id' => $id];
    }

    /** @return array{ok:bool,error:?string,status:int} */
    public function userReply(int $userId, int $appealId, string $body): array
    {
        $a = DB::table('content_appeals')->where('id', $appealId)->where('user_id', $userId)->first();
        if (!$a) {
            return $this->fail('Appeal not found.', 404);
        }
        if ($a->status === 'closed') {
            return $this->fail('This appeal is closed. Please open a new one if you need more help.', 422);
        }
        $body = trim($body);
        if (mb_strlen($body) < 2 || mb_strlen($body) > self::MAX_BODY) {
            return $this->fail('Your message must be between 2 and ' . self::MAX_BODY . ' characters.', 422);
        }
        DB::table('content_appeal_messages')->insert(['appeal_id' => $appealId, 'sender_id' => $userId, 'sender_type' => 'user', 'body' => $body, 'created_at' => now()]);
        DB::table('content_appeals')->where('id', $appealId)->update(['status' => 'open', 'last_sender' => 'user', 'last_message_at' => now(), 'updated_at' => now()]);
        $name = (string) DB::table('users')->where('id', $userId)->value('full_name');
        $this->notifyAdmins('New reply on a content-block appeal', 'رد جديد على اعتراض حجب محتوى', $name, (string) $a->subject, $appealId);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        return DB::table('content_appeals')->where('user_id', $userId)->orderByDesc('last_message_at')->limit(50)
            ->get(['id', 'subject', 'category', 'status', 'resolution', 'last_sender', 'last_message_at', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<string,mixed>|null */
    public function show(int $appealId, ?int $onlyUserId = null): ?array
    {
        $q = DB::table('content_appeals as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.id', $appealId);
        if ($onlyUserId !== null) {
            $q->where('a.user_id', $onlyUserId);
        }
        $a = $q->first(['a.id', 'a.user_id', 'a.subject', 'a.category', 'a.status', 'a.resolution', 'a.last_sender', 'a.last_message_at', 'a.created_at', 'a.closed_at',
            'u.full_name', 'u.email', 'u.uuid', 'u.avatar_path']);
        if (!$a) {
            return null;
        }
        $msgs = DB::table('content_appeal_messages as m')->leftJoin('users as s', 's.id', '=', 'm.sender_id')->where('m.appeal_id', $appealId)->orderBy('m.id')
            ->get(['m.id', 'm.sender_type', 'm.body', 'm.created_at', 's.full_name as sender_name', 's.avatar_path as sender_avatar'])->map(fn ($r) => (array) $r)->all();
        $out = (array) $a;
        $out['messages'] = $msgs;
        if ($onlyUserId === null) {
            $out['violations_7d'] = (int) DB::table('content_safety_events')->where('user_id', $a->user_id)->where('created_at', '>=', now()->subDays(7))->count();
        } else {
            unset($out['email'], $out['uuid']);
        }

        return $out;
    }

    // ------------------------------------------------------------------ admin side

    /** @return array{items:array<int,array<string,mixed>>,counts:array<string,int>} */
    public function adminList(string $status): array
    {
        $q = DB::table('content_appeals as a')->join('users as u', 'u.id', '=', 'a.user_id');
        if (in_array($status, ['open', 'answered', 'closed'], true)) {
            $q->where('a.status', $status);
        }
        $items = $q->orderByRaw("CASE a.status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")->orderByDesc('a.last_message_at')->limit(100)
            ->get(['a.id', 'a.subject', 'a.category', 'a.status', 'a.resolution', 'a.last_sender', 'a.last_message_at', 'a.created_at', 'u.id as user_id', 'u.full_name', 'u.avatar_path'])
            ->map(fn ($r) => (array) $r)->all();
        $counts = DB::table('content_appeals')->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all();

        return ['items' => $items, 'counts' => ['open' => (int) ($counts['open'] ?? 0), 'answered' => (int) ($counts['answered'] ?? 0), 'closed' => (int) ($counts['closed'] ?? 0)]];
    }

    /** @return array{ok:bool,error:?string,status:int} */
    public function adminReply(int $adminId, int $appealId, string $body, ?string $closeAs): array
    {
        $a = DB::table('content_appeals')->where('id', $appealId)->first();
        if (!$a) {
            return $this->fail('Appeal not found.', 404);
        }
        $body = trim($body);
        if ($body === '' && $closeAs === null) {
            return $this->fail('Write a reply or choose how to close the appeal.', 422);
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return $this->fail('The reply is too long.', 422);
        }
        if ($closeAs !== null && !in_array($closeAs, ['resolved', 'declined'], true)) {
            return $this->fail('Invalid closing option.', 422);
        }
        if ($body !== '') {
            DB::table('content_appeal_messages')->insert(['appeal_id' => $appealId, 'sender_id' => $adminId, 'sender_type' => 'admin', 'body' => $body, 'created_at' => now()]);
        }
        $upd = ['last_sender' => 'admin', 'last_message_at' => now(), 'updated_at' => now(), 'status' => 'answered'];
        if ($closeAs !== null) {
            $upd += ['status' => 'closed', 'resolution' => $closeAs, 'closed_at' => now(), 'closed_by' => $adminId];
        }
        DB::table('content_appeals')->where('id', $appealId)->update($upd);

        $u = DB::table('users')->where('id', $a->user_id)->first(['preferred_language']);
        $ar = ($u->preferred_language ?? 'ar') === 'ar';
        $note = $closeAs === 'resolved' ? ($ar ? 'تمت مراجعة اعتراضك وإغلاقه.' : 'Your appeal was reviewed and closed.')
            : ($closeAs === 'declined' ? ($ar ? 'تمت مراجعة اعتراضك وبقي القرار كما هو.' : 'Your appeal was reviewed and the decision stands.')
            : ($ar ? 'ردّت الإدارة على اعتراضك.' : 'The administration replied to your appeal.'));
        $this->notifications->notify((int) $a->user_id, 'content_appeal_reply', $ar ? 'رد على اعتراضك' : 'Reply to your appeal', $note . "\n" . $a->subject, '/content-appeals?open=' . $appealId, 'high');

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    // ------------------------------------------------------------------ helpers

    private function notifyAdmins(string $en, string $ar, string $who, string $subject, int $appealId): void
    {
        try {
            $ids = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('r.slug', 'admin')->where('u.status', 'active')->whereNull('u.deleted_at')->distinct()->limit(30)->pluck('u.id')->all();
            foreach ($ids as $id) {
                $this->notifications->notify((int) $id, 'system_content_appeal', $ar . ' — ' . $en, $who . ': ' . $subject, '/admin/content-appeals?open=' . $appealId, 'high');
            }
        } catch (\Throwable) {
            // إشعار الأدمن مبيكسرش حفظ الاعتراض
        }
    }

    /** @return array{ok:bool,error:string,status:int} */
    private function fail(string $msg, int $status): array
    {
        return ['ok' => false, 'error' => $msg, 'status' => $status];
    }
}
