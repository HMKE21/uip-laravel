<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * يجمّع "الناس" اللي ورا مشروع منشور لصفحة المشروع العامة
 * (GET /api/v1/public/projects/{slug}): صاحب المشروع (الطالب)، الدكاترة/
 * المشرفين، وفريق العمل — بأسمائهم وبيانات تواصلهم.
 *
 * الإيميلات (واللينكات الشخصية) بتتعرض بس لو $canSeeContacts = true،
 * يعني المشاهد مسجّل دخول، أو الأدمن فعّل config('site.project_contacts_public').
 * الضيف بياخد الأسماء والأدوار من غير إيميل — حماية لبيانات الطلبة من الـ scraping.
 * الفلاج ده بيتحكم فيه كله من مكان واحد (config/site.php).
 *
 * مصادر المشرفين (بيتدمجوا ويتشال التكرار بالإيميل أو بالاسم):
 *  1) supervisor_assignments المربوطة بالمشروع مباشرة (project_id) → supervisors
 *  2) أعضاء الفريق اللي دورهم أكاديمي (supervisor / professor / ...)
 *  3) projects.supervisor_name (نص حر كتبه الطالب)
 */
class ProjectPublicShowcaseService
{
    /** أدوار عضو الفريق اللي بتتعامل كإشراف أكاديمي مش كزميل طالب. */
    private const ACADEMIC_ROLES = [
        'supervisor', 'professor', 'principal_investigator', 'teaching_assistant',
    ];

    /**
     * @param array<string,mixed> $project صف المشروع من findPublishedBySlug()
     * @return array{owner:?array,supervisors:array,team:array}
     */
    public function people(array $project, bool $canSeeContacts): array
    {
        $projectId = (int) $project['id'];

        return [
            'owner'       => $this->owner((int) $project['owner_id'], $canSeeContacts),
            'supervisors' => $this->supervisors($project, $canSeeContacts),
            'team'        => $this->team($projectId, $canSeeContacts),
        ];
    }

    private function owner(int $ownerId, bool $canSeeContacts): ?array
    {
        $row = DB::table('users as u')
            ->leftJoin('students as st', 'st.user_id', '=', 'u.id')
            ->where('u.id', $ownerId)
            ->select('u.full_name', 'u.email', 'u.avatar_path',
                'st.student_number', 'st.academic_year', 'st.bio', 'st.skills', 'st.social_links')
            ->first();

        if (!$row) {
            return null;
        }

        $skills = $this->decodeList($row->skills);

        return [
            'full_name'      => $row->full_name,
            'avatar_url'     => $this->publicPath($row->avatar_path),
            'student_number' => $row->student_number,
            'academic_year'  => $row->academic_year !== null ? (int) $row->academic_year : null,
            'bio'            => $row->bio ?: null,
            'skills'         => array_slice($skills, 0, 12),
            'email'          => $canSeeContacts ? $row->email : null,
            'social_links'   => $canSeeContacts ? $this->safeSocialLinks($row->social_links) : [],
        ];
    }

    private function supervisors(array $project, bool $canSeeContacts): array
    {
        $projectId = (int) $project['id'];
        $out = [];

        // 1) إسناد مباشر على المشروع
        $assigned = DB::table('supervisor_assignments as sa')
            ->join('supervisors as s', 's.id', '=', 'sa.supervisor_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('sa.project_id', $projectId)
            ->where('s.status', 'active')
            ->select('s.full_name', 's.email', 's.title', 's.department', 'u.avatar_path')
            ->get();
        foreach ($assigned as $r) {
            $out[] = [
                'name'       => $r->full_name,
                'title'      => $r->title,
                'department' => $r->department,
                'role'       => 'supervisor',
                'avatar_url' => $this->publicPath($r->avatar_path),
                'email'      => $r->email,
            ];
        }

        // 2) أعضاء الفريق بدور أكاديمي
        $members = DB::table('project_team_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('academic_staff as a', 'a.user_id', '=', 'u.id')
            ->leftJoin('academic_ranks as r', 'r.id', '=', 'a.academic_rank_id')
            ->where('m.project_id', $projectId)
            ->where('m.status', 'accepted')
            ->whereIn('m.role', self::ACADEMIC_ROLES)
            ->select('m.role', 'm.member_name', 'm.invited_email',
                'u.full_name as user_name', 'u.email as user_email', 'u.avatar_path',
                'r.name_en as rank_en', 'r.name_ar as rank_ar')
            ->get();
        foreach ($members as $r) {
            $name = $r->user_name ?: $r->member_name;
            if (!$name && !$r->invited_email) {
                continue;
            }
            $out[] = [
                'name'       => $name ?: $r->invited_email,
                'title'      => null,
                'title_en'   => $r->rank_en,
                'title_ar'   => $r->rank_ar,
                'department' => null,
                'role'       => $r->role,
                'avatar_url' => $this->publicPath($r->avatar_path),
                'email'      => $r->user_email ?: $r->invited_email,
            ];
        }

        // 3) الاسم الحر اللي الطالب كتبه
        $free = trim((string) ($project['supervisor_name'] ?? ''));
        if ($free !== '') {
            $out[] = [
                'name' => $free, 'title' => null, 'department' => null,
                'role' => 'supervisor', 'avatar_url' => null, 'email' => null,
            ];
        }

        // إزالة التكرار: الإيميل أولًا، وبعدين الاسم. الإدخال الأغنى بيفضل.
        $seen = [];
        $unique = [];
        foreach ($out as $s) {
            $key = $s['email'] ? 'e:' . mb_strtolower($s['email']) : 'n:' . mb_strtolower($s['name']);
            $nameKey = 'n:' . mb_strtolower($s['name']);
            if (isset($seen[$key]) || isset($seen[$nameKey])) {
                continue;
            }
            $seen[$key] = $seen[$nameKey] = true;
            $unique[] = $s;
        }

        if (!$canSeeContacts) {
            foreach ($unique as &$s) {
                $s['email'] = null;
            }
            unset($s);
        }

        return $unique;
    }

    private function team(int $projectId, bool $canSeeContacts): array
    {
        $rows = DB::table('project_team_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.project_id', $projectId)
            ->where('m.status', 'accepted')
            ->whereNotIn('m.role', self::ACADEMIC_ROLES)
            ->orderBy('m.created_at')
            ->select('m.id', 'm.role', 'm.member_name', 'm.invited_email', 'm.academic_year',
                'm.student_number', 'u.full_name as user_name', 'u.email as user_email', 'u.avatar_path')
            ->get();

        return $rows->map(function ($r) use ($canSeeContacts) {
            return [
                'id'             => $r->id,
                'name'           => $r->user_name ?: ($r->member_name ?: ($r->invited_email ?: 'Team member')),
                'role'           => $r->role,
                'academic_year'  => $r->academic_year,
                'student_number' => $r->student_number,
                'avatar_url'     => $this->publicPath($r->avatar_path),
                'email'          => $canSeeContacts ? ($r->user_email ?: $r->invited_email) : null,
            ];
        })->all();
    }

    private function publicPath(?string $path): ?string
    {
        return $path ? '/' . ltrim($path, '/') : null;
    }

    /** @return string[] */
    private function decodeList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * لينكات السوشيال بتاعة الطالب — http(s) بس (حماية من javascript: وغيره)،
     * والمفتاح لازم يبقى اسم بسيط (github/linkedin/portfolio...).
     * @return array<string,string>
     */
    private function safeSocialLinks($raw): array
    {
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $url) {
            if (is_string($key) && preg_match('/^[a-z_]{2,20}$/i', $key)
                && is_string($url) && preg_match('#^https?://#i', $url)) {
                $out[strtolower($key)] = $url;
            }
        }
        return $out;
    }
}
