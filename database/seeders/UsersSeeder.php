<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 01_users_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class UsersSeeder extends Seeder
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
-- users_seeder.sql
-- Seeds base roles + one demo user per portal role.
-- Passwords are placeholders (bcrypt hash of "Passw0rd@2026") — replace before any real deployment.

INSERT INTO roles (slug, name_ar, name_en, description) VALUES
('student',    'طالب',        'Student',    'University student publishing graduation/innovation projects'),
('university', 'جامعة',       'University', 'University staff account managing students & project approvals'),
('admin',      'مسؤول النظام','Admin',      'Platform administrator with full access')
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar);
SQL,

            <<<'SQL'
INSERT INTO users (uuid, full_name, name_ar, name_en, email, password_hash, status, email_verified_at) VALUES
(UUID(), 'Demo Student',    'طالب تجريبي',   'Demo Student',    'student@uip.demo',    '$2b$10$/je0yLNggES2qBBU5PPRzeqM2PZy8//vup3pcPbRz3.M8fXxeqzue', 'active', NOW()),
(UUID(), 'Demo University', 'جامعة تجريبية', 'Demo University', 'university@uip.demo', '$2b$10$/je0yLNggES2qBBU5PPRzeqM2PZy8//vup3pcPbRz3.M8fXxeqzue', 'active', NOW()),
(UUID(), 'Demo Admin',      'مسؤول تجريبي',  'Demo Admin',      'admin@uip.demo',      '$2b$10$/je0yLNggES2qBBU5PPRzeqM2PZy8//vup3pcPbRz3.M8fXxeqzue', 'active', NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), name_ar = VALUES(name_ar), name_en = VALUES(name_en);
SQL,

            <<<'SQL'
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r
  ON (u.email = 'student@uip.demo'    AND r.slug = 'student')
  OR (u.email = 'university@uip.demo' AND r.slug = 'university')
  OR (u.email = 'admin@uip.demo'      AND r.slug = 'admin');
SQL,

        ];
    }
}
