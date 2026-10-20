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
use App\Services\ProjectAnalyticsService;
use App\Services\ProjectPublicShowcaseService;
use Illuminate\Http\Request;

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
 * - contact() — الفرونت (ProjectDetail.jsx) مُعطّل من ناحيته أصلًا
 *   (بيعرض note بدل فورم شغال)، فمفيش endpoint هنا يستقبله.
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
        private ProjectPublicShowcaseService $showcase
    ) {
    }

    /** GET /api/v1/public/landing — مشاريع مميّزة (Admin-curated) لصفحة الهبوط. */
    public function landing(Request $request)
    {
        return $this->apiSuccess([
            'featured' => $this->projects->featured(6),
        ], 'Landing page data retrieved successfully.');
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

        // الإيميلات بتظهر للمسجّل دخول (توكن صالح عبر uip.auth.optional)،
        // أو لو الأدمن فعّل config('site.project_contacts_public').
        $canSeeContacts = $request->attributes->has('uip_user_id')
            || (bool) config('site.project_contacts_public', false);

        return $this->apiSuccess([
            'project'          => $row,
            'files'            => array_map(fn ($f) => $f->toRowArray(), $this->files->forProjectLatest($row['id'])),
            'media'            => array_map(fn ($f) => $f->toGalleryArray(), $this->files->mediaForProject($row['id'])),
            'links'            => array_map(fn ($l) => $l->toRowArray(), $this->links->forProject($row['id'])),
            'team_members'     => array_map(fn ($m) => $m->toRowArray(), $this->team->forProject($row['id'])),
            'people'           => $this->showcase->people($row, $canSeeContacts),
            'contacts_visible' => $canSeeContacts,
        ], 'Project retrieved successfully.');
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

        return $this->apiSuccess([
            'university' => $stats ?? $university->toArray(),
            'projects'   => $this->projects->publishedByUniversity((int) $university->id),
        ], 'University profile retrieved successfully.');
    }

}
