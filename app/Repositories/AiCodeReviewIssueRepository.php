<?php

namespace App\Repositories;

use App\Models\AiCodeReviewIssue;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AiCodeReviewIssueRepository.php القديمة —
 * وصول للبيانات لجدول `ai_code_review_issues`. GithubCodeReviewService
 * هي الكاتب الوحيد؛ لوحة/تصدير Admin AI Code Review هما القارئين.
 */
class AiCodeReviewIssueRepository
{
    private const CATEGORIES = ['security', 'performance', 'maintainability', 'architecture', 'quality', 'general'];
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public function create(array $data): AiCodeReviewIssue
    {
        return AiCodeReviewIssue::create($data);
    }

    /**
     * إدراج جماعي لكل issue أنتجها التحليل لمراجعة واحدة. كل صف بيتعقّم
     * دفاعيًا (category/severity غير معروفة أو ناقصة بترجع لقيمة افتراضية
     * آمنة) لأن المصدر JSON مولّد بالـ AI مش مضمون يطابق الـ ENUM بالظبط —
     * issue واحد فاسد لازم أبدًا ميوقفش الدفعة كلها أو المراجعة اللي تبعها.
     * @param array<int,array<string,mixed>> $issues
     */
    public function createMany(int $reviewId, array $issues): int
    {
        $inserted = 0;
        foreach ($issues as $issue) {
            $category = strtolower((string) ($issue['category'] ?? 'general'));
            $severity = strtolower((string) ($issue['severity'] ?? 'medium'));

            try {
                $this->create([
                    'review_id'         => $reviewId,
                    'category'          => in_array($category, self::CATEGORIES, true) ? $category : 'general',
                    'severity'          => in_array($severity, self::SEVERITIES, true) ? $severity : 'medium',
                    'file_name'         => $issue['file'] ?? $issue['file_name'] ?? null,
                    'line_number'       => isset($issue['line']) && is_numeric($issue['line']) ? (int) $issue['line'] : (isset($issue['line_number']) && is_numeric($issue['line_number']) ? (int) $issue['line_number'] : null),
                    'description'       => (string) ($issue['description'] ?? $issue['message'] ?? 'No description provided.'),
                    'ai_recommendation' => $issue['recommendation'] ?? $issue['ai_recommendation'] ?? null,
                    'suggested_fix'     => $issue['suggested_fix'] ?? null,
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                // يتخطى issue واحد فاسد بدل ما يوقف الدفعة كلها.
                continue;
            }
        }
        return $inserted;
    }

    /** @return array<int,AiCodeReviewIssue> كل issues لمراجعة واحدة، الأشد خطورة أولاً */
    public function forReview(int $reviewId): array
    {
        return AiCodeReviewIssue::where('review_id', $reviewId)
            ->orderByRaw("FIELD(severity, 'critical','high','medium','low','info')")
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<string,int> عدد issues حسب severity لمراجعة واحدة */
    public function severityCountsForReview(int $reviewId): array
    {
        $rows = DB::select(
            'SELECT severity, COUNT(*) AS c FROM ai_code_review_issues WHERE review_id = ? GROUP BY severity',
            [$reviewId]
        );
        $counts = array_fill_keys(self::SEVERITIES, 0);
        foreach ($rows as $row) {
            $counts[$row->severity] = (int) $row->c;
        }
        return $counts;
    }

    /**
     * كل issues عبر مجموعة مراجعات دفعة واحدة، الأشد خطورة أولاً — تخدم
     * قسم "Findings" في تقارير تصدير الجامعة/الكلية
     * (AdminAiCodeReviewExportController)، اللي محتاج issues من مراجعات
     * كتير مرة واحدة مش وحدة وحدة.
     * @param array<int,int> $reviewIds
     * @return array<int,AiCodeReviewIssue>
     */
    public function forReviews(array $reviewIds): array
    {
        if (!$reviewIds) {
            return [];
        }
        return AiCodeReviewIssue::whereIn('review_id', array_values($reviewIds))
            ->orderByRaw("FIELD(severity, 'critical','high','medium','low','info')")
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * تفصيل severity عبر مجموعة مراجعات — النسخة المجمّعة من
     * severityCountsForReview() اللي بتستخدمها تقارير التجميع.
     * @param array<int,int> $reviewIds
     * @return array<string,int>
     */
    public function severityCountsForReviews(array $reviewIds): array
    {
        $counts = array_fill_keys(self::SEVERITIES, 0);
        if (!$reviewIds) {
            return $counts;
        }
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $rows = DB::select(
            "SELECT severity, COUNT(*) AS c FROM ai_code_review_issues WHERE review_id IN ({$placeholders}) GROUP BY severity",
            array_values($reviewIds)
        );
        foreach ($rows as $row) {
            $counts[$row->severity] = (int) $row->c;
        }
        return $counts;
    }
}
