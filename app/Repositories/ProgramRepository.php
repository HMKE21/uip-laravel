<?php

namespace App\Repositories;

use App\Models\Program;

/** منقولة من app/Repositories/ProgramRepository.php القديمة. */
class ProgramRepository
{
    public function find($id): ?Program
    {
        return Program::find($id);
    }

    /** Lookup محكوم بالملكية، مقيّد بقسم واحد. */
    public function findOwnedByDepartment($id, $departmentId): ?Program
    {
        $program = Program::find($id);
        if (!$program || (int) $program->department_id !== (int) $departmentId) {
            return null;
        }
        return $program;
    }

    /** @return Program[] كل برامج قسم واحد */
    public function forDepartment($departmentId, ?string $status = null): array
    {
        $query = Program::where('department_id', $departmentId);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderBy('name_en')->get()->all();
    }

    public function create(array $data): Program
    {
        return Program::create($data);
    }
}
