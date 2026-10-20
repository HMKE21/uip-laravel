<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ProjectRepository;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminFeaturedProjectsApiController.php
 * القديمة — بند 11 مرحلة 4. سطح REST واحد /api/v1/admin/featured-projects،
 * بيعيد استخدام ProjectRepository::publishedForFeaturedAdmin()/
 * setFeatured() بالظبط زي ما القديمة كانت بتعمل لعرض Blade المُقدَّم من
 * السيرفر. اختيار يدوي لأي مشاريع منشورة تظهر في قسم "Featured Projects"
 * بصفحة الهبوط العامة (PublicApiController::landing() -> ProjectRepository::
 * featured())، وبأي ترتيب — مش "الأكتر مشاهدة". بيتفعّل فورًا لأن صفحة
 * الهبوط بتقرا is_featured/featured_order live مع كل request. مفيش حاجة
 * مختلقة هنا.
 *
 * RBAC: uip.auth + uip.admin بيغطّوا الجروب (routes/api.php).
 */
class AdminFeaturedProjectsApiController extends Controller
{
    private const MAX_FEATURED = 6; // يطابق $limit الافتراضي بتاع ProjectRepository::featured()

    public function __construct(private ProjectRepository $projects)
    {
    }

    /** GET /api/v1/admin/featured-projects — كل مشروع منشور، المميّز الأول، بترتيب مُنسَّق. */
    public function index(Request $request)
    {
        $rows = $this->projects->publishedForFeaturedAdmin();
        $featuredCount = count(array_filter($rows, fn ($p) => (int) $p['is_featured'] === 1));

        return $this->apiSuccess($rows, 'Featured projects retrieved successfully.', 200, [
            'featuredCount' => $featuredCount,
            'maxFeatured'   => self::MAX_FEATURED,
        ]);
    }

    /** POST /api/v1/admin/featured-projects/{id}/feature — {id} هو uuid المشروع؛ بيعلّمه في أقرب ترتيب فاضي. */
    public function feature(Request $request, $id)
    {
        $rows = $this->projects->publishedForFeaturedAdmin();
        $project = $this->findByUuid($rows, (string) $id);

        if (!$project) {
            return $this->apiError('Project not found or not published.', null, 404);
        }

        $currentlyFeatured = array_filter($rows, fn ($p) => (int) $p['is_featured'] === 1);
        if (count($currentlyFeatured) >= self::MAX_FEATURED) {
            return $this->apiError('Only ' . self::MAX_FEATURED . ' projects can be featured at once — unfeature one first.', null, 422);
        }

        $nextOrder = $currentlyFeatured
            ? max(array_column($currentlyFeatured, 'featured_order')) + 1
            : 0;

        $ok = $this->projects->setFeatured((int) $project['id'], true, (int) $nextOrder);

        return $ok
            ? $this->apiSuccess(null, 'Project added to the homepage.')
            : $this->apiError('Could not feature this project.', null, 400);
    }

    /** POST /api/v1/admin/featured-projects/{id}/unfeature — إسقاط مشروع من صفحة الهبوط. */
    public function unfeature(Request $request, $id)
    {
        $rows = $this->projects->publishedForFeaturedAdmin();
        $project = $this->findByUuid($rows, (string) $id);

        if (!$project) {
            return $this->apiError('Project not found or not published.', null, 404);
        }

        $ok = $this->projects->setFeatured((int) $project['id'], false, 0);

        return $ok
            ? $this->apiSuccess(null, 'Project removed from the homepage.')
            : $this->apiError('Could not update this project.', null, 400);
    }

    /**
     * POST /api/v1/admin/featured-projects/{id}/order — body: {direction: 'up'|'down'}.
     * بتبدّل featured_order مع الجار عشان القايمة تفضل متتابعة 0..n-1.
     */
    public function reorder(Request $request, $id)
    {
        $direction = (string) $request->input('direction');

        $rows = $this->projects->publishedForFeaturedAdmin();
        $featured = array_values(array_filter($rows, fn ($p) => (int) $p['is_featured'] === 1));
        usort($featured, fn ($a, $b) => $a['featured_order'] <=> $b['featured_order']);

        $index = null;
        foreach ($featured as $i => $p) {
            if ($p['uuid'] === (string) $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $this->apiError('Project not found among featured projects.', null, 404);
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($featured)) {
            return $this->apiSuccess(null, 'Already at the edge — nothing to do.');
        }

        $a = $featured[$index];
        $b = $featured[$swapWith];
        $this->projects->setFeatured((int) $a['id'], true, (int) $b['featured_order']);
        $this->projects->setFeatured((int) $b['id'], true, (int) $a['featured_order']);

        return $this->apiSuccess(null, 'Order updated.');
    }

    private function findByUuid(array $rows, string $uuid): ?array
    {
        foreach ($rows as $row) {
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }
        return null;
    }
}
