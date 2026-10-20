<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * بيبني "بروفايل" آمن عن اليوزر الموثّق نفسه (من الداتابيز، مش من الفرونت)
 * عشان الـ AI Assistant يعرف مين بيكلمه: الاسم، الدور، الجامعة/الكلية/القسم،
 * السنة الدراسية، مشاريعه وحالتها، وأرقام عامة عن نطاقه (للجامعة/الكلية).
 *
 * RBAC: الدالة بتاخد $userId من توكن الـ uip.auth بس — أبدًا من body الطلب —
 * وكل استعلام مقيّد بـ id اليوزر ده أو بنطاقه هو (university_id بتاعه…).
 * مفيش بيانات يوزر تاني بتتسرّب. وكل بلوك لوحده جوّه try/catch: أي فشل في
 * جزء مايوقفش الشات، بيتخطّى الجزء ده بس.
 *
 * مفيش بيانات حساسة هنا: لا باسورد ولا إيميل ولا تليفون ولا توكنز.
 */
class AiUserContextService
{
    private const MAX_PROJECTS = 6;

    /**
     * @return array<string,mixed> مفاتيح: name, role, language, university, faculty,
     *   department, details (map نصي), projects (list)، stats (map أرقام)
     */
    public function forUser(int $userId, ?string $role): array
    {
        $profile = [];

        try {
            $user = DB::table('users')->where('id', $userId)->first(['full_name', 'preferred_language', 'status', 'created_at']);
            if ($user) {
                $profile['name'] = (string) $user->full_name;
                $profile['language'] = (string) $user->preferred_language;
                $profile['account_status'] = (string) $user->status;
                $profile['member_since'] = $user->created_at ? substr((string) $user->created_at, 0, 10) : null;
            }
        } catch (\Throwable $e) {
            $this->warn('user', $e);
        }

        $profile['role'] = $role;

        try {
            switch ($role) {
                case 'student':
                    $profile = array_merge($profile, $this->student($userId));
                    break;
                case 'university':
                    $profile = array_merge($profile, $this->university($userId));
                    break;
                case 'faculty':
                    $profile = array_merge($profile, $this->faculty($userId));
                    break;
                case 'academic_staff':
                    $profile = array_merge($profile, $this->academicStaff($userId));
                    break;
                case 'supervisor':
                    $profile = array_merge($profile, $this->supervisor($userId));
                    break;
                default:
                    // admin / security / data analyst: الاسم والدور كفاية.
                    break;
            }
        } catch (\Throwable $e) {
            $this->warn('role:' . (string) $role, $e);
        }

        return array_filter($profile, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** نص جاهز للـ system prompt (بالإنجليزي عشان الموديل؛ القيم زي ما هي في الداتا). */
    public function toPromptText(array $profile): string
    {
        if (!$profile) {
            return '';
        }

        $lines = ['[Verified profile of the signed-in user — facts from the platform database about THIS user only]'];

        $labels = [
            'name' => 'Name', 'role' => 'Role', 'language' => 'Preferred language', 'account_status' => 'Account status',
            'member_since' => 'Member since',
        ];
        foreach ($labels as $key => $label) {
            if (!empty($profile[$key])) {
                $lines[] = "{$label}: {$profile[$key]}";
            }
        }

        foreach (['university', 'faculty', 'department'] as $scope) {
            if (!empty($profile[$scope])) {
                $lines[] = ucfirst($scope) . ': ' . $profile[$scope];
            }
        }

        foreach ((array) ($profile['details'] ?? []) as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        if (!empty($profile['stats'])) {
            $bits = [];
            foreach ($profile['stats'] as $label => $value) {
                $bits[] = "{$label}={$value}";
            }
            $lines[] = 'Scope numbers (current): ' . implode(', ', $bits);
        }

        if (!empty($profile['projects'])) {
            $lines[] = 'Their projects (most recent first):';
            foreach ($profile['projects'] as $p) {
                $row = '- ' . $p['title'] . ' [status: ' . $p['status'] . ']';
                if (!empty($p['category'])) {
                    $row .= ' [category: ' . $p['category'] . ']';
                }
                if (isset($p['readiness']) && $p['readiness'] !== null) {
                    $row .= ' [AI readiness: ' . $p['readiness'] . '/100]';
                }
                $lines[] = $row;
            }
        }

        return implode("\n", $lines);
    }

    // -- Per-role builders -------------------------------------------------------

    private function student(int $userId): array
    {
        $out = [];
        $s = DB::table('students')->where('user_id', $userId)->first();
        if (!$s) {
            return $out;
        }

        if ($s->university_id) {
            $u = DB::table('universities')->where('id', $s->university_id)->first(['official_name_en', 'official_name_ar']);
            $out['university'] = $u ? $this->bilingual($u->official_name_en, $u->official_name_ar) : null;
        } else {
            $out['details']['University status'] = 'not affiliated with a university yet (can send a join request from the portal)';
        }

        $faculty = $s->faculty_id ? DB::table('faculties')->where('id', $s->faculty_id)->first(['name_en', 'name_ar']) : null;
        $out['faculty'] = $faculty ? $this->bilingual($faculty->name_en, $faculty->name_ar) : ($s->faculty ?: null);

        $dept = $s->department_id ? DB::table('departments')->where('id', $s->department_id)->first(['name_en', 'name_ar']) : null;
        $out['department'] = $dept ? $this->bilingual($dept->name_en, $dept->name_ar) : ($s->department ?: null);

        $out['details']['Student number'] = $s->student_number ?: null;
        $out['details']['Academic year'] = $s->academic_year ?: null;
        $out['details']['Current semester'] = $s->current_semester ?: null;
        $out['details']['GPA'] = $s->gpa !== null ? (string) $s->gpa : null;
        $out['details']['Study start'] = $s->study_start_date ?: null;
        $out['details']['Expected graduation'] = $s->expected_graduation_date ?: null;
        $skills = is_string($s->skills) ? json_decode($s->skills, true) : null;
        if (is_array($skills) && $skills) {
            $out['details']['Skills'] = implode(', ', array_slice(array_map('strval', $skills), 0, 15));
        }

        $out['projects'] = $this->projectsFor($userId);

        $out['stats'] = [
            'projects_total' => (int) DB::table('projects')->where('owner_id', $userId)->whereNull('deleted_at')->count(),
        ];

        try {
            $graduated = DB::table('graduation_records')->where('student_id', $s->id)->where('status', 'graduated')->exists();
            if ($graduated) {
                $out['details']['Graduation'] = 'has an approved graduation record';
            }
        } catch (\Throwable $e) {
            $this->warn('student.graduation', $e);
        }

        return $out;
    }

    private function university(int $userId): array
    {
        $out = [];
        $u = DB::table('universities')->where('user_id', $userId)->first();
        if (!$u) {
            return $out;
        }

        $out['university'] = $this->bilingual($u->official_name_en, $u->official_name_ar);
        $out['details']['Location'] = trim(($u->city ?? '') . ', ' . ($u->country ?? ''), ', ') ?: null;
        $out['details']['Verification status'] = $u->verification_status ?: null;
        $out['details']['Verification expires'] = $u->verification_expires_at ? substr((string) $u->verification_expires_at, 0, 10) : null;
        $out['details']['Public profile'] = $u->is_public ? 'public' : 'private';

        $uid = (int) $u->id;
        $stats = [];
        $count = function (string $key, \Closure $q) use (&$stats) {
            try {
                $stats[$key] = (int) $q();
            } catch (\Throwable $e) {
                $this->warn('university.' . $key, $e);
            }
        };
        $count('students', fn () => DB::table('students')->where('university_id', $uid)->count());
        $count('faculties', fn () => DB::table('faculties')->where('university_id', $uid)->count());
        $count('academic_staff', fn () => DB::table('academic_staff')->where('university_id', $uid)->count());
        $count('projects_total', fn () => DB::table('projects')->where('university_id', $uid)->whereNull('deleted_at')->count());
        $count('projects_awaiting_review', fn () => DB::table('projects')->where('university_id', $uid)->whereNull('deleted_at')->whereIn('status', ['submitted', 'under_review'])->count());
        $count('projects_published', fn () => DB::table('projects')->where('university_id', $uid)->whereNull('deleted_at')->where('status', 'published')->count());
        $count('pending_join_requests', fn () => DB::table('student_university_requests')->where('university_id', $uid)->where('status', 'pending')->count());
        $count('graduates', fn () => DB::table('graduation_records')->where('university_id', $uid)->where('status', 'graduated')->count());
        $out['stats'] = $stats;

        return $out;
    }

    private function faculty(int $userId): array
    {
        $out = [];
        $f = DB::table('faculties')->where('user_id', $userId)->first();
        if (!$f) {
            return $out;
        }

        $out['faculty'] = $this->bilingual($f->name_en, $f->name_ar);
        $u = DB::table('universities')->where('id', $f->university_id)->first(['official_name_en', 'official_name_ar']);
        $out['university'] = $u ? $this->bilingual($u->official_name_en, $u->official_name_ar) : null;

        $fid = (int) $f->id;
        $stats = [];
        $count = function (string $key, \Closure $q) use (&$stats) {
            try {
                $stats[$key] = (int) $q();
            } catch (\Throwable $e) {
                $this->warn('faculty.' . $key, $e);
            }
        };
        $count('departments', fn () => DB::table('departments')->whereNull('deleted_at')->where('faculty_id', $fid)->count());
        $count('students', fn () => DB::table('students')->where('faculty_id', $fid)->count());
        $count('academic_staff', fn () => DB::table('academic_staff')->where('faculty_id', $fid)->count());
        $out['stats'] = $stats;

        return $out;
    }

    private function academicStaff(int $userId): array
    {
        $out = [];
        $a = DB::table('academic_staff')->where('user_id', $userId)->first();
        if (!$a) {
            return $out;
        }

        $u = DB::table('universities')->where('id', $a->university_id)->first(['official_name_en', 'official_name_ar']);
        $out['university'] = $u ? $this->bilingual($u->official_name_en, $u->official_name_ar) : null;
        $fac = $a->faculty_id ? DB::table('faculties')->where('id', $a->faculty_id)->first(['name_en', 'name_ar']) : null;
        $out['faculty'] = $fac ? $this->bilingual($fac->name_en, $fac->name_ar) : null;
        $dep = $a->department_id ? DB::table('departments')->where('id', $a->department_id)->first(['name_en', 'name_ar']) : null;
        $out['department'] = $dep ? $this->bilingual($dep->name_en, $dep->name_ar) : null;
        $out['details']['Staff number'] = $a->staff_number ?: null;

        try {
            $rank = $a->academic_rank_id ? DB::table('academic_ranks')->where('id', $a->academic_rank_id)->first() : null;
            if ($rank) {
                $out['details']['Academic rank'] = $this->bilingual($rank->name_en ?? null, $rank->name_ar ?? null);
            }
        } catch (\Throwable $e) {
            $this->warn('academic_staff.rank', $e);
        }

        try {
            $out['stats'] = [
                'exams_created' => (int) DB::table('exams')->where('created_by_academic_staff_id', $a->id)->count(),
                'question_banks' => (int) DB::table('question_banks')->where('created_by_academic_staff_id', $a->id)->count(),
            ];
        } catch (\Throwable $e) {
            $this->warn('academic_staff.stats', $e);
        }

        return $out;
    }

    private function supervisor(int $userId): array
    {
        $out = [];
        $s = DB::table('supervisors')->where('user_id', $userId)->first();
        if (!$s) {
            return $out;
        }

        $u = DB::table('universities')->where('id', $s->university_id)->first(['official_name_en', 'official_name_ar']);
        $out['university'] = $u ? $this->bilingual($u->official_name_en, $u->official_name_ar) : null;
        $out['department'] = $s->department ?: null;
        $out['details']['Title'] = $s->title ?: null;

        return $out;
    }

    // -- Helpers ------------------------------------------------------------------

    /** @return list<array{title:string,status:string,category:?string,readiness:?string}> */
    private function projectsFor(int $userId): array
    {
        $rows = DB::table('projects')
            ->where('owner_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_PROJECTS)
            ->get(['id', 'title_en', 'title_ar', 'status', 'category']);

        $out = [];
        foreach ($rows as $r) {
            $readiness = null;
            try {
                $score = DB::table('ai_readiness_scores')->where('project_id', $r->id)->orderByDesc('id')->value('overall_score');
                $readiness = $score !== null ? (string) round((float) $score) : null;
            } catch (\Throwable $e) {
                // الجدول/العمود مش لازم يكون موجود في كل بيئة.
            }
            $out[] = [
                'title'     => $this->bilingual($r->title_en, $r->title_ar) ?: 'Untitled',
                'status'    => (string) $r->status,
                'category'  => $r->category ?: null,
                'readiness' => $readiness,
            ];
        }
        return $out;
    }

    private function bilingual(?string $en, ?string $ar): ?string
    {
        $en = trim((string) $en);
        $ar = trim((string) $ar);
        if ($en !== '' && $ar !== '' && $en !== $ar) {
            return "{$en} / {$ar}";
        }
        return $en !== '' ? $en : ($ar !== '' ? $ar : null);
    }

    private function warn(string $part, \Throwable $e): void
    {
        Log::warning('AI user context block skipped', ['part' => $part, 'error' => $e->getMessage()]);
    }
}
