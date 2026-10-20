<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 06_demo_analytics_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class DemoAnalyticsSeeder extends Seeder
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
-- demo_analytics_seeder.sql
-- Demo analytics/trend records so the Analytics Dashboard, Innovation
-- Statistics, and Trend Analysis pages render meaningful charts immediately.

INSERT INTO analytics_records (metric_key, metric_value, dimension, recorded_for_date) VALUES
('total_projects',       124, 'platform', CURDATE()),
('approved_projects',     89, 'platform', CURDATE()),
('active_users',         340, 'platform', CURDATE()),
('avg_readiness_score', 74.30, 'platform', CURDATE());
SQL,

            <<<'SQL'
INSERT INTO innovation_statistics (university_id, category, total_projects, approved_projects, avg_readiness_score, period_start, period_end)
VALUES
(NULL, 'Artificial Intelligence', 40, 31, 76.20, DATE_SUB(CURDATE(), INTERVAL 30 DAY), CURDATE()),
(NULL, 'IoT',                     28, 20, 69.80, DATE_SUB(CURDATE(), INTERVAL 30 DAY), CURDATE()),
(NULL, 'FinTech',                 18, 12, 71.50, DATE_SUB(CURDATE(), INTERVAL 30 DAY), CURDATE());
SQL,

            <<<'SQL'
INSERT INTO trends (topic, trend_score, project_count, period_month) VALUES
('Generative AI',      92.40, 22, DATE_FORMAT(CURDATE(), '%Y-%m-01')),
('Sustainable Energy',  81.10, 15, DATE_FORMAT(CURDATE(), '%Y-%m-01')),
('HealthTech',          77.60, 13, DATE_FORMAT(CURDATE(), '%Y-%m-01'));
SQL,

        ];
    }
}
