<?php

namespace App\Repositories;

use App\Models\SecurityIncident;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/SecurityIncidentRepository.php القديمة —
 * بند 25 batch 2 (Incidents). كامل الآن (كانت جزئية في batch 1 —
 * allWithAssignee()/countBySeverity()/countOpen() بس). نفس الميثودز
 * بالظبط — data access لجدول `security_incidents` (migration 041) +
 * `security_incident_events` (تايم لاين الحادثة)، مدموجة مع `users`
 * لاسم المعيّن/الفاعل.
 */
class SecurityIncidentRepository
{
    public const CATEGORIES = [
        'unauthorized_access', 'malware', 'data_leak', 'phishing',
        'brute_force', 'policy_violation', 'other',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const STATUSES = ['open', 'investigating', 'contained', 'resolved', 'closed'];

    public function create(array $data): SecurityIncident
    {
        $data['reference_code'] = $data['reference_code'] ?? $this->nextReferenceCode();
        return SecurityIncident::create($data);
    }

    public function find($id): ?SecurityIncident
    {
        return SecurityIncident::find($id);
    }

    /** @return array<int,array<string,mixed>> الأحدث أولًا، مدموجة مع اسم المعيّن */
    public function allWithAssignee(int $limit = 200, ?string $status = null, ?string $severity = null): array
    {
        $query = DB::table('security_incidents as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')
            ->select('i.*', 'u.full_name as assignee_name');

        if ($status) {
            $query->where('i.status', $status);
        }
        if ($severity) {
            $query->where('i.severity', $severity);
        }

        return $query->orderByDesc('i.detected_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function countByStatus(string $status): int
    {
        return SecurityIncident::where('status', $status)->count();
    }

    public function countBySeverity(string $severity): int
    {
        return SecurityIncident::where('severity', $severity)->count();
    }

    public function countOpen(): int
    {
        return SecurityIncident::whereIn('status', ['open', 'investigating'])->count();
    }

    public function updateStatus($id, string $status, ?string $resolvedAt = null): bool
    {
        $incident = SecurityIncident::find($id);
        if (!$incident) {
            return false;
        }
        $data = ['status' => $status];
        if ($status === 'resolved' || $status === 'closed') {
            $data['resolved_at'] = $resolvedAt ?? now();
        }
        $incident->fill($data);
        return $incident->save();
    }

    public function assign($id, $userId): bool
    {
        $incident = SecurityIncident::find($id);
        if (!$incident) {
            return false;
        }
        $incident->fill(['assigned_to' => $userId]);
        return $incident->save();
    }

    public function addEvent($incidentId, ?int $userId, string $eventType, ?string $note = null): void
    {
        DB::table('security_incident_events')->insert([
            'incident_id' => $incidentId,
            'user_id'     => $userId,
            'event_type'  => $eventType,
            'note'        => $note,
            'created_at'  => now(),
        ]);
    }

    /** @return array<int,array<string,mixed>> الأقدم أولًا، مدموجة مع اسم الفاعل */
    public function timeline($incidentId): array
    {
        return DB::table('security_incident_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->select('e.*', 'u.full_name as actor_name')
            ->where('e.incident_id', $incidentId)
            ->orderBy('e.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    private function nextReferenceCode(): string
    {
        $year = date('Y');
        $count = (int) DB::table('security_incidents')
            ->where('reference_code', 'like', "INC-{$year}-%")
            ->count();

        return sprintf('INC-%s-%04d', $year, $count + 1);
    }
}
