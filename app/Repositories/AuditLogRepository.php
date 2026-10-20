<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AuditLogRepository.php القديمة (Database::
 * connection() الخام -> DB facade). بس recent() هنا — القديمة فيها كمان
 * searchAdvanced() (فلترة متقدمة + بحث لصفحة Security & Audit Logs)، مش
 * منقولة عمدًا لحد ما بند 25 (Security portal) ييجي. AuditLogService
 * (بند 3) بتكتب عن طريق موديول AuditLog::create() مباشرة ولسه كده —
 * الكلاس ده بس للقراءة (ReportService::auditLogExport()، بند الـ Reports).
 */
class AuditLogRepository
{
    /** آخر N صف من audit_logs مع اسم الفاعل، الأحدث الأول. @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.full_name as actor_name')
            ->orderByDesc('a.created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * منقولة من forActionPrefixes() القديمة — بند 24 batch 6 (Team
     * Workspace Activity Timeline). كل صف action بيبدأ بأي prefix من
     * $prefixes (LIKE 'prefix%')، الأحدث الأول، مع اسم الفاعل.
     * @param string[] $prefixes مثال ['saved_dashboard.', 'kpi.']
     * @return array<int,array<string,mixed>>
     */
    public function forActionPrefixes(array $prefixes, int $limit = 30): array
    {
        if (!$prefixes) {
            return [];
        }

        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.full_name as actor_name')
            ->where(function ($w) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $w->orWhere('a.action', 'like', $prefix . '%');
                }
            })
            ->orderByDesc('a.created_at')
            ->limit(max(1, $limit));

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * منقولة من AuditLogRepository::forActor() القديمة بالظبط — كل صف
     * الفاعل فيه (user_id) هو $userId، الأحدث الأول، مع اسم الفاعل.
     * @return array<int,array<string,mixed>>
     */
    public function forActor(int $userId, int $limit = 100): array
    {
        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.full_name as actor_name')
            ->where('a.user_id', $userId)
            ->orderByDesc('a.created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * منقولة من searchPaginated() القديمة — بند 25 batch 4 (Logs). نفس
     * الفلاتر والـ joins بالظبط (subquery أول دور لكل مستخدم زي
     * UserRepository::allWithRoles()، بدل الـ LEFT JOIN المباشر
     * القديم على جدول r مؤقت — نفس النتيجة، أسلوب Eloquent).
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function searchPaginated(array $filters, int $page, int $perPage): array
    {
        $base = fn () => DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin(DB::raw('(SELECT ur1.user_id, ur1.role_id FROM user_roles ur1
                    WHERE ur1.assigned_at = (SELECT MIN(ur2.assigned_at) FROM user_roles ur2 WHERE ur2.user_id = ur1.user_id)) as ur'), 'ur.user_id', '=', 'a.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('universities as uni', 'uni.user_id', '=', 'a.user_id')
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $like = '%' . $filters['q'] . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('u.full_name', 'like', $like)
                        ->orWhere('u.email', 'like', $like)
                        ->orWhere('a.action', 'like', $like)
                        ->orWhere('a.ip_address', 'like', $like);
                });
            })
            ->when(!empty($filters['user']), function ($q) use ($filters) {
                $like = '%' . $filters['user'] . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like);
                });
            })
            ->when(!empty($filters['role']), fn ($q) => $q->where('r.slug', $filters['role']))
            ->when(!empty($filters['university']), function ($q) use ($filters) {
                $like = '%' . $filters['university'] . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('uni.official_name_en', 'like', $like)->orWhere('uni.official_name_ar', 'like', $like);
                });
            })
            ->when(!empty($filters['ip']), fn ($q) => $q->where('a.ip_address', 'like', '%' . $filters['ip'] . '%'))
            ->when(!empty($filters['module']), fn ($q) => $q->where('a.subject_type', $filters['module']))
            ->when(!empty($filters['action']), fn ($q) => $q->where('a.action', 'like', '%' . $filters['action'] . '%'))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('a.created_at', '>=', $filters['date_from'] . ' 00:00:00'))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('a.created_at', '<=', $filters['date_to'] . ' 23:59:59'));

        $total = (int) $base()->distinct()->count('a.id');

        $rows = $base()
            ->select(
                'a.*', 'u.full_name as actor_name', 'u.email as actor_email',
                'r.slug as actor_role', 'r.name_ar as actor_role_ar', 'r.name_en as actor_role_en',
                'uni.official_name_en as university_en', 'uni.official_name_ar as university_ar'
            )
            ->orderByDesc('a.created_at')
            ->forPage(max(1, $page), max(1, $perPage))
            ->get()
            ->map(fn ($row) => (array) $row)->all();

        return ['rows' => $rows, 'total' => $total];
    }
}
