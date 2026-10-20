<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PatentService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/PatentsApiController.php القديمة —
 * /api/v1/patents/* (بند 14/Future — Patent Portal، طالب أو باحث،
 * نفس الـ endpoint واحد للاتنين لأن الكويري مفلترة بـ submitted_by
 * فقط، مش بالدور).
 *
 * RBAC: middleware('uip.auth') بتغطي الجروب كله (routes/api.php)؛ الدور
 * لازم يكون student. المستخدم الفاعل بيتحدد دايمًا من
 * uip_user_id في الـ request، أبدًا مش id جاي من العميل.
 */
class PatentsApiController extends Controller
{
    public function __construct(private PatentService $patents)
    {
    }

    /**
     * GET /api/v1/patents — ملفات براءات الاختراع بتاعة المستخدم نفسه +
     * المشاريع اللي ممكن يربط ملف جديد بيها.
     */
    public function index(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        if ($role !== 'student') {
            return $this->apiError('Only student accounts can view patent filings.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'patents'  => $this->patents->listForStudent($userId),
            'projects' => $this->patents->linkableProjects($userId),
        ], 'Patents retrieved successfully.');
    }

    /**
     * POST /api/v1/patents — body: title (مطلوب), project_id (اختياري),
     * application_number (اختياري).
     */
    public function store(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        if ($role !== 'student') {
            return $this->apiError('Only student accounts can submit patent filings.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $this->patents->submit($userId, [
                'title'              => $request->input('title'),
                'project_id'         => $request->input('project_id') ?: null,
                'application_number' => $request->input('application_number'),
            ]);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->patents->listForStudent($userId), 'Patent filing submitted successfully.', 201);
    }
}
