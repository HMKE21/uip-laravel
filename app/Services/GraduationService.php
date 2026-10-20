<?php

namespace App\Services;

use App\Repositories\GraduationRecordRepository;
use App\Repositories\ProjectGradeRepository;
use App\Repositories\StudentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/GraduationService.php القديمة — بند 14
 * (Graduation Record / Transcript / Certificate).
 *
 * مبنية فوق اللي موجود بدل ما تبني مصدر بيانات موازي:
 *   - أهلية التخرج (eligibility) بتتحسب، مش بتتخزن — بتقرا حالة حساب
 *     الطالب + تقييم مشروعه النهائي (project_grades.status='final') في
 *     كل مرة، فمستحيل تفضل قديمة عن الداتا الحقيقية.
 *   - قرار التخرج (approve/revoke) ده اللي بيتخزن، في graduation_records
 *     (migration 109)، لأنه حدث حقيقي له تاريخ ومعتمِد ورقم شهادة لازم
 *     يفضل ثابت حتى لو الداتا الأصلية اتغيرت بعدين.
 *   - "Final GPA" هنا SNAPSHOT من students.gpa وقت الاعتماد.
 *   - Transcript = صف graduation_records + تقييم(ات) المشروع النهائية،
 *     بيتجمّعوا وقت القراءة (transcriptFor())، مش أعمدة مكررة.
 */
class GraduationService
{
    /** أي تقييم نهائي بالحرف ده يعتبر "راسب" — نفس السلم اللي ProjectGradingService بتشتق منه الحروف. */
    private const FAILING_LETTER = 'F';

    public function __construct(
        private GraduationRecordRepository $records,
        private ProjectGradeRepository $grades,
        private StudentRepository $students,
        private AuditLogService $auditLog,
        private NotificationService $notifications
    ) {
    }

    /**
     * قائمة تحقق الأهلية لطالب واحد، بشكل يصلح لعرض الطالب لنفسه ولشاشة
     * مراجعة الجامعة. عمرها ما بتثق في flag متخزن — دايمًا بتشتق من حالة
     * حساب الطالب الحالية وتقييم مشروعه النهائي.
     *
     * @return array{eligible:bool, already_graduated:bool, revoked:bool, checks:array, finalized_grade:?array, student:?array, existing_record:mixed}
     */
    public function checkEligibility(int $studentId): array
    {
        $studentRow = $this->students->withProfileDetails($studentId);
        if (!$studentRow) {
            return ['eligible' => false, 'already_graduated' => false, 'revoked' => false, 'checks' => [], 'finalized_grade' => null, 'student' => null, 'existing_record' => null];
        }

        $finalGrades = $this->grades->finalGradesForUser((int) $studentRow['user_id']);
        $finalizedGrade = $finalGrades[0] ?? null;
        $existing = $this->records->findByStudent($studentId);

        $accountActive = ($studentRow['account_status'] ?? null) === 'active';
        $hasFinalGrade = $finalizedGrade !== null;
        $passingGrade = $hasFinalGrade && ($finalizedGrade['letter_grade'] ?? null) !== self::FAILING_LETTER;

        $checks = [
            ['key' => 'account_active', 'met' => $accountActive, 'label' => [
                'en' => 'Student account is active',
                'ar' => 'حساب الطالب فعّال',
            ]],
            ['key' => 'project_graded', 'met' => $hasFinalGrade, 'label' => [
                'en' => 'Graduation project has a finalized grade',
                'ar' => 'مشروع التخرج له تقييم نهائي معتمد',
            ]],
            ['key' => 'passing_grade', 'met' => $passingGrade, 'label' => [
                'en' => 'Final project grade is passing',
                'ar' => 'درجة المشروع النهائية ناجحة',
            ]],
        ];

        $eligible = $accountActive && $hasFinalGrade && $passingGrade && (!$existing || $existing->status === 'revoked');

        return [
            'eligible'          => $eligible,
            'already_graduated' => $existing !== null && $existing->status === 'graduated',
            'revoked'           => $existing !== null && $existing->status === 'revoked',
            'checks'            => $checks,
            'finalized_grade'   => $finalizedGrade,
            'student'           => $studentRow,
            'existing_record'   => $existing,
        ];
    }

    /**
     * كل طلاب جامعة مقسّمين eligible / graduated / revoked لقائمة
     * بورتال الجامعة. $facultyId اختياري وإضافي (نفس اتفاقية
     * StudentRepository::findOwned()) — بورتال الجامعة مش بيمررها،
     * بورتال الكلية دايمًا بيمرر faculty_id بتاعته.
     */
    public function listForUniversity(int $universityId, ?int $facultyId = null): array
    {
        $eligible = [];
        $graduated = $this->records->forUniversity($universityId, 'graduated', $facultyId);
        $revoked = $this->records->forUniversity($universityId, 'revoked', $facultyId);

        $roster = $facultyId !== null
            ? $this->students->forFaculty($facultyId)
            : $this->students->forUniversity($universityId);

        foreach ($roster as $student) {
            $existing = $this->records->findByStudent($student->id);
            if ($existing) {
                continue; // أصلًا ظاهر في قوائم graduated/revoked فوق
            }
            $result = $this->checkEligibility((int) $student->id);
            if ($result['eligible']) {
                $eligible[] = $result;
            }
        }

        return ['eligible' => $eligible, 'graduated' => $graduated, 'revoked' => $revoked];
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function approve(
        int $studentId,
        int $universityId,
        int $approvedByUserId,
        ?string $degreeTitleAr,
        ?string $degreeTitleEn,
        ?string $notes,
        string $locale = 'ar',
        ?int $facultyId = null,
        bool $manualOverride = false,
        ?string $overrideReason = null,
        array $fields = []
    ): array {
        $eligibility = $this->checkEligibility($studentId);
        $student = $eligibility['student'];

        if (!$student || (int) $student['university_id'] !== $universityId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الطالب غير موجود ضمن جامعتك.'
                : 'Student not found within your university.'];
        }
        if ($facultyId !== null && (int) ($student['faculty_id'] ?? 0) !== $facultyId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الطالب غير موجود ضمن كليتك.'
                : 'Student not found within your faculty.'];
        }

        // الاعتماد اليدوي عمدًا مايسمحش بتخريج طالب عنده صف
        // graduation_records فعّال بالفعل — UNIQUE(student_id) + قاعدة
        // "شهادة واحدة عمرها ما تتكرر" معناها لازم يعدي على revoke()
        // الأول، override أو مش override. هو بس بديل لحساب الأهلية
        // (تقييم ناقص/راسب، حساب غير فعّال)، مش للقيد البنيوي ده.
        if ($eligibility['already_graduated']) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'الطالب متخرج بالفعل.'
                : 'This student has already graduated.'];
        }

        if (!$eligibility['eligible']) {
            if (!$manualOverride) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'الطالب لا يستوفي شروط التخرج بعد.'
                    : 'This student does not yet meet the graduation requirements.'];
            }
            if (trim((string) $overrideReason) === '') {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'لازم تكتب سبب الاعتماد اليدوي.'
                    : 'A reason is required for a manual override approval.'];
            }
        }

        // قيَم اختيارية بيكتبها الموظف وقت الاعتماد (تاريخ/معدل/أسماء). أي حقل
        // فاضي بيرجع للقيمة المشتقة من ملف الطالب زي الأول بالظبط.
        $graduationDate = trim((string) ($fields['graduation_date'] ?? ''));
        if ($graduationDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $graduationDate) || strtotime($graduationDate) === false)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'تاريخ تخرج غير صالح.' : 'Invalid graduation date.'];
        }
        $gpaInput = $fields['final_gpa'] ?? null;
        $hasGpa = $gpaInput !== null && trim((string) $gpaInput) !== '';
        if ($hasGpa && (!is_numeric($gpaInput) || (float) $gpaInput < 0 || (float) $gpaInput > 4)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'المعدل التراكمي لازم يكون رقم بين 0 و 4.' : 'Final GPA must be a number between 0 and 4.'];
        }
        $pick = fn (string $key, $default) => $this->blankToNull($fields[$key] ?? null) ?? $default;

        $certificateNumber = $this->nextCertificateNumber($universityId);
        $approvedAt = now();
        $isOverride = $manualOverride && !$eligibility['eligible'];

        $finalNotes = ($notes ?? '') !== '' ? $notes : null;
        if ($isOverride) {
            $overrideNote = ($locale === 'ar' ? 'اعتماد يدوي (تجاوز الشروط): ' : 'Manual override (requirements bypassed): ') . trim((string) $overrideReason);
            $finalNotes = $finalNotes !== null ? $finalNotes . "\n\n" . $overrideNote : $overrideNote;
        }

        $data = [
            'student_id'         => $studentId,
            'university_id'      => $universityId,
            'status'             => 'graduated',
            'graduation_date'    => $graduationDate !== '' ? $graduationDate : now()->toDateString(),
            'final_gpa'          => $hasGpa ? (float) $gpaInput : ($student['gpa'] ?? null),
            'degree_title_ar'    => ($degreeTitleAr ?? '') !== '' ? $degreeTitleAr : null,
            'degree_title_en'    => ($degreeTitleEn ?? '') !== '' ? $degreeTitleEn : null,
            'faculty_name_ar'    => $pick('faculty_name_ar', $student['faculty_name_ar'] ?? $student['faculty'] ?? null),
            'faculty_name_en'    => $pick('faculty_name_en', $student['faculty_name_en'] ?? $student['faculty'] ?? null),
            'department_name_ar' => $pick('department_name_ar', $student['department_name_ar'] ?? $student['department'] ?? null),
            'department_name_en' => $pick('department_name_en', $student['department_name_en'] ?? $student['department'] ?? null),
            'certificate_number' => $certificateNumber,
            'notes'              => $finalNotes,
            'approved_by'        => $approvedByUserId,
            'approved_at'        => $approvedAt,
        ];

        // طالب اتلغى تخرجه قبل كده: الجدول UNIQUE(student_id) فمينفعش INSERT
        // تاني (كان بيطلّع 500) — بنحدّث نفس الصف برقم شهادة جديد ونصفّر بيانات الإلغاء.
        $existingRecord = $eligibility['existing_record'];
        if ($existingRecord && $existingRecord->status === 'revoked') {
            $existingRecord->fill($data + [
                'revoked_by'    => null,
                'revoked_at'    => null,
                'revoke_reason' => null,
            ]);
            $existingRecord->save();
        } else {
            $this->records->create($data);
        }

        $auditAction = $isOverride ? 'university.graduation_manual_override' : 'university.graduation_approve';
        $this->auditLog->record($approvedByUserId, $auditAction, 'Student', $studentId, null, $data + ['manual_override' => $isOverride, 'override_reason' => $isOverride ? $overrideReason : null]);

        if ($isOverride) {
            Log::warning('Graduation manually approved (requirements bypassed)', ['student_id' => $studentId, 'university_id' => $universityId, 'certificate_number' => $certificateNumber, 'approved_by' => $approvedByUserId]);
        } else {
            Log::info('Graduation approved', ['student_id' => $studentId, 'university_id' => $universityId, 'certificate_number' => $certificateNumber]);
        }

        $this->notifications->notify(
            $student['user_id'],
            'graduation_approved',
            $locale === 'ar' ? 'تهانينا، تم اعتماد تخرجك!' : "Congratulations, your graduation was approved!",
            $locale === 'ar'
                ? "رقم الشهادة: {$certificateNumber}. يمكنك عرض شهادتك وسجلك الأكاديمي من صفحة التخرج."
                : "Certificate number: {$certificateNumber}. View your certificate and academic record on the Graduation page.",
            '/student/graduation'
        );

        return ['success' => true, 'message' => $locale === 'ar'
            ? "تم اعتماد تخرج الطالب. رقم الشهادة: {$certificateNumber}."
            : "Graduation approved. Certificate number: {$certificateNumber}."];
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function revoke(int $studentId, int $universityId, int $revokedByUserId, string $reason, string $locale = 'ar', ?int $facultyId = null): array
    {
        $record = $this->records->findByStudent($studentId);
        if (!$record || (int) $record->university_id !== $universityId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يوجد سجل تخرج لهذا الطالب ضمن جامعتك.'
                : 'No graduation record for this student within your university.'];
        }
        if ($facultyId !== null) {
            $recordStudent = $this->students->find($studentId);
            if (!$recordStudent || (int) $recordStudent->faculty_id !== $facultyId) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'لا يوجد سجل تخرج لهذا الطالب ضمن كليتك.'
                    : 'No graduation record for this student within your faculty.'];
            }
        }
        if ($record->status === 'revoked') {
            return ['success' => false, 'message' => $locale === 'ar' ? 'سجل التخرج ملغى بالفعل.' : 'This graduation record is already revoked.'];
        }
        if (trim($reason) === '') {
            return ['success' => false, 'message' => $locale === 'ar' ? 'سبب الإلغاء مطلوب.' : 'A revoke reason is required.'];
        }

        $before = $record->toArray();
        $record->fill([
            'status'        => 'revoked',
            'revoked_by'    => $revokedByUserId,
            'revoked_at'    => now(),
            'revoke_reason' => $reason,
        ]);
        $record->save();

        $this->auditLog->record($revokedByUserId, 'university.graduation_revoke', 'Student', $studentId, $before, $record->toArray());
        Log::info('Graduation revoked', ['student_id' => $studentId, 'university_id' => $universityId]);

        $student = $this->students->find($studentId);
        if ($student) {
            $this->notifications->notify(
                $student->user_id,
                'graduation_revoked',
                $locale === 'ar' ? 'تم إلغاء اعتماد تخرجك' : 'Your graduation approval was revoked',
                $locale === 'ar' ? "السبب: {$reason}" : "Reason: {$reason}",
                '/student/graduation'
            );
        }

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم إلغاء سجل التخرج.' : 'Graduation record revoked.'];
    }

    /**
     * سحب إلغاء التخرج: بيرجّع سجل revoked لحالة graduated بنفس رقم الشهادة
     * وبنفس البيانات اللي كانت وقت الاعتماد (من غير إصدار شهادة جديدة ولا
     * فحص أهلية) — عكس revoke() بالظبط. لو عايز بيانات/شهادة جديدة استخدم
     * approve() (Review & Approve).
     *
     * @return array{success:bool, message:string}
     */
    public function restore(int $studentId, int $universityId, int $restoredByUserId, string $locale = 'ar', ?int $facultyId = null): array
    {
        $record = $this->records->findByStudent($studentId);
        if (!$record || (int) $record->university_id !== $universityId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يوجد سجل تخرج لهذا الطالب ضمن جامعتك.'
                : 'No graduation record for this student within your university.'];
        }
        if ($facultyId !== null) {
            $recordStudent = $this->students->find($studentId);
            if (!$recordStudent || (int) $recordStudent->faculty_id !== $facultyId) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'لا يوجد سجل تخرج لهذا الطالب ضمن كليتك.'
                    : 'No graduation record for this student within your faculty.'];
            }
        }
        if ($record->status !== 'revoked') {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'سجل التخرج غير ملغى.'
                : 'This graduation record is not revoked.'];
        }

        $before = $record->toArray();
        $record->fill([
            'status'        => 'graduated',
            'revoked_by'    => null,
            'revoked_at'    => null,
            'revoke_reason' => null,
        ]);
        $record->save();

        $this->auditLog->record($restoredByUserId, 'university.graduation_restore', 'Student', $studentId, $before, $record->toArray());
        Log::info('Graduation revoke withdrawn (restored)', ['student_id' => $studentId, 'university_id' => $universityId, 'certificate_number' => $record->certificate_number]);

        $student = $this->students->find($studentId);
        if ($student) {
            $this->notifications->notify(
                $student->user_id,
                'graduation_approved',
                $locale === 'ar' ? 'تم استرجاع اعتماد تخرجك' : 'Your graduation approval was restored',
                $locale === 'ar'
                    ? "رقم الشهادة: {$record->certificate_number}. يمكنك عرض شهادتك من صفحة التخرج."
                    : "Certificate number: {$record->certificate_number}. You can view your certificate on the Graduation page.",
                '/student/graduation'
            );
        }

        return ['success' => true, 'message' => $locale === 'ar'
            ? 'تم سحب الإلغاء وإرجاع الطالب متخرجًا.'
            : 'Revocation withdrawn — the student is graduated again.'];
    }

    /**
     * تعديل مباشر لبيانات شهادة صادرة بالفعل — لخطأ إملائي أو درجة/برنامج
     * غلط، حيث دورة revoke()+approve() كاملة (رقم شهادة جديد، صف
     * "revoked" فاضل، إشعار الطالب كأنه اتخرج جديد) هتكون الأداة الغلط.
     * عمدًا ضيقة: certificate_number/status/approved_by/revoke fields
     * عمرها ما بتتلمس هنا — بس حقول الوصف اللي الموظف يقدر يعيد كتابتها.
     *
     * @return array{success:bool, message:string}
     */
    public function editCertificate(
        int $studentId,
        int $universityId,
        int $editedByUserId,
        array $fields,
        string $locale = 'ar',
        ?int $facultyId = null
    ): array {
        $record = $this->records->findByStudent($studentId);
        if (!$record || (int) $record->university_id !== $universityId) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يوجد سجل تخرج لهذا الطالب ضمن جامعتك.'
                : 'No graduation record for this student within your university.'];
        }
        if ($facultyId !== null) {
            $recordStudent = $this->students->find($studentId);
            if (!$recordStudent || (int) $recordStudent->faculty_id !== $facultyId) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'لا يوجد سجل تخرج لهذا الطالب ضمن كليتك.'
                    : 'No graduation record for this student within your faculty.'];
            }
        }
        if ($record->status !== 'graduated') {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يمكن تعديل سجل ملغى — يمكن اعتماد الطالب من جديد بدلاً من ذلك.'
                : 'A revoked record can\'t be edited — approve the student again instead.'];
        }

        $graduationDate = trim((string) ($fields['graduation_date'] ?? ''));
        if ($graduationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $graduationDate)) {
            return ['success' => false, 'message' => $locale === 'ar' ? 'تاريخ تخرج غير صالح.' : 'Invalid graduation date.'];
        }

        $finalGpa = $fields['final_gpa'] ?? null;
        if ($finalGpa !== null && $finalGpa !== '') {
            if (!is_numeric($finalGpa) || (float) $finalGpa < 0 || (float) $finalGpa > 4) {
                return ['success' => false, 'message' => $locale === 'ar' ? 'المعدل التراكمي لازم يكون رقم بين 0 و 4.' : 'Final GPA must be a number between 0 and 4.'];
            }
        }

        $before = $record->toArray();

        $editable = [
            'graduation_date'    => $graduationDate !== '' ? $graduationDate : $record->graduation_date,
            'final_gpa'          => ($finalGpa !== null && $finalGpa !== '') ? (float) $finalGpa : null,
            'degree_title_ar'    => $this->blankToNull($fields['degree_title_ar'] ?? null),
            'degree_title_en'    => $this->blankToNull($fields['degree_title_en'] ?? null),
            'faculty_name_ar'    => $this->blankToNull($fields['faculty_name_ar'] ?? null),
            'faculty_name_en'    => $this->blankToNull($fields['faculty_name_en'] ?? null),
            'department_name_ar' => $this->blankToNull($fields['department_name_ar'] ?? null),
            'department_name_en' => $this->blankToNull($fields['department_name_en'] ?? null),
            'notes'              => $this->blankToNull($fields['notes'] ?? null),
        ];

        $record->fill($editable);
        $record->save();

        $this->auditLog->record($editedByUserId, 'university.graduation_certificate_edit', 'Student', $studentId, $before, $record->toArray());
        Log::info('Graduation certificate edited', ['student_id' => $studentId, 'university_id' => $universityId, 'certificate_number' => $record->certificate_number, 'edited_by' => $editedByUserId]);

        $student = $this->students->find($studentId);
        if ($student) {
            $this->notifications->notify(
                $student->user_id,
                'graduation_certificate_edited',
                $locale === 'ar' ? 'تم تحديث بيانات شهادة تخرجك' : 'Your graduation certificate was updated',
                $locale === 'ar'
                    ? "رقم الشهادة: {$record->certificate_number}. تم تحديث بعض بياناتها — يمكنك مراجعتها من صفحة التخرج."
                    : "Certificate number: {$record->certificate_number}. Some details were updated — review it on the Graduation page.",
                '/student/graduation'
            );
        }

        return ['success' => true, 'message' => $locale === 'ar' ? 'تم تحديث بيانات الشهادة.' : 'Certificate details updated.'];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * بيجمّع الـ transcript الكامل لطالب واحد: بروفايل + تقييم(ات)
     * مشروعه النهائية + سجل تخرجه (لو موجود) — للقراءة فقط.
     */
    public function transcriptFor(int $studentId): array
    {
        $student = $this->students->withProfileDetails($studentId);
        if (!$student) {
            return ['student' => null, 'grades' => [], 'graduation' => null];
        }

        $grades = $this->grades->finalGradesForUser((int) $student['user_id']);
        $graduation = $this->records->findByStudent($studentId);

        return ['student' => $student, 'grades' => $grades, 'graduation' => $graduation];
    }

    /** رقم شهادة تسلسلي لكل جامعة/سنة: UIP-{university_id}-{year}-{0001}. */
    private function nextCertificateNumber(int $universityId): string
    {
        $year = (int) now()->format('Y');
        $sequence = $this->records->countForUniversityYear($universityId, $year) + 1;
        // لو في سجل اتمسح/اتعدّل بيخلّي العدّاد يتصادم مع رقم موجود (UNIQUE) — نكمّل لحد أول رقم فاضي.
        do {
            $number = sprintf('UIP-%d-%d-%04d', $universityId, $year, $sequence++);
        } while (DB::table('graduation_records')->where('certificate_number', $number)->exists());
        return $number;
    }
}
