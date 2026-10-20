<?php

namespace App\Repositories;

use App\Models\IncidentEvidence;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/IncidentEvidenceRepository.php القديمة —
 * بند 25 batch 2 (Incidents). نفس الميثودز بالظبط — data access لجدول
 * `incident_evidence` (migration 071)، مدموجة مع `users` لاسم الرافع
 * (uploaded_by ممكن يبقى NULL، فـ LEFT JOIN زي القديمة بالظبط).
 */
class IncidentEvidenceRepository
{
    public function create(array $data): IncidentEvidence
    {
        return IncidentEvidence::create($data);
    }

    public function find($id): ?IncidentEvidence
    {
        return IncidentEvidence::find($id);
    }

    /** @return array<int,array<string,mixed>> الأقدم أولًا، مدموجة مع اسم الرافع */
    public function forIncident($incidentId): array
    {
        return DB::table('incident_evidence as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.uploaded_by')
            ->select('e.*', 'u.full_name as uploader_name')
            ->where('e.incident_id', $incidentId)
            ->orderBy('e.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function countForIncident($incidentId): int
    {
        return IncidentEvidence::where('incident_id', $incidentId)->count();
    }

    public function delete($id): bool
    {
        $evidence = IncidentEvidence::find($id);
        if (!$evidence) {
            return false;
        }
        return (bool) $evidence->delete();
    }
}
