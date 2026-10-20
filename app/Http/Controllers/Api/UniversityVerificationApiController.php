<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UniversityRepository;
use App\Repositories\UniversityReverificationLogRepository;
use App\Services\UniversityVerificationService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/UniversityVerificationApiController.php
 * القديمة — سطح REST واحد بيلف UniversityVerificationService::statusFor()/
 * submitDocument() بالظبط زي University\UniversityVerificationController
 * (الويب) — نفس شكل status/documents/history، نفس رفع multipart (مبيغيّرش
 * verification_status لوحده — القرار ده فاضل لـ Admin). كمان بيعرض صورة
 * إعادة التحقق التلقائي (expires_at/auto_reverify_enabled/سجل الأحداث)
 * اللي الشريط الجانبي في الصفحة القديمة بيعرضها، عبر
 * UniversityReverificationLogRepository.
 *
 * RBAC: uip.auth بتغطي المجموعة كله؛ كل أكشن بيستخرج جامعة الكولر نفسه
 * عبر UniversityRepository::findByUserId()، أبدًا مش id جاي من العميل.
 */
class UniversityVerificationApiController extends Controller
{
    public function __construct(
        private UniversityRepository $universities,
        private UniversityVerificationService $verification,
        private UniversityReverificationLogRepository $reverificationLog
    ) {
    }

    /** GET /api/v1/university/verification */
    public function index(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->findByUserId($userId);
        if (!$university) {
            return $this->apiError('Only university accounts have a verification status.', null, 403);
        }

        return $this->apiSuccess([
            'university'          => $university->toArray(),
            'verification'        => $this->verification->statusFor($university->id),
            'reverification_log'  => $this->reverificationLog->forUniversity($university->id, 20),
        ], 'Verification status retrieved successfully.');
    }

    /** POST /api/v1/university/verification/documents — رفع multipart. */
    public function submitDocument(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->findByUserId($userId);
        if (!$university) {
            return $this->apiError('Only university accounts can submit verification documents.', null, 403);
        }

        try {
            $this->verification->submitDocument($university->id, $request->file('document'), $request->input('notes'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->verification->statusFor($university->id), 'Document submitted for review.', 201);
    }
}
