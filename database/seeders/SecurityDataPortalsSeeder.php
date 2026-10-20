<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 08_security_data_portals_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class SecurityDataPortalsSeeder extends Seeder
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
-- security_data_portals_seeder.sql
-- ============================================================================
-- Adds the 3 new roles (Security Administrator, Security Officer,
-- Data Analyst), their permissions, role-permission mapping, the demo
-- accounts requested, and grants Admin full access to the new modules.
-- Safe to re-run (ON DUPLICATE KEY UPDATE / INSERT IGNORE throughout).
-- ============================================================================

-- 1. New roles ---------------------------------------------------------------
INSERT INTO roles (slug, name_ar, name_en, description) VALUES
('security_admin',   'مسؤول أمن المعلومات', 'Security Administrator',
    'Full control over the Cybersecurity Portal: incidents, sessions, vulnerabilities, policies, and security user management'),
('security_officer',  'ضابط أمن المعلومات',  'Security Officer',
    'Operational Cybersecurity Portal access: monitoring, incident response, and reporting (no user/role management)'),
('data_analyst',      'محلل بيانات',         'Data Analyst',
    'Full access to the Data Analysis Portal: dashboards, reports, exports, and platform-wide analytics')
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), name_en = VALUES(name_en);
SQL,

            <<<'SQL'
-- 2. New permissions ----------------------------------------------------------
INSERT INTO permissions (slug, module, description) VALUES
-- Security module (logs.view_security / logs.view_audit / user.manage / role.manage already exist and are reused)
('security.dashboard.view',      'security', 'View the Security Dashboard'),
('security.incidents.manage',    'security', 'Create, update, assign and resolve security incidents'),
('security.sessions.manage',     'security', 'View and revoke active user sessions'),
('security.vulnerabilities.manage','security', 'Track and manage vulnerabilities'),
('security.policies.manage',     'security', 'Configure security policies (password rules, lockouts, session timeout)'),
('security.risk.view',           'security', 'View risk monitoring & risk scores'),
('security.ip.manage',           'security', 'Block/unblock IP addresses'),
('security.notifications.manage','security', 'View and manage security notifications'),
('security.reports.generate',    'security', 'Generate security reports'),
('security.users.manage',        'security', 'Manage Security Officer / Security Administrator accounts'),

-- Data Analysis module (analytics.view / reports.generate already exist and are reused)
('data_analysis.dashboard.view',   'data_analysis', 'View the Data Analysis Dashboard'),
('data_analysis.export',           'data_analysis', 'Export data as CSV/Excel/PDF'),
('data_analysis.reports.manage',   'data_analysis', 'Create and manage custom report templates'),
('data_analysis.segments.manage',  'data_analysis', 'Create and manage saved data segments'),
('data_analysis.dashboards.customize', 'data_analysis', 'Customize/save personal dashboard layouts'),
('data_analysis.trends.view',      'data_analysis', 'View trend analysis and predictive insights')
ON DUPLICATE KEY UPDATE module = VALUES(module);
SQL,

            <<<'SQL'
-- 3. Admin gets everything automatically (existing rule: admin role already
--    receives every permission via the CROSS JOIN in roles_seeder.sql, so no
--    action needed here as long as roles_seeder runs AFTER this file, or is
--    re-run afterwards). We re-assert it defensively:
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';
SQL,

            <<<'SQL'
-- 4. Security Administrator — full security module + user management
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN (
    'security.dashboard.view','security.incidents.manage','security.sessions.manage',
    'security.vulnerabilities.manage','security.policies.manage','security.risk.view',
    'security.ip.manage','security.notifications.manage','security.reports.generate',
    'security.users.manage','logs.view_security','logs.view_audit',
    'messaging.use','notifications.manage'
  )
WHERE r.slug = 'security_admin';
SQL,

            <<<'SQL'
-- 5. Security Officer — operational access, no policy/user management
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN (
    'security.dashboard.view','security.incidents.manage','security.sessions.manage',
    'security.vulnerabilities.manage','security.risk.view','security.notifications.manage',
    'security.reports.generate','logs.view_security','logs.view_audit',
    'messaging.use','notifications.manage'
  )
WHERE r.slug = 'security_officer';
SQL,

            <<<'SQL'
-- 6. Data Analyst — full analytics module
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.slug IN (
    'data_analysis.dashboard.view','data_analysis.export','data_analysis.reports.manage',
    'data_analysis.segments.manage','data_analysis.dashboards.customize',
    'data_analysis.trends.view','analytics.view','reports.generate',
    'messaging.use','notifications.manage'
  )
WHERE r.slug = 'data_analyst';
SQL,

            <<<'SQL'
-- 7. Demo accounts -------------------------------------------------------------
-- Password: Passw0rd@2026 — bcrypt hash below was generated and verified
-- (cost factor 10, same as the rest of the seeded demo accounts) and is
-- verifiable with PHP's password_verify('Passw0rd@2026', $hash).
INSERT INTO users (uuid, full_name, email, password_hash, status, email_verified_at) VALUES
(UUID(), 'Security Administrator (Demo)', 'security@uip.demo',    '$2b$10$/je0yLNggES2qBBU5PPRzeqM2PZy8//vup3pcPbRz3.M8fXxeqzue', 'active', NOW()),
(UUID(), 'Data Analyst (Demo)',           'data.analyst@uip.demo','$2b$10$/je0yLNggES2qBBU5PPRzeqM2PZy8//vup3pcPbRz3.M8fXxeqzue', 'active', NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);
SQL,

            <<<'SQL'
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r
  ON (u.email = 'security@uip.demo'     AND r.slug = 'security_admin')
  OR (u.email = 'data.analyst@uip.demo' AND r.slug = 'data_analyst')
ON DUPLICATE KEY UPDATE assigned_at = assigned_at;
SQL,

            <<<'SQL'
-- 8. Default security policies (editable later from the portal) --------------
INSERT INTO security_policies (policy_key, category, name_ar, name_en, value) VALUES
('password.min_length',        'authentication', 'الحد الأدنى لطول كلمة المرور', 'Minimum password length', '8'),
('password.require_special',   'authentication', 'إلزام رمز خاص في كلمة المرور', 'Require special character', '1'),
('session.timeout_minutes',    'session',        'مهلة انتهاء الجلسة (دقائق)',   'Session timeout (minutes)', '60'),
('login.max_failed_attempts',  'access_control', 'الحد الأقصى لمحاولات الدخول الفاشلة', 'Max failed login attempts', '5'),
('login.lockout_minutes',      'access_control', 'مدة الحظر بعد الفشل (دقائق)', 'Lockout duration (minutes)', '15'),
('monitoring.alert_on_new_ip', 'monitoring',     'تنبيه عند دخول من IP جديد',   'Alert on login from new IP', '1')
ON DUPLICATE KEY UPDATE value = value;
SQL,

        ];
    }
}
