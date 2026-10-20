<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\CategoryRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\UniversityRepository;
use App\Services\MessagingService;
use App\Services\ProjectAnalyticsService;
use App\Services\SiteVisitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * منقولة جزئيًا من app/Controllers/PublicProjectController.php +
 * PublicLandingController القديمين — بند 11 مرحلة 3 (Publishing +
 * Public Discovery). سطح JSON بدون auth خالص (مفيش
 * uip.auth على الجروب ده في routes/api.php)، بيعيد استخدام نفس تجمّع
 * المشاريع المنشورة اللي كل بورتال Discovery (شركة/مستثمر، بند
 * 6/7/8) بيقرا منه — مفيش نظام موازي، زي ما توثيق القديمة بالظبط بيقول.
 *
 * اللي **مش** هنا عمدًا (تأجيل موثّق، مش نسيان):
 * - /go/github, /go/demo, /go/link, تحميل ملف مع تتبّع — دول redirect
 *   endpoints منفصلة (مش جزء من عرض المشروع نفسه). project_analytics_events
 *   اتنقلت فعليًا (بند 11 مرحلة 4، ProjectAnalyticsService) وrecordView()
 *   بقت متسجّلة هنا فعليًا (تاب Analytics بيشتغل)، لكن الفرونت
 *   (ProjectDetail.jsx) بيروح على الرابط الخام مباشرة مش عبر /go/
 *   متتبّع، فمفيش داعي فعلي للـ redirect endpoints دي دلوقتي.
 * - contact() — اتبنى: POST /public/projects/{slug}/contact (محتاج
 *   uip.auth)، بيفتح/يكمّل محادثة مباشرة مع صاحب المشروع عبر
 *   MessagingService::startConversation()، فنفس قيود المراسلة بتتطبق.
 * - verifyCertificate() كانت بترجع result: null دايمًا لحد بند 14
 *   (Graduation)؛ دلوقتي بتقرا فعليًا من GraduationRecordRepository::
 *   findByCertificateNumber() — شهادة ملغاة لسه بترجع (status:'revoked'
 *   واضح)، نفس قاعدة صفحة verify-certificate.php القديمة إن الشهادة
 *   الملغاة لسه "بترد" بس واضح إنها ملغاة، مش تختفي كأنها مش موجودة.
 */
class PublicApiController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private ProjectRepository $projects,
        private CategoryRepository $categories,
        private ProjectFileRepository $files,
        private ProjectLinkRepository $links,
        private ProjectTeamMemberRepository $team,
        private ProjectAnalyticsService $analytics,
        private \App\Repositories\GraduationRecordRepository $graduationRecords,
        private UniversityRepository $universities,
        private MessagingService $messaging,
        private SiteVisitService $siteVisits
    ) {
    }

    /** GET /api/v1/public/landing — مشاريع مميّزة (Admin-curated) لصفحة الهبوط. */
    public function landing(Request $request)
    {
        return $this->apiSuccess([
            'featured' => $this->projects->featured(6),
            // أرقام حقيقية من الـ DB (مش ثابتة) — بتتحدّث مع نمو المنصة. كاش قصير
            // (60ث) عشان صفحة الهبوط العامة متعملش 4 COUNT على كل زيارة.
            'stats'    => \Illuminate\Support\Facades\Cache::remember(
                'public.landing_stats',
                60,
                fn () => $this->siteVisits->landingStats()
            ),
        ], 'Landing page data retrieved successfully.');
    }

    /** GET /api/v1/public/visits — عدّاد زوار المنصة (للفوتر). */
    public function visits(Request $request)
    {
        return $this->apiSuccess($this->siteVisits->counts(), 'Visit counters retrieved successfully.');
    }

    /**
     * POST /api/v1/public/visits — تسجيل زيارة. الفرونت بيبعت مرة لكل جلسة
     * متصفح بمعرّف عشوائي (visitor_id) — بيتخزّن hash بس، مفيش IP/UA.
     */
    public function recordVisit(Request $request)
    {
        $visitorId = trim((string) $request->input('visitor_id', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $visitorId)) {
            return $this->apiError('Invalid visitor id.', null, 422);
        }

        $ua = strtolower((string) $request->userAgent());
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|curl|wget|python-requests/', $ua)) {
            // بوتات/أدوات: بنرجّع العدّاد من غير ما نزوّده.
            return $this->apiSuccess($this->siteVisits->counts(), 'Visit counters retrieved successfully.');
        }

        return $this->apiSuccess($this->siteVisits->record($visitorId), 'Visit recorded.');
    }

    /**
     * GET /api/v1/public/site-info — بيانات تواصل/سوشيال ميديا عامة
     * للفوتر وصفحة سياسة الخصوصية (config/site.php)، بدل ما الفرونت
     * يخترعها. فاضية = العنصر المرتبط بيها (سطر "Contact support"، أيقونة
     * سوشيال ميديا) بيختفي تمامًا في الفرونت — مفيش قيمة وهمية أبدًا.
     */
    public function siteInfo(Request $request)
    {
        return $this->apiSuccess([
            'support_email'              => (string) config('site.support_email', ''),
            'social_links'               => (array) config('site.social_links', []),
            'privacy_policy_updated_at'  => (string) config('site.privacy_policy_updated_at', ''),
        ], 'Site info retrieved successfully.');
    }

    /**
     * GET /api/v1/public/projects — تصفح/بحث/فلترة/ترقيم صفحات لكل
     * مشروع منشور، من غير auth. 'category' بتوصل إما slug واحد
     * (?category=ai) أو مصفوفة من شريط الفلاتر متعدد الاختيار
     * (?category[]=ai&category[]=iot) — نفس تسامح القديمة.
     */
    public function projects(Request $request)
    {
        $rawCategory = $request->input('category', []);
        $categorySlugs = is_array($rawCategory)
            ? array_values(array_unique(array_filter(array_map('trim', $rawCategory))))
            : array_values(array_filter([trim((string) $rawCategory)]));

        $allowedSorts = ['newest', 'views', 'oldest', 'az'];
        $sort = (string) $request->input('sort', 'newest');
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'newest';
        }

        $filters = array_filter([
            'category_slug' => $categorySlugs,
            'search'        => trim((string) $request->input('q', '')),
            'sort'          => $sort,
        ], fn ($v) => $v !== '' && $v !== null && $v !== []);

        $page = max(1, (int) $request->input('page', 1));
        $result = $this->projects->publishedPaginated($filters, $page, self::PER_PAGE);

        return $this->apiSuccess([
            'items'       => $result['rows'],
            'total'       => $result['total'],
            'page'        => $page,
            'per_page'    => self::PER_PAGE,
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'categories'  => $this->categories->withPublishedCounts(),
        ], 'Published projects retrieved successfully.');
    }

    /**
     * GET /api/v1/public/projects/{slug} — صفحة مشروع عامة واحدة، مفيش
     * auth مطلوب. عداد المشاهدات (views_count) بيتزود زي القديمة بالظبط،
     * وكمان (بند 11 مرحلة 4، بعد ما project_analytics_events اتنقلت)
     * بيتسجّل حدث 'view' تفصيلي عبر ProjectAnalyticsService::recordView()
     * — ده اللي بيغذّي تاب Analytics بتاع صاحب المشروع (summary/trend).
     * /go/github, /go/demo, /go/link وتحميل ملف متتبّع لسهم غير مبنيين
     * (الفرونت بيروح على الرابط الخام مباشرة، مش عبر /go/ متتبّع).
     */
    public function projectShow(Request $request, string $slug)
    {
        $row = $this->projects->findPublishedBySlugOrUuid($slug);
        if (!$row) {
            return $this->apiError('Project not found.', null, 404);
        }

        $this->projects->incrementViews($row['uuid']);
        $this->analytics->recordView($row['id'], $request);

        return $this->apiSuccess([
            'project'      => $row,
            'files'        => array_map(fn ($f) => $f->toRowArray(), $this->files->forProjectLatest($row['id'])),
            'media'        => array_map(fn ($f) => $f->toGalleryArray(), $this->files->mediaForProject($row['id'])),
            'links'        => array_map(fn ($l) => $l->toRowArray(), $this->links->forProject($row['id'])),
            'team_members' => array_map(fn ($m) => $m->toRowArray(), $this->team->forProject($row['id'])),
            // إيميلات وحسابات الفريق ظاهرة للكل (زائر أو مسجّل) — قرار صاحب المنصة.
            'people'           => $this->buildPeople($row),
            'contacts_visible' => true,
        ], 'Project retrieved successfully.');
    }

    private function avatarUrl(?string $path, $ownerId = null): ?string
    {
        // صاحب الصورة ممكن يخفيها عن العامة (إعداد "صورتي للعامة").
        if ($path && $ownerId && app(\App\Services\AvatarPrivacyService::class)->isHidden((int) $ownerId)) {
            return null;
        }
        return $path ? '/' . ltrim($path, '/') : null;
    }

    private function jsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** المالك + المشرفين/الدكاترة + أعضاء الفريق (المقبولين) بإيميلاتهم. */
    private function buildPeople(array $row): array
    {
        $ownerRow = DB::table('users as u')
            ->leftJoin('students as st', 'st.user_id', '=', 'u.id')
            ->where('u.id', $row['owner_id'])
            ->select('u.id as owner_user_id', 'u.full_name', 'u.email', 'u.avatar_path', 'st.student_number', 'st.academic_year', 'st.bio', 'st.skills', 'st.social_links')
            ->first();

        $owner = $ownerRow ? [
            'full_name'      => $ownerRow->full_name,
            'email'          => $ownerRow->email,
            'avatar_url'     => $this->avatarUrl($ownerRow->avatar_path, $ownerRow->owner_user_id),
            'student_number' => $ownerRow->student_number,
            'academic_year'  => $ownerRow->academic_year,
            'bio'            => $ownerRow->bio,
            'skills'         => $this->jsonArray($ownerRow->skills),
            'social_links'   => array_filter($this->jsonArray($ownerRow->social_links), fn ($u) => is_string($u) && preg_match('#^https?://#i', $u)),
        ] : null;

        $supervisors = DB::table('supervisor_assignments as sa')
            ->join('supervisors as s', 's.id', '=', 'sa.supervisor_id')
            ->leftJoin('users as su', 'su.id', '=', 's.user_id')
            ->where('sa.project_id', $row['id'])
            ->select('s.id', 's.full_name', 's.email', 's.title', 's.department', 'su.avatar_path', 'su.id as su_user_id')
            ->distinct()
            ->get()
            ->map(fn ($s) => [
                'name'       => $s->full_name,
                'email'      => $s->email,
                'title'      => $s->title,
                'department' => $s->department,
                'avatar_url' => $this->avatarUrl($s->avatar_path, $s->su_user_id),
                'role'       => 'supervisor',
            ])->all();

        // مفيش تعيين رسمي؟ نحاول نطابق اسم المشرف المكتوب في المشروع على مشرف الجامعة.
        if (!$supervisors && !empty($row['supervisor_name'])) {
            $match = DB::table('supervisors')
                ->where('university_id', $row['university_id'] ?? 0)
                ->where('full_name', $row['supervisor_name'])
                ->first();
            $supervisors[] = [
                'name'  => $row['supervisor_name'],
                'email' => $match->email ?? null,
                'title' => $match->title ?? null,
                'role'  => 'supervisor',
            ];
        }

        $team = DB::table('project_team_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.project_id', $row['id'])
            ->where('m.status', 'accepted')
            ->select('m.id', 'm.member_name', 'm.role', 'm.academic_year', 'm.student_number', 'm.invited_email', 'm.user_id', 'u.full_name', 'u.email', 'u.avatar_path')
            ->get()
            ->filter(fn ($m) => (int) $m->user_id !== (int) $row['owner_id'])
            ->map(fn ($m) => [
                'id'             => $m->id,
                'name'           => $m->member_name ?: ($m->full_name ?: 'Team member'),
                'email'          => $m->email ?: $m->invited_email,
                'role'           => $m->role,
                'academic_year'  => $m->academic_year,
                'student_number' => $m->student_number,
                'avatar_url'     => $this->avatarUrl($m->avatar_path, $m->user_id),
            ])->values()->all();

        return ['owner' => $owner, 'supervisors' => $supervisors, 'team' => $team];
    }

    /**
     * POST /api/v1/public/projects/{slug}/contact — body: message (≤ 2000).
     * محتاج مستخدم مسجّل (uip.auth على الراوت). بيبعت لصاحب المشروع المنشور
     * من خلال نظام الرسائل نفسه (نفس قيود المراسلة والـ rate limit)،
     * وإيميل المالك عمره ما بيطلع للفرونت. بيرجع conversation_id.
     */
    public function contact(Request $request, string $slug)
    {
        $row = $this->projects->findPublishedBySlugOrUuid($slug);
        if (!$row) {
            return $this->apiError('Project not found.', null, 404);
        }

        $senderId = (int) $request->attributes->get('uip_user_id');
        if ($senderId === (int) $row['owner_id']) {
            return $this->apiError('This is your own project.', null, 422);
        }

        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return $this->apiError('The message field is required.', ['message' => ['The message field is required.']], 422);
        }
        if (mb_strlen($message) > 2000) {
            return $this->apiError('The message may not be greater than 2000 characters.', ['message' => ['The message may not be greater than 2000 characters.']], 422);
        }

        $owner = User::find((int) $row['owner_id']);
        if (!$owner) {
            return $this->apiError('Project not found.', null, 404);
        }

        $title = trim((string) ($row['title_en'] ?? '')) ?: trim((string) ($row['title_ar'] ?? ''));
        $body = $title !== '' ? "[{$title}]\n\n{$message}" : $message;

        try {
            $conversationId = $this->messaging->startConversation($senderId, (string) $owner->email, $body);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['conversation_id' => $conversationId], 'Message sent to the project team.', 201);
    }

    /**
     * GET /api/v1/public/verify-certificate?number=... — لسه بترجع
     * result: null دايمًا (شوف تعليق الكلاس فوق) لحد ما بند 14
     * (Graduation) يبني GraduationRecordRepository::findByCertificateNumber().
     */
    /** GET /api/v1/public/verify-certificate?number=... — VerifyCertificate.jsx. */
    public function verifyCertificate(Request $request)
    {
        $number = trim((string) $request->input('number', ''));
        $result = $number !== '' ? $this->graduationRecords->findByCertificateNumber($number) : null;

        return $this->apiSuccess([
            'result' => $result,
        ], $result ? 'Certificate found.' : 'Certificate number not found.');
    }

    /**
     * GET /api/v1/public/universities/{uuid} — صفحة /u/{uuid} في
     * الفرونت (UniversitiesApiController::me() بترجع share_url بالشكل
     * ده). 404 موحّد (uuid مش موجود، أو جامعة مش فعّلة is_public).
     */
    public function universityProfile(Request $request, string $uuid)
    {
        $user = User::where('uuid', $uuid)->first();
        if (!$user) {
            return $this->apiError('Page not found.', null, 404);
        }

        $university = $this->universities->findByUserId($user->id);
        if (!$university || !$university->is_public) {
            return $this->apiError('Page not found.', null, 404);
        }

        $stats = $this->universities->withProfileStats((int) $university->id);
        // Certificate signature/stamp/dean details are for certificates only, not the public profile page.
        if ($stats) {
            unset($stats['signature_path'], $stats['stamp_path'], $stats['dean_signature_path'],
                $stats['dean_name_en'], $stats['dean_name_ar'], $stats['dean_title_en'], $stats['dean_title_ar']);
        }

        return $this->apiSuccess([
            'university' => $stats ?? $university->makeHidden(['signature_path', 'stamp_path', 'dean_signature_path', 'dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar'])->toArray(),
            'projects'   => $this->projects->publishedByUniversity((int) $university->id),
        ], 'University profile retrieved successfully.');
    }

}
