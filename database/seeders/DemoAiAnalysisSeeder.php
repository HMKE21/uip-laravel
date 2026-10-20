<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 05_demo_ai_analysis_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class DemoAiAnalysisSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        return [
            <<<'SQL'
-- demo_ai_analysis_seeder.sql
-- Demo AI results so the AI Analysis, Readiness Score, Classification, Startup
-- Potential, and Improvement Suggestions UIs have believable placeholder content.
-- Safe to re-run: ai_readiness_scores/startup_potential are keyed on the UNIQUE
-- project_id column via ON DUPLICATE KEY UPDATE, ai_classifications and
-- improvement_suggestions have no unique key, so they're guarded with NOT EXISTS
-- instead, otherwise re-running would silently pile up duplicate rows.

INSERT INTO ai_readiness_scores (project_id, overall_score, technical_score, market_score, innovation_score, presentation_score, computed_at, is_demo_data)
SELECT p.id, 78.50, 82.00, 70.00, 85.00, 75.00, NOW(), 1
FROM projects p WHERE p.title_en = 'AI Platform for Analyzing Graduation Projects'
ON DUPLICATE KEY UPDATE overall_score = VALUES(overall_score), computed_at = VALUES(computed_at);
SQL,

            <<<'SQL'
INSERT INTO ai_classifications (project_id, predicted_category, confidence, alternative_categories, is_demo_data)
SELECT p.id, 'Artificial Intelligence', 91.20, JSON_ARRAY('Data Science','EdTech'), 1
FROM projects p WHERE p.title_en = 'AI Platform for Analyzing Graduation Projects'
  AND NOT EXISTS (
    SELECT 1 FROM ai_classifications c
    JOIN projects p2 ON p2.id = c.project_id
    WHERE p2.title_en = 'AI Platform for Analyzing Graduation Projects'
  );
SQL,

            <<<'SQL'
INSERT INTO startup_potential (project_id, potential_score, market_size_estimate, competitive_edge, risk_factors, is_demo_data)
SELECT p.id, 68.00, 'Medium (regional EdTech market)',
       'First-mover advantage for Arabic-language academic AI tooling.',
       JSON_ARRAY('Requires partnerships with universities','Data privacy compliance'), 1
FROM projects p WHERE p.title_en = 'AI Platform for Analyzing Graduation Projects'
ON DUPLICATE KEY UPDATE potential_score = VALUES(potential_score);
SQL,

            <<<'SQL'
INSERT INTO improvement_suggestions (project_id, suggestion, category, priority, is_demo_data)
SELECT p.id, 'Add automated test coverage for the scoring engine.', 'technical', 'high', 1
FROM projects p WHERE p.title_en = 'AI Platform for Analyzing Graduation Projects'
  AND NOT EXISTS (
    SELECT 1 FROM improvement_suggestions s
    JOIN projects p2 ON p2.id = s.project_id
    WHERE p2.title_en = 'AI Platform for Analyzing Graduation Projects'
      AND s.suggestion = 'Add automated test coverage for the scoring engine.'
  );
SQL,

            <<<'SQL'
INSERT INTO improvement_suggestions (project_id, suggestion, category, priority, is_demo_data)
SELECT p.id, 'Strengthen the market-need section in the project summary.', 'market_fit', 'medium', 1
FROM projects p WHERE p.title_en = 'AI Platform for Analyzing Graduation Projects'
  AND NOT EXISTS (
    SELECT 1 FROM improvement_suggestions s
    JOIN projects p2 ON p2.id = s.project_id
    WHERE p2.title_en = 'AI Platform for Analyzing Graduation Projects'
      AND s.suggestion = 'Strengthen the market-need section in the project summary.'
  );
SQL,

        ];
    }
}
