<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AnnouncementRepository;
use App\Repositories\UniversityRepository;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AnnouncementsApiController.php القديمة —
 * سطح /api/v1/announcements/* واحد لبند الـ University Announcements
 * (migration 113/118/122 — announcements + announcement_attachments)،
 * بنفس الشكل بالظبط اللي FeedApiController منقول بيه:
 *
 *   - جامعة: index()/store()/update()/destroy() بتلف AnnouncementRepository::
 *     forUniversity() و AnnouncementService::publish()/update()/delete() —
 *     university_id دايمًا مُشتق من uip_user_id، مش من client input.
 *   - طالب: index()/show() بترجع الإعلانات المنشورة بس، مقيّدة بجامعته
 *     (universityIdForStudent()) + نطاق رؤيته (scopeForStudent()) —
 *     زي FeedApiController بالظبط.
 *
 * show() ownership-checked عبر AnnouncementRepository::findOwnedByUniversity()
 * للدورين (إعلان بينتمي لجامعة واحدة بس دايمًا)، مع attachments مرفقة زي
 * ما الـ web views القديمة كانت بتعمل.
 *
 * فرق شكلي فقط عن القديمة (زي FeedApiController بالظبط):
 * Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/
 * 'uip_role')، $this->param('id') -> $id مُمرر صراحة كـ route parameter.
 * store()/update() بتاخد الـ Request كامل زي القديمة بالظبط، لأن
 * AnnouncementService نفسه بيقرا title/body/category/... مباشرة منه.
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php). كل ميثود بتتأكد من
 * uip_role بنفسها لأن الجامعة والطالب بيعملوا أكشنز مختلفة تمامًا، زي
 * الكنترولرين القديمين (University\UniversityAnnouncementController +
 * Student\StudentAnnouncementController) اللي دي بتلفهم.
 */
class AnnouncementsApiController extends Controller
{
    public function __construct(
        private AnnouncementRepository $announcements,
        private UniversityRepository $universities,
        private AnnouncementService $service
    ) {
    }

    /** GET /api/v1/announcements — University: its own roster. Student: published, scoped to their own university + visibility scope. */
    public function index(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        $userId = $request->attributes->get('uip_user_id');

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(50, (int) $request->input('per_page', 10)));

        if ($role === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $filters = [
                'q'        => trim((string) $request->input('q', '')),
                'category' => $request->input('category', 'all'),
                'status'   => $request->input('status', 'all'),
            ];

            $listing = $this->announcements->forUniversity((int) $university->id, $filters, $page, $perPage);
            $attachments = $this->announcements->attachmentsFor(array_column($listing['items'], 'id'));

            return $this->apiSuccess($listing['items'], 'Announcements retrieved successfully.', 200, [
                'page' => $page, 'perPage' => $perPage, 'total' => $listing['total'], 'attachments' => $attachments,
            ]);
        }

        if ($role === 'student') {
            $universityId = $this->service->universityIdForStudent($userId);
            $filters = [
                'q'        => trim((string) $request->input('q', '')),
                'category' => $request->input('category', 'all'),
            ];

            if (!$universityId) {
                return $this->apiSuccess([], 'Announcements retrieved successfully.', 200, [
                    'page' => 1, 'perPage' => $perPage, 'total' => 0, 'unaffiliated' => true,
                ]);
            }

            $listing = $this->announcements->publishedForUniversity($universityId, $filters, $page, $perPage, $this->service->scopeForStudent($userId));
            $attachments = $this->announcements->attachmentsFor(array_column($listing['items'], 'id'));

            return $this->apiSuccess($listing['items'], 'Announcements retrieved successfully.', 200, [
                'page' => $page, 'perPage' => $perPage, 'total' => $listing['total'], 'attachments' => $attachments,
            ]);
        }

        return $this->apiError('Only university or student accounts can view announcements.', null, 403);
    }

    /** GET /api/v1/announcements/{id} — ownership-checked (announcement always belongs to one university). */
    public function show(Request $request, $id)
    {
        $universityId = $this->resolveUniversityId($request);
        if ($universityId === null) {
            return $this->apiError('Only university or affiliated student accounts can view announcements.', null, 403);
        }

        $announcement = $this->announcements->findOwnedByUniversity((int) $id, $universityId);
        if (!$announcement) {
            return $this->apiError('Announcement not found.', null, 404);
        }

        $announcement['attachments'] = $this->announcements->attachmentsFor([$announcement['id']])[$announcement['id']] ?? [];

        return $this->apiSuccess($announcement, 'Announcement retrieved successfully.');
    }

    /** POST /api/v1/announcements — University role only. */
    public function store(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can publish announcements.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $id = $this->service->publish((int) $university->id, $userId, $request);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['id' => $id], 'Announcement published successfully.', 201);
    }

    /** PATCH /api/v1/announcements/{id} — University role only. */
    public function update(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update an announcement.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->service->update((int) $id, (int) $university->id, $request);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Announcement updated successfully.');
    }

    /** DELETE /api/v1/announcements/{id} — University role only. */
    public function destroy(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can delete an announcement.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->service->delete((int) $id, (int) $university->id, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(null, 'Announcement deleted successfully.');
    }

    // -- helpers --------------------------------------------------------------

    private function resolveUniversityId(Request $request): ?int
    {
        $role = $request->attributes->get('uip_role');
        $userId = $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            return (int) $this->universities->getOrCreate($userId)->id;
        }
        if ($role === 'student') {
            return $this->service->universityIdForStudent($userId);
        }
        return null;
    }
}
