<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الإيميل الجامعي: دومين الجامعة + نمط التوليد (الرقم الجامعي | الاسم) ← عنوان رسمي لكل طالب.
 * الإدارة (الجامعة) بس هي اللي بتضبط وتولّد وتعدّل وتصدّر؛ الكلية بتشوف طلابها؛ الطالب بيشوف عنوانه.
 * العنوان بيتحوّل لإيميل الطالب الشخصي (users.email) — التحويل نفسه بيتضبط على سيرفر دومين الجامعة من ملف التصدير.
 */
class UniversityEmailService
{
    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    private function uni(array $scope): ?object
    {
        return DB::table('universities')->where('id', $scope['universityId'])->first();
    }

    private function students(array $scope)
    {
        $q = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.university_id', $scope['universityId']);
        return ($scope['role'] ?? null) === 'faculty' ? $q->where('s.faculty_id', (int) $scope['facultyId']) : $q;
    }

    private function cleanDomain(?string $d): ?string
    {
        $d = strtolower(trim((string) $d));
        $d = ltrim($d, '@');
        return $d === '' ? null : $d;
    }

    private function validDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{3,120}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d);
    }

    /** الجزء المحلي من العنوان حسب النمط، بدون تكرار. */
    private function localPart(object $s, string $pattern, string $domain, array &$taken): string
    {
        $base = '';
        if ($pattern === 'name') {
            $ascii = Str::of(Str::ascii((string) $s->full_name))->lower()->replaceMatches('/[^a-z\s]/', '')->trim()->explode(' ')->filter()->values();
            if ($ascii->count() >= 2) {
                $base = $ascii->first() . '.' . $ascii->last();
            } elseif ($ascii->count() === 1) {
                $base = $ascii->first();
            }
        }
        if ($base === '' && !empty($s->student_number)) {
            $base = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $s->student_number));
        }
        if ($base === '') {
            $base = 'student' . $s->id;
        }
        $local = $base;
        $i = 1;
        while (isset($taken[$local . '@' . $domain])) {
            $i++;
            $local = $base . $i;
        }
        return $local;
    }

    // ------------------------------------------------------------------ student

    public function mine(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $row = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.id', $scope['studentId'])->first(['s.university_email', 'u.email']);
        $uni = $this->uni($scope);
        return ['success' => true, 'message' => 'OK', 'data' => [
            'address' => $row->university_email ?? null,
            'forwards_to' => $row->email ?? null,
            'domain' => $uni->email_domain ?? null,
        ]];
    }

    // ------------------------------------------------------------------ management

    public function overview(array $scope, array $f, string $l): array
    {
        if (!in_array($scope['role'] ?? null, ['university', 'faculty'], true)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $uni = $this->uni($scope);
        $q = $this->students($scope);
        $total = (clone $q)->count();
        $with = (clone $q)->whereNotNull('s.university_email')->count();
        if (!empty($f['missing'])) {
            $q->whereNull('s.university_email');
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($f['q'])) . '%';
            $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('s.student_number', 'like', $like)->orWhere('s.university_email', 'like', $like));
        }
        $rows = $q->orderBy('u.full_name')->limit(300)->get(['s.id', 's.student_number', 's.university_email', 'u.full_name', 'u.email']);
        return ['success' => true, 'message' => 'OK', 'data' => [
            'settings' => ['domain' => $uni->email_domain ?? null, 'pattern' => $uni->email_pattern ?? 'number', 'can_edit' => $scope['role'] === 'university'],
            'stats' => ['total' => $total, 'with_email' => $with, 'missing' => $total - $with],
            'students' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->full_name, 'number' => $r->student_number, 'address' => $r->university_email, 'personal_email' => $r->email])->values()->all(),
        ]];
    }

    public function saveSettings(array $scope, array $d, string $l): array
    {
        if (($scope['role'] ?? null) !== 'university') {
            return $this->fail($l, 'للجامعة بس.', 'University only.', 403);
        }
        $domain = $this->cleanDomain($d['domain'] ?? null);
        if (!$domain || !$this->validDomain($domain)) {
            return $this->fail($l, 'الدومين غير صالح (مثال: uni.edu.eg).', 'Invalid domain (e.g. uni.edu.eg).');
        }
        $pattern = ($d['pattern'] ?? 'number') === 'name' ? 'name' : 'number';
        $old = DB::table('universities')->where('id', $scope['universityId'])->value('email_domain');
        DB::transaction(function () use ($scope, $domain, $pattern, $old) {
            DB::table('universities')->where('id', $scope['universityId'])->update(['email_domain' => $domain, 'email_pattern' => $pattern, 'updated_at' => now()]);
            // تغيير الدومين: العناوين المولَّدة بتتحدّث لنفس الجزء المحلي بالدومين الجديد.
            if ($old && $old !== $domain) {
                DB::table('students')->where('university_id', $scope['universityId'])->whereNotNull('university_email')->orderBy('id')
                    ->each(function ($st) use ($domain) {
                        DB::table('students')->where('id', $st->id)->update(['university_email' => strstr($st->university_email, '@', true) . '@' . $domain]);
                    });
            }
        });
        return ['success' => true, 'message' => $this->ar($l) ? 'تم حفظ الإعدادات.' : 'Settings saved.'];
    }

    /** يولّد عناوين لكل طالب ماعندوش عنوان. */
    public function generate(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'university') {
            return $this->fail($l, 'للجامعة بس.', 'University only.', 403);
        }
        $uni = $this->uni($scope);
        $domain = $uni->email_domain ?? null;
        if (!$domain) {
            return $this->fail($l, 'اضبط دومين الجامعة الأول.', 'Set the university domain first.');
        }
        $pattern = $uni->email_pattern ?? 'number';
        $taken = array_flip(DB::table('students')->whereNotNull('university_email')->pluck('university_email')->all());
        $created = 0;
        $students = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.university_id', $scope['universityId'])->whereNull('s.university_email')->orderBy('s.id')->get(['s.id', 's.student_number', 'u.full_name']);
        DB::transaction(function () use ($students, $pattern, $domain, &$taken, &$created) {
            foreach ($students as $s) {
                $addr = $this->localPart($s, $pattern, $domain, $taken) . '@' . $domain;
                $taken[$addr] = true;
                DB::table('students')->where('id', $s->id)->update(['university_email' => $addr]);
                $created++;
            }
        });
        return ['success' => true, 'message' => $this->ar($l) ? "تم توليد {$created} عنوان." : "Generated {$created} addresses.", 'data' => ['created' => $created]];
    }

    /** تعديل الجزء المحلي لعنوان طالب (أو حذف العنوان بقيمة فاضية). */
    public function setLocal(array $scope, int $studentId, ?string $local, string $l): array
    {
        if (($scope['role'] ?? null) !== 'university') {
            return $this->fail($l, 'للجامعة بس.', 'University only.', 403);
        }
        $s = DB::table('students')->where('id', $studentId)->where('university_id', $scope['universityId'])->first();
        if (!$s) {
            return $this->fail($l, 'الطالب غير موجود.', 'Student not found.', 404);
        }
        $local = strtolower(trim((string) $local));
        if ($local === '') {
            DB::table('students')->where('id', $studentId)->update(['university_email' => null]);
            return ['success' => true, 'message' => $this->ar($l) ? 'تم حذف العنوان.' : 'Address removed.'];
        }
        $domain = DB::table('universities')->where('id', $scope['universityId'])->value('email_domain');
        if (!$domain) {
            return $this->fail($l, 'اضبط دومين الجامعة الأول.', 'Set the university domain first.');
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/', $local)) {
            return $this->fail($l, 'الاسم غير صالح (حروف إنجليزية وأرقام ونقطة وشرطة فقط).', 'Invalid name (letters, digits, dot, dash only).');
        }
        $addr = $local . '@' . $domain;
        if (DB::table('students')->where('university_email', $addr)->where('id', '!=', $studentId)->exists()) {
            return $this->fail($l, 'العنوان مستخدم بالفعل.', 'That address is already taken.', 409);
        }
        DB::table('students')->where('id', $studentId)->update(['university_email' => $addr]);
        return ['success' => true, 'message' => $this->ar($l) ? 'تم تحديث العنوان.' : 'Address updated.'];
    }

    /** CSV: العنوان الجامعي ← الإيميل الشخصي (لضبط التحويل على سيرفر الدومين). */
    public function export(array $scope, string $l): array
    {
        if (!in_array($scope['role'] ?? null, ['university', 'faculty'], true)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $rows = $this->students($scope)->whereNotNull('s.university_email')->orderBy('s.university_email')->get(['s.university_email', 'u.email', 'u.full_name', 's.student_number']);
        $dir = storage_path('app/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/university_emails_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['university_email', 'forward_to', 'name', 'student_number']);
        foreach ($rows as $r) {
            // حماية من CSV injection في خانة الاسم
            $name = preg_match('/^[=+\-@\t\r]/', (string) $r->full_name) ? "'" . $r->full_name : $r->full_name;
            fputcsv($fh, [$r->university_email, $r->email, $name, $r->student_number]);
        }
        fclose($fh);
        return ['success' => true, 'data' => ['path' => $path, 'filename' => 'university-emails.csv']];
    }
}
