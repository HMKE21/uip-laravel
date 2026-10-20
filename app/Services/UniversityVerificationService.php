<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UniversityRepository;
use App\Repositories\UniversityVerificationRepository;
use Illuminate\Http\UploadedFile;

/**
 * منقولة من app/Services/UniversityVerificationService.php القديمة —
 * صفحة Verification بتاعة بورتال الجامعة: verification_status الحالية
 * (جدول universities، بيقرر فيها Admin عبر UniversityRepository::decide())
 * + مسار المستندات المُقدَّمة (university_verification_requests). رفع
 * مستند جديد مبيغيّرش verification_status لوحده — القرار ده فاضل لـ
 * Admin — بس بيضيف دليل لطابور الطلبات.
 */
class UniversityVerificationService
{
    public function __construct(
        private UniversityVerificationRepository $requests,
        private UniversityRepository $universities,
        private FileUploadService $uploads
    ) {
    }

    /**
     * الصورة الكاملة لحالة توثيق جامعة: الحالة الحالية + المستندات
     * المُقدَّمة + سجل زمني.
     * @return array{status:string,verified_at:?string,verified_by:?string,documents:array,history:array}
     */
    public function statusFor($universityId): array
    {
        $university = $this->universities->find($universityId);
        $documents = $this->requests->forUniversity($universityId);

        $verifiedByName = null;
        if ($university && $university->verified_by) {
            $admin = User::find($university->verified_by);
            $verifiedByName = $admin ? $admin->full_name : null;
        }

        $history = [];
        foreach (array_reverse($documents) as $doc) {
            $history[] = [
                'label' => $doc['notes'] ?: 'Document submitted',
                'date'  => $doc['created_at'],
            ];
        }
        if ($university && $university->verified_at) {
            $history[] = [
                'label' => $university->verification_status === 'rejected' ? 'Verification rejected' : 'Verification approved',
                'date'  => $university->verified_at,
            ];
        }

        return [
            'status'      => $university->verification_status ?? 'unverified',
            'verified_at' => $university->verified_at ?? null,
            'verified_by' => $verifiedByName,
            'documents'   => $documents,
            'history'     => array_reverse($history),
        ];
    }

    /** @throws \RuntimeException على فشل الفاليديشن (رسالة آمنة تتعرض للمستخدم) */
    public function submitDocument($universityId, ?UploadedFile $file, ?string $notes): void
    {
        $stored = $this->uploads->store($file, 'documents', 'university-' . $universityId);

        $this->requests->create([
            'university_id' => $universityId,
            'document_path' => $stored['stored_path'],
            'notes'         => $notes ? trim($notes) : null,
            'status'        => 'pending',
        ]);
    }
}
