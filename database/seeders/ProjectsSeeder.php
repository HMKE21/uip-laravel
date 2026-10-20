<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 04_projects_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class ProjectsSeeder extends Seeder
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
-- projects_seeder.sql
-- A handful of demo projects across categories/statuses so every dashboard
-- (student, university, admin) has real-looking data to render.
-- Run organizations_seeder.sql FIRST — the published project below is
-- linked to the demo university (university_id) by lookup, so that row
-- needs to already exist, otherwise it's inserted with university_id = NULL
-- and won't show up on the University Portfolio/public showcase page.

INSERT INTO projects (uuid, owner_id, university_id, title_ar, title_en, summary, category, status, visibility, published_at)
SELECT UUID(), u.id, uni.id,
       'منصة ذكاء اصطناعي لتحليل المشاريع الجامعية',
       'AI Platform for Analyzing Graduation Projects',
       'A demo graduation project showcasing an AI pipeline that scores project readiness and suggests improvements.',
       'Artificial Intelligence', 'published', 'public', NOW()
FROM users u
LEFT JOIN universities uni ON uni.user_id = (SELECT id FROM users WHERE email = 'university@uip.demo')
WHERE u.email = 'student@uip.demo'
  AND NOT EXISTS (SELECT 1 FROM projects WHERE title_en = 'AI Platform for Analyzing Graduation Projects');
SQL,

            <<<'SQL'
INSERT INTO projects (uuid, owner_id, university_id, title_ar, title_en, summary, category, status, visibility)
SELECT UUID(), u.id, NULL,
       'تطبيق إنترنت الأشياء لمراقبة الزراعة الذكية',
       'IoT Smart Agriculture Monitoring App',
       'A demo project using IoT sensors to monitor soil moisture and crop health in real time.',
       'IoT', 'under_review', 'university_only'
FROM users u WHERE u.email = 'student@uip.demo'
  AND NOT EXISTS (SELECT 1 FROM projects WHERE title_en = 'IoT Smart Agriculture Monitoring App');
SQL,

        ];
    }
}
