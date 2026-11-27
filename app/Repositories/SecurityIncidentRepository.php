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
        $data['sla_due_at'] = $data['sla_due_at'] ?? $this->slaDueAt((string) ($data['severity'] ?? 'low'), $data['detected_at'] ?? null);
        $incident = SecurityIncident::create($data);
        // بند 7: تصعيد الحوادث الجديدة (بعد الرد؛ بيتجاهل الخطورة المنخفضة حسب القواعد).
        \App\Services\EscalationService::fire('incident', (int) $incident->id, (string) ($incident->severity ?? $data['severity'] ?? 'low'), (string) ($incident->category ?? $data['category'] ?? 'other'), (string) ($incident->title ?? 'Security incident'), isset($data['description']) ? (string) $data['description'] : null, array_filter(['Reference' => $incident->reference_code ?? null]));

        return $incident;
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
            // already resolved -> closed keeps the original resolution time (the SLA is judged against it)
            $data['resolved_at'] = $incident->resolved_at ?: ($resolvedAt ?? now());
        } else {
            $data['resolved_at'] = null; // reopened
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

    // ---- SLA ---------------------------------------------------------------------------------------------------

    public function slaHours(string $severity): int
    {
        $map = (array) config('security.incident_sla_hours', ['critical' => 4, 'high' => 24, 'medium' => 72, 'low' => 168]);
        return max(1, (int) ($map[$severity] ?? 72));
    }

    /** موعد استحقاق الحل = وقت الاكتشاف + ساعات الخطورة. */
    public function slaDueAt(string $severity, $detectedAt = null): string
    {
        $base = $detectedAt ? (strtotime((string) $detectedAt) ?: time()) : time();
        return date('Y-m-d H:i:s', $base + $this->slaHours($severity) * 3600);
    }

    /**
     * حالة الـ SLA لحادثة (array أو Model):
     *  met = اتحلّت قبل الموعد | missed = اتحلّت بعد الموعد | breached = لسه مفتوحة وعدّى الموعد
     *  at_risk = لسه مفتوحة واستهلكت أغلب المهلة | on_track = في الوقت.
     * @return array{due_at:?string,state:string,total_hours:int,hours_left:?float,percent:?int}
     */
    public function slaInfo($incident): array
    {
        $i = is_array($incident) ? $incident : $incident->toArray();
        $total = $this->slaHours((string) ($i['severity'] ?? 'low'));
        $detected = strtotime((string) ($i['detected_at'] ?? $i['created_at'] ?? '')) ?: time();
        $dueRaw = $i['sla_due_at'] ?? null;
        $due = $dueRaw ? strtotime((string) $dueRaw) : ($detected + $total * 3600);
        $done = in_array($i['status'] ?? '', ['resolved', 'closed'], true);
        $endTs = $done ? (strtotime((string) ($i['resolved_at'] ?? '')) ?: time()) : time();

        if ($done) {
            $state = $endTs <= $due ? 'met' : 'missed';
        } elseif ($endTs > $due) {
            $state = 'breached';
        } else {
            $span = max(1, $due - $detected);
            $state = (($endTs - $detected) / $span) >= (float) config('security.incident_sla_at_risk_ratio', 0.75) ? 'at_risk' : 'on_track';
        }

        $span = max(1, $due - $detected);
        return [
            'due_at'      => date('Y-m-d H:i:s', $due),
            'state'       => $state,
            'total_hours' => $total,
            'hours_left'  => $done ? null : round(($due - time()) / 3600, 1),
            'percent'     => $done ? null : (int) max(0, min(999, round((time() - $detected) / $span * 100))),
        ];
    }

    /** تغيير الخطورة بيعيد حساب الـ SLA من وقت الاكتشاف. */
    public function updateSeverity($id, string $severity): bool
    {
        $incident = SecurityIncident::find($id);
        if (!$incident) {
            return false;
        }
        $incident->fill(['severity' => $severity, 'sla_due_at' => $this->slaDueAt($severity, $incident->detected_at)]);
        return $incident->save();
    }

    // ---- links (alerts / logs / users) ---------------------------------------------------------------------------

    public const LINK_TYPES = ['alert', 'log', 'user'];

    /** @return array<int,array<string,mixed>> الروابط مع تفاصيل كل عنصر (لو لسه موجود). */
    public function links($incidentId): array
    {
        $rows = DB::table('security_incident_links as l')
            ->leftJoin('users as cu', 'cu.id', '=', 'l.created_by')
            ->where('l.incident_id', $incidentId)
            ->select('l.*', 'cu.full_name as created_by_name')
            ->orderBy('l.id')->get()->map(fn ($r) => (array) $r)->all();

        $alertIds = $userIds = $logIds = [];
        foreach ($rows as $r) {
            if (!ctype_digit((string) $r['ref'])) {
                continue;
            }
            if ($r['link_type'] === 'alert') { $alertIds[] = (int) $r['ref']; }
            if ($r['link_type'] === 'user')  { $userIds[]  = (int) $r['ref']; }
            if ($r['link_type'] === 'log')   { $logIds[]   = (int) $r['ref']; }
        }
        $alerts = $alertIds ? DB::table('security_alerts')->whereIn('id', $alertIds)->get(['id', 'title', 'type', 'severity', 'status', 'source_ip', 'created_at'])->keyBy('id') : collect();
        $users  = $userIds ? DB::table('users')->whereIn('id', $userIds)->get(['id', 'full_name', 'email'])->keyBy('id') : collect();
        $logs   = $logIds ? DB::table('security_logs')->whereIn('id', $logIds)->get(['id', 'event_type', 'severity', 'ip_address', 'user_id', 'created_at'])->keyBy('id') : collect();

        foreach ($rows as &$r) {
            $key = ctype_digit((string) $r['ref']) ? (int) $r['ref'] : null;
            $r['item'] = null;
            if ($key !== null) {
                $src = $r['link_type'] === 'alert' ? $alerts : ($r['link_type'] === 'user' ? $users : $logs);
                $r['item'] = isset($src[$key]) ? (array) $src[$key] : null;
            }
        }
        unset($r);
        return $rows;
    }

    public function findLink($incidentId, $linkId): ?object
    {
        return DB::table('security_incident_links')->where('incident_id', $incidentId)->where('id', $linkId)->first();
    }

    /** @return array{id:int,created:bool} */
    public function addLink($incidentId, string $type, string $ref, ?int $userId, ?string $note = null): array
    {
        $existing = DB::table('security_incident_links')->where(['incident_id' => $incidentId, 'link_type' => $type, 'ref' => $ref])->first();
        if ($existing) {
            return ['id' => (int) $existing->id, 'created' => false];
        }
        $id = DB::table('security_incident_links')->insertGetId([
            'incident_id' => $incidentId, 'link_type' => $type, 'ref' => $ref,
            'note' => $note, 'created_by' => $userId, 'created_at' => now(),
        ]);
        return ['id' => (int) $id, 'created' => true];
    }

    public function removeLink($incidentId, $linkId): bool
    {
        return DB::table('security_incident_links')->where('incident_id', $incidentId)->where('id', $linkId)->delete() > 0;
    }

    /** وجود العنصر المراد ربطه (التنبيه/المستخدم لازم يكونوا موجودين؛ السجل ممكن يكون نص حر). */
    public function linkTargetExists(string $type, string $ref): bool
    {
        if ($type === 'log') {
            return $ref !== '';
        }
        if (!ctype_digit($ref)) {
            return false;
        }
        return DB::table($type === 'alert' ? 'security_alerts' : 'users')->where('id', (int) $ref)->exists();
    }

    /**
     * بحث لاختيار عنصر يتربط بالحادثة (بدون اللي مربوط فعلًا).
     * @return array<int,array<string,mixed>>
     */
    public function lookup(string $type, string $q, $incidentId, int $limit = 15): array
    {
        $q = trim($q);
        $linked = DB::table('security_incident_links')->where(['incident_id' => $incidentId, 'link_type' => $type])->pluck('ref')->all();
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        if ($type === 'alert') {
            $query = DB::table('security_alerts')->select('id', 'title', 'type', 'severity', 'status', 'source_ip', 'created_at');
            if ($q !== '') {
                $query->where(function ($w) use ($like, $q) {
                    $w->where('title', 'like', $like)->orWhere('source_ip', 'like', $like)->orWhere('type', 'like', $like);
                    if (ctype_digit($q)) { $w->orWhere('id', (int) $q); }
                });
            }
        } elseif ($type === 'user') {
            $query = DB::table('users')->select('id', 'full_name', 'email');
            if ($q !== '') {
                $query->where(function ($w) use ($like, $q) {
                    $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like);
                    if (ctype_digit($q)) { $w->orWhere('id', (int) $q); }
                });
            }
        } else {
            $query = DB::table('security_logs')->select('id', 'event_type', 'severity', 'ip_address', 'user_id', 'created_at');
            if ($q !== '') {
                $query->where(function ($w) use ($like, $q) {
                    $w->where('event_type', 'like', $like)->orWhere('ip_address', 'like', $like);
                    if (ctype_digit($q)) { $w->orWhere('id', (int) $q); }
                });
            }
        }
        if ($linked) {
            $query->whereNotIn('id', array_filter($linked, 'ctype_digit'));
        }
        return $query->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    /** عدد الروابط لكل حادثة (للقائمة). @return array<int,int> */
    public function linkCounts(array $incidentIds): array
    {
        if (!$incidentIds) {
            return [];
        }
        return DB::table('security_incident_links')->whereIn('incident_id', $incidentIds)
            ->selectRaw('incident_id, COUNT(*) as c')->groupBy('incident_id')->pluck('c', 'incident_id')
            ->map(fn ($v) => (int) $v)->all();
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
