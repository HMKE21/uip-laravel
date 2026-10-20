<?php

namespace App\Repositories;

use App\Models\AIInsightReport;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AIInsightRepository.php القديمة
 * (Database::connection()->fetchOne/fetchAll الخام -> DB facade) — بند
 * 24 batch 5 (AI Insights، enhancement spec section 4). الوصول لبيانات
 * `ai_insight_reports` (migration 081)، بالإضافة لاسم المستخدم اللي
 * ولّد كل تقرير. كل تشغيلة (ناجحة أو فاشلة) بتتسجل هنا عشان البورتال
 * دايمًا يعرض آخر حالة حقيقية، أبدًا مش مختلقة، لو مزوّد الـ AI مش متاح.
 */
class AIInsightRepository
{
    public function record(array $attributes): AIInsightReport
    {
        return AIInsightReport::create($attributes);
    }

    /** آخر تقرير مكتمل، أو null لو لسه معملش أي تقرير. */
    public function latestCompleted(): ?array
    {
        $row = DB::table('ai_insight_reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.generated_by')
            ->select('r.*', 'u.full_name as generated_by_name')
            ->where('r.status', 'completed')
            ->orderByDesc('r.created_at')
            ->first();

        return $row ? (array) $row : null;
    }

    /** آخر تقرير بأي حالة (بيسمح بإظهار بانر فشل حتى لو فيه تقرير مكتمل أقدم). */
    public function latestAny(): ?array
    {
        $row = DB::table('ai_insight_reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.generated_by')
            ->select('r.*', 'u.full_name as generated_by_name')
            ->orderByDesc('r.created_at')
            ->first();

        return $row ? (array) $row : null;
    }

    /** تاريخ التشغيلات، الأحدث أولًا. @return array<int,array<string,mixed>> */
    public function history(int $limit = 10): array
    {
        return DB::table('ai_insight_reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.generated_by')
            ->select('r.id', 'r.status', 'r.model_used', 'r.error_message', 'r.created_at', 'u.full_name as generated_by_name')
            ->orderByDesc('r.created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** تقرير واحد بالـ id (مع result_json واسم اللي ولّده)، أو null. */
    public function find(int $id): ?array
    {
        $row = DB::table('ai_insight_reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.generated_by')
            ->select('r.*', 'u.full_name as generated_by_name')
            ->where('r.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * مسح صفوف محددة من سجل التوليد. @param int[] $ids
     * @return int عدد الصفوف اللي اتمسحت فعلًا
     */
    public function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if (!$ids) {
            return 0;
        }

        return DB::table('ai_insight_reports')->whereIn('id', $ids)->delete();
    }

    /** مسح سجل التوليد بالكامل. @return int عدد الصفوف اللي اتمسحت */
    public function deleteAll(): int
    {
        return DB::table('ai_insight_reports')->delete();
    }
}
