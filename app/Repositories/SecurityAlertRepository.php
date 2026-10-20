<?php

namespace App\Repositories;

use App\Models\SecurityAlert;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/SecurityAlertRepository.php القديمة —
 * بند 25 batch 2 (Alerts). كامل الآن (كانت جزئية في batch 1 — countOpen()
 * بس). نفس الميثودز بالظبط — data access لجدول `security_alerts`
 * (migration 071)، مدموجة مع `users` مرتين (subject/assignee) زي
 * SecurityIncidentRepository::allWithAssignee() بالظبط.
 *
 * فرق شكلي فقط عن القديمة: موديل SecurityAlert هنا معمول عليه array
 * cast لعمود meta (بخلاف القديمة اللي كانت بتعمل json_encode يدوي جوّه
 * create()/bumpOccurrence()) — فمفيش داعي نكرر الـ encode هنا، الـ cast
 * بيتكفل بيه وقت الحفظ.
 */
class SecurityAlertRepository
{
    public const TYPES = [
        'failed_login', 'account_lockout', 'privilege_escalation',
        'suspicious_login_location', 'brute_force', 'multiple_session_detection',
        'unauthorized_file_access', 'security_policy_violation',
    ];

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'escalated'];
    public const SEVERITIES = ['info', 'warning', 'high', 'critical'];

    public function create(array $data): SecurityAlert
    {
        return SecurityAlert::create($data);
    }

    public function find($id): ?SecurityAlert
    {
        return SecurityAlert::find($id);
    }

    /**
     * أحدث تنبيه لسه open من نوع معيّن لمستخدم معيّن، خلال آخر
     * $withinMinutes دقيقة — بيسمح للـ callers (زي AccountLockoutService)
     * إنه يزوّد occurrence على تنبيه موجود بدل ما يغرق الليست بصف لكل
     * محاولة فاشلة.
     */
    public function recentOpenForUser(int $userId, string $type, int $withinMinutes = 30): ?array
    {
        $row = DB::table('security_alerts')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'open')
            ->where('created_at', '>=', now()->subMinutes($withinMinutes))
            ->orderByDesc('created_at')
            ->first();

        return $row ? (array) $row : null;
    }

    public function bumpOccurrence(int $id, array $meta): void
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) {
            return;
        }
        $alert->fill(['meta' => $meta]);
        $alert->save();
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} مفلترة + مقسّمة صفحات، الأحدث أولًا */
    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $query = $this->filteredQuery($filters);

        $total = (clone $query)->count();

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $query->orderByDesc('a.created_at')
            ->limit($perPage)
            ->offset($offset)
            ->get()
            ->map(fn ($r) => (array) $r)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** نفس فلاتر search() بس كل صف مطابق، بحد أقصى — للـ export. */
    public function searchAll(array $filters, int $limit = 5000): array
    {
        return $this->search($filters, 1, $limit)['rows'];
    }

    public function countByStatus(string $status): int
    {
        return SecurityAlert::where('status', $status)->count();
    }

    public function countOpen(): int
    {
        return SecurityAlert::whereIn('status', ['open', 'escalated'])->count();
    }

    public function acknowledge($id, int $userId): bool
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) {
            return false;
        }
        $alert->fill([
            'status'          => 'acknowledged',
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
        ]);
        return $alert->save();
    }

    public function resolve($id, int $userId, ?string $note = null): bool
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) {
            return false;
        }
        $alert->fill([
            'status'          => 'resolved',
            'resolved_by'     => $userId,
            'resolved_at'     => now(),
            'resolution_note' => $note,
        ]);
        return $alert->save();
    }

    public function escalate($id, int $userId, ?string $note = null): bool
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) {
            return false;
        }
        $alert->fill([
            'status'          => 'escalated',
            'escalated_by'    => $userId,
            'escalated_at'    => now(),
            'escalation_note' => $note,
        ]);
        return $alert->save();
    }

    public function assign($id, $userId): bool
    {
        $alert = SecurityAlert::find($id);
        if (!$alert) {
            return false;
        }
        $alert->fill(['assigned_to' => $userId]);
        return $alert->save();
    }

    // -- helpers --------------------------------------------------------------

    private function filteredQuery(array $filters)
    {
        $query = DB::table('security_alerts as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as asg', 'asg.id', '=', 'a.assigned_to')
            ->select('a.*', 'u.full_name as subject_name', 'asg.full_name as assignee_name');

        if (!empty($filters['status'])) {
            $query->where('a.status', $filters['status']);
        }
        if (!empty($filters['severity'])) {
            $query->where('a.severity', $filters['severity']);
        }
        if (!empty($filters['type'])) {
            $query->where('a.type', $filters['type']);
        }
        if (!empty($filters['assigned_to'])) {
            $query->where('a.assigned_to', $filters['assigned_to']);
        }
        if (!empty($filters['q'])) {
            $q = '%' . $filters['q'] . '%';
            $query->where(function ($w) use ($q) {
                $w->where('a.title', 'like', $q)
                    ->orWhere('a.message', 'like', $q)
                    ->orWhere('a.source_ip', 'like', $q);
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('a.created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('a.created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query;
    }
}
