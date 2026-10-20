<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 02_roles_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class RolesSeeder extends Seeder
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
-- roles_seeder.sql
-- Full permission catalogue + role-permission mapping.
-- Modules mirror the controllers/services created in Phase 1.

INSERT INTO permissions (slug, module, description) VALUES
('project.create',        'projects',   'Create a new project'),
('project.edit_own',      'projects',   'Edit own project'),
('project.publish',       'projects',   'Publish an approved project'),
('project.approve',       'projects',   'Approve/reject a project (university/admin)'),
('project.view_all',      'projects',   'View all projects across the platform'),
('university.verify',     'university', 'Verify a university account'),
('user.manage',           'users',      'Create/edit/suspend user accounts'),
('role.manage',           'roles',      'Assign/revoke roles and permissions'),
('logs.view_security',    'security',   'View security logs'),
('logs.view_audit',       'security',   'View audit logs'),
('analytics.view',        'analytics',  'View analytics dashboard'),
('reports.generate',      'reports',    'Generate platform reports'),
('settings.manage',       'settings',   'Manage global platform settings'),
('ai.request_analysis',   'ai',         'Request an AI analysis on a project'),
('messaging.use',         'messaging',  'Send and receive messages'),
('notifications.manage',  'notifications', 'Manage own notifications')
ON DUPLICATE KEY UPDATE module = VALUES(module);
SQL,

            <<<'SQL'
-- Admin gets everything
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';
SQL,

            <<<'SQL'
-- Student
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN ('project.create','project.edit_own','ai.request_analysis','messaging.use','notifications.manage')
WHERE r.slug = 'student';
SQL,

            <<<'SQL'
-- University
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN ('project.approve','project.view_all','analytics.view','reports.generate','messaging.use','notifications.manage')
WHERE r.slug = 'university';
SQL,

            <<<'SQL'
-- ############################################################################
-- # 00_academic_ranks_seeder.sql  (new file, reference data only — rewritten with NOT EXISTS guards, see note in file)
-- ############################################################################

-- academic_ranks_seeder.sql
-- Default platform-wide academic/administrative rank taxonomy
-- (university_id = NULL) for migration 100's `academic_ranks` table. This
-- is reference/lookup data (title names), not fake business data — every
-- university can still add its own custom ranks on top, per the "flexible,
-- non-hardcoded" requirement.
--
-- NOTE: rewritten from a single plain multi-row INSERT into guarded
-- INSERT ... SELECT ... WHERE NOT EXISTS statements (one per rank). The
-- original had zero re-run protection -- every other seeder in this
-- project uses ON DUPLICATE KEY UPDATE / INSERT IGNORE / NOT EXISTS, and
-- without a matching guard here, re-running the seeders (as the combined
-- file is designed to support) would insert a duplicate copy of all 13
-- platform-wide ranks on every run. Guard is keyed on (name_en,
-- university_id IS NULL) since a university-specific custom rank added
-- later is allowed to reuse the same name_en.

INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'أستاذ', 'Professor', 'academic', 10, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Professor' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'أستاذ مشارك', 'Associate Professor', 'academic', 20, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Associate Professor' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'أستاذ مساعد', 'Assistant Professor', 'academic', 30, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Assistant Professor' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'مدرس', 'Lecturer', 'academic', 40, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Lecturer' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'مدرس مساعد', 'Assistant Lecturer', 'academic', 50, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Assistant Lecturer' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'معيد', 'Demonstrator', 'academic', 60, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Demonstrator' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'معيد مساعد تدريس', 'Teaching Assistant', 'academic', 70, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Teaching Assistant' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'رئيس الجامعة', 'University President', 'administrative', 10, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'University President' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'نائب رئيس الجامعة', 'Vice President', 'administrative', 20, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Vice President' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'عميد الكلية', 'Dean', 'administrative', 30, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Dean' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'وكيل الكلية', 'Vice Dean', 'administrative', 40, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Vice Dean' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'رئيس القسم', 'Head of Department', 'administrative', 50, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Head of Department' AND university_id IS NULL);
SQL,

            <<<'SQL'
INSERT INTO academic_ranks (university_id, name_ar, name_en, category, sort_order, is_active)
SELECT NULL, 'موظف إداري', 'Administrative Staff', 'administrative', 60, 1
WHERE NOT EXISTS (SELECT 1 FROM academic_ranks WHERE name_en = 'Administrative Staff' AND university_id IS NULL);
SQL,

        ];
    }
}
