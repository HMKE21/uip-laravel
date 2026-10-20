<?php

namespace App\Repositories;

use App\Models\GraduationRecord;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/GraduationRecordRepository.php القديمة —
 * بند 14 (Graduation). صف واحد لكل طالب متخرج (UNIQUE student_id) —
 * شوف GraduationService لمنطق الأهلية/الاعتماد/الإلغاء المبني فوقها.
 */
class GraduationRecordRepository
{
    public function findByStudent($studentId): ?GraduationRecord
    {
        return GraduationRecord::where('student_id', $studentId)->first();
    }

    public function find($id): ?GraduationRecord
    {
        return GraduationRecord::find($id);
    }

    /**
     * تحقق عام من رقم الشهادة (VerifyCertificate.jsx) — المسار الوحيد
     * اللي بيقرا بـ certificate_number بدل student_id، لأنه أصلًا
     * المعرّف الوحيد المتاح لطرف تالت بيتحقق من شهادة.
     */
    public function findByCertificateNumber(string $certificateNumber): ?array
    {
        $row = DB::table('graduation_records as gr')
            ->join('students as s', 's.id', '=', 'gr.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('universities as uni', 'uni.id', '=', 'gr.university_id')
            ->select(
                'gr.*',
                'u.full_name as student_name',
                'u.name_ar as student_name_ar',
                'u.name_en as student_name_en',
                'uni.official_name_ar as university_name_ar',
                'uni.official_name_en as university_name_en'
            )
            ->where('gr.certificate_number', $certificateNumber)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * كل سجلات تخرج جامعة، مع اسم الطالب — لقائمة University portal.
     * $status بيفلتر حالة واحدة ('graduated'|'revoked'); null = الكل.
     * $facultyId اختياري وإضافي (نفس اتفاقية StudentRepository::
     * findOwned()) — بورتال الجامعة مش بيمررها، بورتال الكلية دايمًا
     * بيمرر faculty_id بتاعته. graduation_records مفهاش عمود
     * faculty_id، فالفلتر ده عن طريق join على students.faculty_id.
     */
    public function forUniversity($universityId, ?string $status = null, $facultyId = null): array
    {
        $query = DB::table('graduation_records as gr')
            ->join('students as s', 's.id', '=', 'gr.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select('gr.*', 'u.full_name as student_name', 'u.name_ar as student_name_ar', 'u.name_en as student_name_en', 's.student_number')
            ->where('gr.university_id', $universityId);

        if ($status !== null) {
            $query->where('gr.status', $status);
        }
        if ($facultyId !== null) {
            $query->where('s.faculty_id', $facultyId);
        }

        return $query->orderByDesc('gr.approved_at')->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * عدد الشهادات اللي الجامعة دي أصدرتها في $year — لبناء رقم الشهادة
     * التسلسلي الجاي (GraduationService::nextCertificateNumber()).
     * بتعد الملغاة كمان — رقم شهادة ملغى لازم مايتكررش لطالب تاني.
     */
    public function countForUniversityYear($universityId, int $year): int
    {
        return (int) DB::table('graduation_records')
            ->where('university_id', $universityId)
            ->whereYear('approved_at', $year)
            ->count();
    }

    public function create(array $data): GraduationRecord
    {
        return GraduationRecord::create($data);
    }
}
