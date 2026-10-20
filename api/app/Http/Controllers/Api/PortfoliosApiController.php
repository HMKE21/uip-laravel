<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PortfolioService;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Controllers/Api/PortfoliosApiController.php القديمة —
 * بند 12 (Portfolios). سيرفر REST واحد /api/v1/portfolios/* للصفحة
 * القابلة للمشاركة (`portfolios` + `portfolio_projects`، migration 016)،
 * بتستخدم PortfolioService/PortfolioRepository بالظبط زي ما
 * StudentPortfolio.jsx بيعمل. PortfolioService نفسها مش role-gated —
 * "الصفحة العامة/القابلة للمشاركة اللي الطلاب، الباحثين... بيستخدموها
 * عشان يعرضوا مجموعة مختارة من مشاريعهم المنشورة" زي ما docblock موديل
 * Portfolio نفسه بيقول — فالكنترولر هنا كمان مش مقصور على role واحد؛ أي
 * مستخدم مسجّل عنده مشاريع منشورة يقدر يبقى عنده portfolio.
 *
 * show() بتطابق سلوك PublicPortfolioController القديمة تمامًا (/p/{uuid}:
 * 404 لـ uuid مش موجود OU portfolio صاحبه خلاه private، عشان الاستجابة
 * أبدًا متسربّش أنهي uuid موجود) لكن فاضلة جوه الجروب العادي المتحكم فيه
 * بـ uip.auth، زي كل موديول تاني في الـ REST layer ده (مثلاً
 * ProjectsApiController::discover() كمان endpoint "تصفح" auth-only هنا،
 * رغم إن نظيرتها في الـ web عامة).
 *
 * RBAC: uip.auth بتغطي الجروب كله. كل فعل تعديل بيشتغل بس على الـ
 * portfolio بتاع الكولر نفسه (uip_user_id متحلّل من الـ middleware، زي
 * أي استدعاء تاني لـ PortfolioService) — مفيش تعديل عبر مستخدمين.
 */
class PortfoliosApiController extends Controller
{
    public function __construct(private PortfolioService $portfolios, private RoleService $roles)
    {
    }

    // -- List (portfolio الكولر نفسه: البروفايل + المؤهلة + المميزة) -------

    /** GET /api/v1/portfolios/me */
    public function me(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $data = $this->portfolios->forOwner($userId);
        $user = User::find($userId);

        return $this->apiSuccess([
            'portfolio'         => $data['portfolio']->toArray(),
            'eligible_projects' => $data['eligibleProjects'],
            'featured_projects' => $data['featuredProjects'],
            'share_url'         => $this->publicBaseUrl() . '/p/' . ($user->uuid ?? ''),
            'role'              => $this->roles->primaryRoleFor($userId),
        ], 'Portfolio retrieved successfully.');
    }

    /**
     * أساس رابط الفرونت (SPA) عشان بناء share_url — config('app.url') هنا
     * عنوان الباك إند نفسه (Laravel API)، مش الفرونت الـ React اللي شغال
     * على بورت/دومين تاني في التطوير (Vite على :5173 مثلًا). نفس منطق
     * MailService::loginUrl()/UniversitiesApiController::publicBaseUrl()
     * بالظبط: FRONTEND_URL لو متظبطة في .env، وإلا رجوع لـ APP_URL.
     */
    private function publicBaseUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
    }

    // -- Show (عرض بشكل عام بـ uuid مستخدم تاني) -----------------------------

    /** GET /api/v1/portfolios/{uuid} */
    public function show(Request $request, string $uuid)
    {
        $user = User::where('uuid', $uuid)->first();
        if (!$user) {
            return $this->apiError('Portfolio not found.', null, 404);
        }

        $data = $this->portfolios->forPublicShare($user->id);
        if (!$data) {
            return $this->apiError('Portfolio not found.', null, 404);
        }
        $role = $this->roles->primaryRoleFor((int) $user->id);

        return $this->apiSuccess([
            // role: عشان الصفحة العامة تعرض شارة الدور (مشرف/أدمن/محلل...) وتخفي
            // أقسام المشاريع للأدوار اللي مش بتملك مشاريع. slug بس — مفيش بيانات حساسة.
            'owner'             => [
                'full_name' => $user->full_name,
                'uuid'      => $user->uuid,
                'role'      => $role,
                // profile: معلومات عامة آمنة خاصة بالدور (جهة/قسم/رتبة) — من غير إيميل أو أي بيانات داخلية.
                'profile'   => $this->publicRoleProfile((int) $user->id, $role),
            ],
            'portfolio'         => $data['portfolio']->toArray(),
            'featured_projects' => $data['featuredProjects'],
        ], 'Portfolio retrieved successfully.');
    }

    /**
     * بيانات عامة مخصصة لكل دور على /p/{uuid}. academic_staff بيتفرّع لـ
     * doctor / ta حسب الرتبة (Demonstrator / Teaching Assistant = معيد).
     * بيرجع null للأدوار اللي ملهاش بيانات عامة إضافية (admin/security/analyst).
     */
    private function publicRoleProfile(int $userId, string $role): ?array
    {
        if ($role === 'academic_staff') {
            $r = DB::table('academic_staff as a')
                ->leftJoin('academic_ranks as r', 'r.id', '=', 'a.academic_rank_id')
                ->leftJoin('universities as u', 'u.id', '=', 'a.university_id')
                ->leftJoin('faculties as f', 'f.id', '=', 'a.faculty_id')
                ->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
                ->where('a.user_id', $userId)
                ->first([
                    'r.name_en as rank_en', 'r.name_ar as rank_ar',
                    'u.official_name_en as uni_en', 'u.official_name_ar as uni_ar',
                    'f.name_en as fac_en', 'f.name_ar as fac_ar',
                    'd.name_en as dep_en', 'd.name_ar as dep_ar',
                ]);
            if (!$r) {
                return null;
            }
            $isTa = (bool) preg_match('/demonstrator|teaching assistant/i', (string) $r->rank_en);
            return [
                'kind'       => $isTa ? 'ta' : 'doctor',
                'rank'       => ['en' => $r->rank_en, 'ar' => $r->rank_ar],
                'university' => ['en' => $r->uni_en, 'ar' => $r->uni_ar],
                'faculty'    => ['en' => $r->fac_en, 'ar' => $r->fac_ar],
                'department' => ['en' => $r->dep_en, 'ar' => $r->dep_ar],
            ];
        }

        if ($role === 'supervisor') {
            $r = DB::table('supervisors as s')
                ->leftJoin('universities as u', 'u.id', '=', 's.university_id')
                ->where('s.user_id', $userId)
                ->first(['s.title', 's.department', 'u.official_name_en as uni_en', 'u.official_name_ar as uni_ar']);
            if (!$r) {
                return null;
            }
            return [
                'kind'       => 'supervisor',
                'rank'       => $r->title ? ['en' => $r->title, 'ar' => $r->title] : null,
                'university' => ['en' => $r->uni_en, 'ar' => $r->uni_ar],
                'department' => $r->department ? ['en' => $r->department, 'ar' => $r->department] : null,
            ];
        }

        return null;
    }

    // -- Update (headline/about/theme/visibility) ----------------------------

    /** PATCH /api/v1/portfolios/me — headline/about/is_public عبر PortfolioService (نفس المنطق القديم)؛ theme عبر fill+save مباشر (نفس fillable field، مفيش service method ليها). */
    public function update(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $portfolio = $this->portfolios->forOwner($userId)['portfolio'];

        $this->portfolios->updateProfile($userId, [
            'headline'  => $request->has('headline') ? $request->input('headline') : $portfolio->headline,
            'about'     => $request->has('about') ? $request->input('about') : $portfolio->about,
            'is_public' => $request->has('is_public') ? $request->boolean('is_public') : (bool) $portfolio->is_public,
        ]);

        if ($request->has('theme')) {
            $portfolio->fill(['theme' => $request->input('theme') ?: null]);
            $portfolio->save();
        }

        return $this->apiSuccess(
            $this->portfolios->forOwner($userId)['portfolio']->toArray(),
            'Portfolio updated.'
        );
    }

    // -- Publish / Unpublish (تبديل الظهور) -----------------------------------

    /** POST /api/v1/portfolios/publish — يخلي الـ portfolio ظاهر عام على /p/{uuid}. */
    public function publish(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->updateProfile($userId, $this->currentProfile($userId, ['is_public' => true]));
        return $this->apiSuccess(null, 'Portfolio published.');
    }

    /** POST /api/v1/portfolios/unpublish — بيخفي الـ portfolio عن رابط المشاركة العام. */
    public function unpublish(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->updateProfile($userId, $this->currentProfile($userId, ['is_public' => false]));
        return $this->apiSuccess(null, 'Portfolio unpublished.');
    }

    // -- المشاريع المميزة (أنهي مشاريع منشورة تظهر على الـ portfolio) --------

    /** POST /api/v1/portfolios/projects/{projectId}/feature — بتبدّل حالة "مميز" لمشروع منشور بتاع الكولر نفسه. */
    public function toggleFeature(Request $request, int $projectId)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->toggleFeatured($userId, $projectId);

        return $this->apiSuccess(
            $this->portfolios->forOwner($userId)['featuredProjects'],
            'Featured projects updated.'
        );
    }

    // -- helpers --------------------------------------------------------------

    /** @return array{headline:?string, about:?string, is_public:bool} حقول البروفايل الحالية + $overrides — لـ PATCH بحقل واحد عبر توقيع PortfolioService::updateProfile() اللي بياخد الـ payload كامل. */
    private function currentProfile(int $userId, array $overrides): array
    {
        $portfolio = $this->portfolios->forOwner($userId)['portfolio'];
        return array_merge([
            'headline'  => $portfolio->headline,
            'about'     => $portfolio->about,
            'is_public' => (bool) $portfolio->is_public,
        ], $overrides);
    }
}
