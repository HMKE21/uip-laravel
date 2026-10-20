<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\DataSegmentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/DataAnalysisSegmentsApiController.php
 * القديمة — بند 24 batch 2 (Data Segments). غلاف JSON رفيع فوق
 * App\Repositories\DataSegmentRepository — نفس الريبوزيتوري بالظبط، مفيش
 * منطق أو استعلامات جديدة. الـ criteria بتتقيّم حية ضد جداول
 * projects/users الحقيقية عبر DataSegmentRepository::countMatches() —
 * الكنترولر ده مش بيكرر المنطق ده.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو
 * admin — نفس قاعدة باقي DataAnalysis*ApiController.
 */
class DataAnalysisSegmentsApiController extends Controller
{
    private const ALLOWED_ENTITIES = ['projects', 'users'];

    public function __construct(private DataSegmentRepository $segments)
    {
    }

    /** GET /api/v1/data-analysis/segments */
    public function index(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view segments.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        return $this->apiSuccess([
            'segments'            => $this->segments->forUser($userId),
            'role_options'        => DataSegmentRepository::USER_ROLE_SLUGS,
            'user_status_options' => DataSegmentRepository::USER_STATUS_VALUES,
        ], 'Segments retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/segments */
    public function store(Request $request)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can create segments.', null, 403);
        }

        $validator = Validator::make($request->all(), ['name' => 'required', 'entity' => 'required']);
        if ($validator->fails()) {
            return $this->apiError('The given data was invalid.', $validator->errors()->toArray(), 422);
        }

        $entity = (string) $request->input('entity');
        if (!in_array($entity, self::ALLOWED_ENTITIES, true)) {
            return $this->apiError('Unknown data entity.', null, 422);
        }

        if ($entity === 'users') {
            $status = $request->input('status') ?: null;
            $role = $request->input('role') ?: null;

            if ($status !== null && !in_array($status, DataSegmentRepository::USER_STATUS_VALUES, true)) {
                return $this->apiError('Unknown user status.', null, 422);
            }
            if ($role !== null && !in_array($role, DataSegmentRepository::USER_ROLE_SLUGS, true)) {
                return $this->apiError('Unknown user role.', null, 422);
            }

            $criteria = array_filter(['status' => $status, 'role' => $role]);
        } else {
            $criteria = array_filter([
                'category' => $request->input('category') ?: null,
                'status'   => $request->input('status') ?: null,
            ]);
        }

        $segment = $this->segments->create($request->attributes->get('uip_user_id'), (string) $request->input('name'), $entity, $criteria);

        return $this->apiSuccess($segment->toArray(), 'Segment saved successfully.', 201);
    }

    /** DELETE /api/v1/data-analysis/segments/{id} */
    public function destroy(Request $request, $id)
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can delete segments.', null, 403);
        }

        if (!$this->segments->delete((int) $id, $request->attributes->get('uip_user_id'))) {
            return $this->apiError('Segment not found.', null, 404);
        }

        return $this->apiSuccess(null, 'Segment deleted successfully.');
    }

    private function isDataAnalyst(Request $request): bool
    {
        // 'admin' متضمنة: الأدمن عنده وصول كامل غير مقيد لبورتال Data
        // Analysis حسب config('roles.portal_prefixes').
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}