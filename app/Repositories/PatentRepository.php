<?php

namespace App\Repositories;

use App\Models\Patent;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/PatentRepository.php القديمة — بند 14/Future
 * (Patent Portal، طالب). forSubmitterWithProject()/findOwned()/
 * create() لجانب الـ CRUD الفعلي.
 */
class PatentRepository
{
    public function findOwned($id, $userId): ?Patent
    {
        $patent = Patent::find($id);
        return ($patent && (int) $patent->submitted_by === (int) $userId) ? $patent : null;
    }

    public function create(array $data): Patent
    {
        return Patent::create($data);
    }

    /**
     * عنوان براءة الاختراع مدموج مع عنوان المشروع المرتبط بيها لو فيه —
     * الشكل اللي صفحة Patent Portal محتاجاه من غير round-trip تاني لكل صف.
     * @return array<int,array<string,mixed>>
     */
    public function forSubmitterWithProject($userId): array
    {
        return DB::table('patents as pt')
            ->leftJoin('projects as p', 'p.id', '=', 'pt.project_id')
            ->where('pt.submitted_by', $userId)
            ->select('pt.*', 'p.title_en as project_title_en', 'p.title_ar as project_title_ar')
            ->orderByDesc('pt.created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
