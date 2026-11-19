<?php

namespace App\Services\Identity;

use App\Services\AcademicStaffManagementService;
use App\Services\StudentManagementService;
use App\Support\BilingualName;
use App\Support\DisposableEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5: استيراد CSV/XLSX للطلاب أو هيئة التدريس مع معاينة (dry run) وسجل عمليات.
 * بنبني على importRows() الموجودة (نفس التحققات والأعمدة)، والصندوق المؤسسي بيتعمل تلقائيًا لكل صف ناجح (hook الدعوة) لو auto_provision شغّال.
 * المعاينة بتتحقق من الإيميل/الاسم/التكرار/وجود الحساب من غير ما تكتب حاجة.
 */
class ProvisioningImportService
{
    public const MAX_ROWS = 2000;
    public const TYPES = ['student', 'staff'];

    public function __construct(private StudentManagementService $students, private AcademicStaffManagementService $staff)
    {
    }

    private function fail(string $m, int $code = 422): never
    {
        throw new \DomainException($m, $code);
    }

    public static function template(string $type): string
    {
        $cols = $type === 'staff'
            ? ['name_ar', 'name_en', 'email', 'staff_number', 'department', 'rank', 'bio', 'password']
            : ['name_ar', 'name_en', 'email', 'student_number', 'faculty', 'department', 'academic_year', 'current_semester', 'group', 'password'];
        $example = $type === 'staff'
            ? ['أحمد علي', 'Ahmed Ali', 'ahmed.ali@example.com', 'S-1001', '', '', '', '']
            : ['سارة محمود', 'Sara Mahmoud', 'sara@example.com', '2024001', '', '', '1', '1', '', ''];
        return "\xEF\xBB\xBF" . implode(',', $cols) . "\n" . implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $example)) . "\n";
    }

    /**
     * @param array<int,array<string,string>> $rows
     * @return array{run_id:int,dry_run:bool,total:int,imported:int,skipped:int,errors:string[],results:array}
     */
    public function run(int $universityId, int $actingUserId, string $type, array $rows, ?int $forcedFacultyId, string $locale, bool $dryRun, bool $sendEmail): array
    {
        if (!in_array($type, self::TYPES, true)) {
            $this->fail('type must be student or staff.');
        }
        if (!$rows) {
            $this->fail('The file has no rows.');
        }
        if (count($rows) > self::MAX_ROWS) {
            $this->fail('Too many rows (max ' . self::MAX_ROWS . ' per file). Split the file.', 413);
        }

        $tenantId = DB::table('mail_tenants')->where('university_id', $universityId)->value('id');
        if ($dryRun) {
            [$imported, $errors, $results] = $this->validate($rows, $locale);
        } elseif ($type === 'student') {
            $r = $this->students->importRows($rows, $universityId, $actingUserId, $forcedFacultyId, $locale, $sendEmail);
            [$imported, $errors, $results] = [(int) $r['imported'], $r['errors'], $r['results']];
        } else {
            $r = $this->staff->importRows($rows, $universityId, $actingUserId, $forcedFacultyId, $locale);
            $imported = (int) $r['success_count'];
            $results = $r['results'];
            $errors = [];
            foreach ($results as $x) {
                if (!$x['success']) {
                    $errors[] = "Row {$x['row']} ({$x['email']}): {$x['message']}";
                }
            }
        }

        $runId = DB::table('provisioning_runs')->insertGetId([
            'tenant_id' => $tenantId, 'university_id' => $universityId, 'created_by' => $actingUserId, 'kind' => 'csv', 'member_type' => $type, 'dry_run' => $dryRun ? 1 : 0,
            'total' => count($rows), 'created' => $imported, 'failed' => count($rows) - $imported, 'errors' => json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
        return ['run_id' => (int) $runId, 'dry_run' => $dryRun, 'total' => count($rows), 'imported' => $imported, 'skipped' => count($rows) - $imported, 'errors' => array_slice($errors, 0, 200), 'results' => $results];
    }

    /** معاينة: تحققات سريعة من غير كتابة. @return array{0:int,1:string[],2:array} */
    private function validate(array $rows, string $locale): array
    {
        $ar = $locale === 'ar';
        $seen = [];
        $existing = [];
        $emails = array_values(array_unique(array_filter(array_map(fn ($r) => mb_strtolower(trim((string) ($r['email'] ?? ''))), $rows))));
        foreach (array_chunk($emails, 500) as $chunk) {
            foreach (DB::table('users')->whereIn(DB::raw('LOWER(email)'), $chunk)->pluck('email') as $e) {
                $existing[mb_strtolower($e)] = true;
            }
        }
        $ok = 0;
        $errors = [];
        $results = [];
        foreach (array_values($rows) as $i => $row) {
            $n = $i + 2;
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $msg = null;
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg = $ar ? 'البريد الإلكتروني ناقص أو غير صالح.' : 'Missing or invalid email.';
            } elseif (isset($seen[$email])) {
                $msg = $ar ? 'البريد مكرر داخل الملف.' : 'Duplicate email inside the file.';
            } elseif (isset($existing[$email])) {
                $msg = $ar ? 'يوجد حساب بهذا البريد بالفعل.' : 'An account with this email already exists.';
            } elseif ($why = DisposableEmail::problem($email, $locale, false)) {
                $msg = $why;
            } else {
                $names = BilingualName::resolve((string) ($row['name_ar'] ?? $row['arabic_name'] ?? ''), (string) ($row['name_en'] ?? $row['english_name'] ?? ''), $locale);
                if (!$names['ok']) {
                    $msg = implode(' ', $names['errors']);
                }
            }
            if ($email !== '') {
                $seen[$email] = true;
            }
            if ($msg) {
                $errors[] = "Row {$n} ({$email}): {$msg}";
                $results[] = ['row' => $n, 'email' => $email, 'success' => false, 'message' => $msg];
            } else {
                $ok++;
                $results[] = ['row' => $n, 'email' => $email, 'success' => true, 'message' => $ar ? 'جاهز للاستيراد.' : 'Ready to import.'];
            }
        }
        return [$ok, $errors, $results];
    }

    public function history(int $universityId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $q = DB::table('provisioning_runs')->where('university_id', $universityId);
        $total = (clone $q)->count();
        $items = $q->orderByDesc('id')->forPage($page, $perPage)->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'kind' => $r->kind, 'member_type' => $r->member_type, 'dry_run' => (bool) $r->dry_run, 'total' => (int) $r->total,
            'created' => (int) $r->created, 'failed' => (int) $r->failed, 'errors' => $r->errors ? json_decode($r->errors, true) : [],
            'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String(),
        ])->all();
        return ['items' => $items, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }
}
