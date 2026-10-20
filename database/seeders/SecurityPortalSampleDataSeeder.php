<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 09_security_portal_sample_data_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class SecurityPortalSampleDataSeeder extends Seeder
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
-- security_portal_sample_data_seeder.sql
-- ============================================================================
-- Realistic demo data for the Cybersecurity Portal (security_incidents,
-- security_incident_events, vulnerabilities, security_notifications) so the
-- Security Dashboard, Incidents, Vulnerabilities, and Notifications feed
-- render meaningful content on a fresh install, per the "sample security
-- logs" seeding requirement. Safe to re-run: every insert is guarded by a
-- NOT EXISTS check on reference_code / title so re-running this file never
-- creates duplicates.
-- Run AFTER security_data_portals_seeder.sql (needs the security_admin /
-- security_officer demo accounts and roles to exist).
-- ============================================================================

-- 1. Security incidents ------------------------------------------------------
INSERT INTO security_incidents (reference_code, title, description, category, severity, status, source_ip, assigned_to, created_by, detected_at)
SELECT * FROM (SELECT
    'INC-2026-0001' AS reference_code,
    'Repeated failed login attempts from a single IP' AS title,
    'Automated monitoring flagged 12 failed login attempts against 3 different accounts within 5 minutes from the same source IP.' AS description,
    'brute_force' AS category, 'high' AS severity, 'investigating' AS status,
    '203.0.113.45' AS source_ip,
    (SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'security_officer' LIMIT 1) AS assigned_to,
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo') AS created_by,
    DATE_SUB(NOW(), INTERVAL 2 DAY) AS detected_at
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM security_incidents WHERE reference_code = 'INC-2026-0001');
SQL,

            <<<'SQL'
INSERT INTO security_incidents (reference_code, title, description, category, severity, status, source_ip, assigned_to, created_by, detected_at, resolved_at)
SELECT * FROM (SELECT
    'INC-2026-0002', 'Phishing email reported by a student account',
    'A student reported a suspicious email impersonating the university portal asking for credentials. Link was sandboxed and confirmed malicious.',
    'phishing', 'medium', 'resolved', NULL,
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo') AS assigned_to,
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo') AS created_by,
    DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM security_incidents WHERE reference_code = 'INC-2026-0002');
SQL,

            <<<'SQL'
INSERT INTO security_incidents (reference_code, title, description, category, severity, status, source_ip, created_by, detected_at)
SELECT * FROM (SELECT
    'INC-2026-0003', 'Unusual project-file access pattern',
    'A user account accessed and downloaded an unusually high number of project files in a short window, outside its normal usage pattern.',
    'unauthorized_access', 'critical', 'open', '198.51.100.22',
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'),
    DATE_SUB(NOW(), INTERVAL 6 HOUR)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM security_incidents WHERE reference_code = 'INC-2026-0003');
SQL,

            <<<'SQL'
INSERT INTO security_incidents (reference_code, title, description, category, severity, status, assigned_to, created_by, detected_at, resolved_at)
SELECT * FROM (SELECT
    'INC-2026-0004', 'Policy violation: shared account credentials',
    'Two logins for the same university account observed from geographically distant IPs within a short time window, suggesting shared credentials.',
    'policy_violation', 'low', 'contained',
    (SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'security_officer' LIMIT 1),
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'),
    DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 19 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM security_incidents WHERE reference_code = 'INC-2026-0004');
SQL,

            <<<'SQL'
-- 2. Incident timeline events -------------------------------------------------
INSERT INTO security_incident_events (incident_id, user_id, event_type, note, created_at)
SELECT i.id, i.created_by, 'created', 'Incident reported.', i.detected_at
FROM security_incidents i
WHERE i.reference_code IN ('INC-2026-0001','INC-2026-0002','INC-2026-0003','INC-2026-0004')
  AND NOT EXISTS (SELECT 1 FROM security_incident_events e WHERE e.incident_id = i.id AND e.event_type = 'created');
SQL,

            <<<'SQL'
INSERT INTO security_incident_events (incident_id, user_id, event_type, note, created_at)
SELECT i.id, i.assigned_to, 'status_change', CONCAT('Status changed to ', i.status, '.'), DATE_ADD(i.detected_at, INTERVAL 1 HOUR)
FROM security_incidents i
WHERE i.reference_code IN ('INC-2026-0001','INC-2026-0004') AND i.assigned_to IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM security_incident_events e WHERE e.incident_id = i.id AND e.event_type = 'status_change');
SQL,

            <<<'SQL'
-- 3. Vulnerabilities -----------------------------------------------------------
INSERT INTO vulnerabilities (title, description, affected_component, severity, cvss_score, status, discovered_by, discovered_at)
SELECT * FROM (SELECT
    'Outdated TLS cipher suite accepted on legacy endpoint', 'The legacy /public/phpinfo.php diagnostic endpoint accepts weaker TLS cipher suites than the rest of the platform.',
    'Web Server / TLS Config', 'medium', 5.3, 'open',
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'), DATE_SUB(NOW(), INTERVAL 4 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM vulnerabilities WHERE title = 'Outdated TLS cipher suite accepted on legacy endpoint');
SQL,

            <<<'SQL'
INSERT INTO vulnerabilities (title, description, affected_component, severity, cvss_score, status, discovered_by, assigned_to, discovered_at)
SELECT * FROM (SELECT
    'Missing rate limiting on password reset endpoint', 'The password reset request endpoint has no per-IP rate limiting, allowing enumeration of registered email addresses.',
    'Auth Module', 'high', 7.1, 'in_progress',
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'),
    (SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'security_officer' LIMIT 1),
    DATE_SUB(NOW(), INTERVAL 12 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM vulnerabilities WHERE title = 'Missing rate limiting on password reset endpoint');
SQL,

            <<<'SQL'
INSERT INTO vulnerabilities (title, description, affected_component, severity, cvss_score, status, discovered_by, discovered_at, resolved_at)
SELECT * FROM (SELECT
    'Uploaded PDF files served without content-type sniffing protection', 'Project attachment PDFs were served without X-Content-Type-Options: nosniff, fixed by adding the header to the uploads path.',
    'File Upload Module', 'low', 3.1, 'resolved',
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'),
    DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM vulnerabilities WHERE title = 'Uploaded PDF files served without content-type sniffing protection');
SQL,

            <<<'SQL'
INSERT INTO vulnerabilities (title, description, affected_component, severity, cvss_score, status, discovered_by, discovered_at)
SELECT * FROM (SELECT
    'Debug endpoint exposed in production build', 'public/debug.php remains reachable and reveals server environment details useful for reconnaissance.',
    'Public Debug Endpoint', 'critical', 8.6, 'open',
    (SELECT u.id FROM users u WHERE u.email = 'security@uip.demo'), DATE_SUB(NOW(), INTERVAL 1 DAY)
) AS tmp WHERE NOT EXISTS (SELECT 1 FROM vulnerabilities WHERE title = 'Debug endpoint exposed in production build');
SQL,

            <<<'SQL'
-- 4. Security notifications -----------------------------------------------------
INSERT INTO security_notifications (title, message, severity, source, source_id, recipient_role, created_at)
SELECT 'New critical incident: Unusual project-file access pattern',
       'A critical severity incident was opened and needs immediate triage.',
       'critical', 'incident', i.id, NULL, i.detected_at
FROM security_incidents i WHERE i.reference_code = 'INC-2026-0003'
  AND NOT EXISTS (SELECT 1 FROM security_notifications WHERE source = 'incident' AND source_id = i.id);
SQL,

            <<<'SQL'
INSERT INTO security_notifications (title, message, severity, source, source_id, recipient_role, created_at)
SELECT 'New critical vulnerability discovered',
       'A critical severity vulnerability was logged and requires prioritized remediation.',
       'critical', 'vulnerability', v.id, NULL, v.discovered_at
FROM vulnerabilities v WHERE v.title = 'Debug endpoint exposed in production build'
  AND NOT EXISTS (SELECT 1 FROM security_notifications WHERE source = 'vulnerability' AND source_id = v.id);
SQL,

            <<<'SQL'
INSERT INTO security_notifications (title, message, severity, source, source_id, recipient_role, created_at)
SELECT 'Vulnerability assigned to Security Officer',
       'The "Missing rate limiting on password reset endpoint" vulnerability was assigned for remediation.',
       'warning', 'vulnerability', v.id, 'security_officer', DATE_ADD(v.discovered_at, INTERVAL 1 DAY)
FROM vulnerabilities v WHERE v.title = 'Missing rate limiting on password reset endpoint'
  AND NOT EXISTS (SELECT 1 FROM security_notifications WHERE source = 'vulnerability' AND source_id = v.id AND recipient_role = 'security_officer');
SQL,

            <<<'SQL'
-- 3. Admin gets everything automatically (existing rule — re-asserted
--    defensively, same as security_data_portals_seeder.sql does):
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';
SQL,

            <<<'SQL'
-- -- FAQ Smart Answer Layer — seed intents

-- 3_faq_smart_answer_layer_seed.sql
--
-- GENERATED FILE — see _generate_faq_seed.php in this same folder for
-- the source data and regeneration instructions. Every question/answer/
-- alias/keyword pairing below maps to a real, confirmed UIP feature
-- (see that file's header for exactly which controllers/routes).
--
-- Idempotent: intent_key is UNIQUE (see faq_intents' uq_faq_intents_key),
-- so re-running this file only touches updated_at on rows that already
-- exist instead of failing or duplicating — same spirit as every other
-- migration/seed file's IF-NOT-EXISTS convention.

INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('AUTH_REGISTER','auth',NULL,'إزاي أعمل حساب جديد على المنصة؟','How do I create a new account on UIP?','تقدر تعمل حساب جديد من صفحة \"تسجيل\" (Register) في أعلى الموقع:
1. اختار نوع الحساب المناسب (طالب أو جامعة).
2. املأ بياناتك الأساسية (الاسم، البريد الإلكتروني، كلمة المرور، والبيانات الخاصة بنوع حسابك مثل الجامعة/الكلية للطالب).
3. أرسل النموذج، ثم افتح بريدك الإلكتروني لتفعيل الحساب برابط التحقق اللي هيوصلك.
بعد التفعيل هتقدر تسجل الدخول مباشرة.','You can create a new account from the **Register** page:
1. Choose the account type that fits you (Student or University).
2. Fill in your basic details (name, email, password, plus type-specific fields such as your university/faculty for a student).
3. Submit the form, then check your email and click the verification link to activate your account.
Once verified, you can log in right away.','[\"ازاي اعمل حساب جديد\",\"ازاي اسجل في المنصة\",\"عايز اعمل اكونت جديد\",\"ازاي افتح حساب\",\"فين صفحة التسجيل\",\"اقدر اعمل حساب ازاي\",\"ممكن اعرف ازاي اسجل حساب\"]','[\"how to register\",\"how do I sign up\",\"create a new account\",\"where do I register\",\"how can I make an account\",\"sign up for UIP\"]','[\"ازاي اعمل register\",\"ازاي اعمل sign up\",\"how ينفع اعمل حساب\",\"ازاي أعمل account جديد\"]','[\"اعمل+حساب\",\"افتح+حساب\",\"سجل+حساب\",\"انشاء+حساب\",\"create+account\",\"sign+up\",\"register+account\",\"new+account\"]',5,0.78)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
SQL,

            <<<'SQL'
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('AUTH_LOGIN','auth',NULL,'ازاي أسجل الدخول للمنصة؟','How do I log in to UIP?','من صفحة \"تسجيل الدخول\" (Login) اكتب البريد الإلكتروني وكلمة المرور اللي سجلت بيهم، واضغط دخول. لو نسيت كلمة المرور استخدم رابط \"نسيت كلمة المرور\" في نفس الصفحة.','Go to the **Login** page and enter the email and password you registered with, then click Log In. If you\'ve forgotten your password, use the \"Forgot password\" link on the same page.','[\"ازاي ادخل حسابي\",\"مش عارف ادخل ازاي\",\"فين تسجيل الدخول\",\"ازاي اسجل دخول\",\"عايز ادخل على حسابي\"]','[\"how to log in\",\"how do I sign in\",\"where is the login page\",\"can\'t log in\"]','[\"ازاي اعمل login\",\"ازاي ادخل log in\"]','[\"سجل+دخول\",\"ادخل+حساب\",\"log+in\",\"sign+in\"]',5,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('AUTH_FORGOT_PASSWORD','auth',NULL,'نسيت كلمة المرور، أعمل إيه؟','I forgot my password, what do I do?','من صفحة تسجيل الدخول اضغط على \"نسيت كلمة المرور؟\"، اكتب بريدك الإلكتروني، وهيوصلك رابط لإعادة تعيين كلمة المرور. افتح الرابط واختار كلمة مرور جديدة.','On the Login page, click **\"Forgot password?\"**, enter your email address, and you\'ll receive a link to reset your password. Open the link and choose a new password.','[\"نسيت الباسورد\",\"ازاي استرجع كلمة المرور\",\"عايز اغير كلمة المرور بسبب اني نسيتها\",\"ازاي اعمل ريست باسورد\",\"مش فاكر كلمة السر\"]','[\"forgot my password\",\"reset my password\",\"how do I reset password\",\"can\'t remember my password\"]','[\"نسيت ال password\",\"ازاي اعمل reset للباسورد\",\"ازاي اعمل forgot password\"]','[\"نسيت+باسورد\",\"نسيت+كلمة\",\"استرجاع+كلمة\",\"forgot+password\",\"reset+password\"]',5,0.78)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('AUTH_VERIFY_EMAIL','auth',NULL,'مش لاقي إيميل تفعيل الحساب، أعمل إيه؟','I didn\'t receive the account verification email, what should I do?','تأكد الأول من مجلد الرسائل غير المرغوب فيها (Spam/Junk). لو مش لاقيه برضه، ارجع لصفحة تسجيل الدخول وحاول تسجل الدخول عادي — النظام غالبًا هيديك خيار إعادة إرسال رابط التفعيل لبريدك الإلكتروني.','First check your Spam/Junk folder. If it\'s still not there, try logging in normally — the system will typically offer to resend the verification link to your email.','[\"مفعلتش الايميل\",\"مجاش ايميل التفعيل\",\"ازاي افعل الحساب\",\"ازاي اأكد الايميل بتاعي\"]','[\"verification email not received\",\"how do I verify my email\",\"account not activated\"]','[\"مجاش verification email\",\"ازاي اعمل verify للايميل\"]','[\"تفعيل+حساب\",\"تفعيل+ايميل\",\"verify+email\",\"verification+email\"]',3,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('ADD_GRADUATION_PROJECT','student_projects','student','ازاي أضيف مشروع التخرج بتاعي؟','How can I add my graduation project?','من بوابة الطالب اروح على \"مشاريعي\" (Projects) في القائمة الجانبية، بعدين اضغط \"إنشاء مشروع\" (Create Project). املأ بيانات المشروع (الاسم، الوصف، التصنيف...)، واحفظ. بعد الإنشاء تقدر ترفع ملفات، تضيف روابط GitHub/عرض حي، وتدعو زمايلك في الفريق من نفس صفحة المشروع.','From the Student Portal, go to **Projects** in the sidebar, then click **Create Project**. Fill in the project details (name, description, category...) and save. After creating it, you can upload files, add GitHub/live-demo links, and invite teammates — all from the project\'s own page.','[\"ازاي أضيف مشروع التخرج؟\",\"إزاي أضيف مشروع التخرج؟\",\"ازاى اضيف مشروع التخرج\",\"ممكن أضيف مشروع تخرج إزاي؟\",\"أضيف مشروع التخرج منين؟\",\"فين إضافة مشروع التخرج؟\",\"عايز أرفع مشروع التخرج\",\"ازاي ارفع مشروع تخرجي\",\"اضيف مشروع التخرج منين\",\"أعمل ايه عشان أضيف مشروع التخرج؟\",\"فين مكان إضافة مشروع التخرج؟\",\"عايز اضيف مشروع تخرجي بتاعي\",\"محتاج اضيف مشروع تخرج\",\"ينفع أضيف مشروع تخرج ازاي\",\"اقدر أضيف مشروع ازاي\"]','[\"How do I add my graduation project?\",\"How do I submit a graduation project?\",\"Where can I upload my graduation project?\",\"How do I create a graduation project?\",\"How can I add my project?\",\"Where do I add my graduation project?\",\"how to add a project\",\"how do I create a new project\",\"where do I upload my project\"]','[\"ازاي ارفع graduation project\",\"ازاي أضيف graduation project؟\",\"how اضيف project؟\",\"ازاي اعمل create project\",\"عايز اعمل add project\",\"ازاي اعمل upload لمشروع التخرج\"]','[\"اضيف+مشروع\",\"ارفع+مشروع\",\"انشاء+مشروع\",\"اعمل+مشروع\",\"مشروع+تخرج\",\"add+project\",\"create+project\",\"upload+project\",\"submit+project\",\"new+project\"]',10,0.75)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('EDIT_PROJECT','student_projects','student','ازاي أعدل في بيانات مشروعي؟','How do I edit my project details?','من صفحة \"مشاريعي\"، افتح المشروع اللي عايز تعدله واضغط \"تعديل\" (Edit). تقدر تغير الاسم، الوصف، التصنيف، وأي بيانات تانية، بعدين احفظ التعديلات.','Open the project from your **Projects** list and click **Edit**. You can change the name, description, category, and other fields, then save your changes.','[\"ازاي اعدل مشروعي\",\"عايز اغير بيانات المشروع\",\"ازاي احدث المشروع بتاعي\",\"فين تعديل المشروع\"]','[\"how do I update my project\",\"edit project details\",\"change project information\"]','[\"ازاي اعمل edit للمشروع\",\"عايز اعمل update لمشروعي\"]','[\"عدل+مشروع\",\"تعديل+مشروع\",\"غير+مشروع\",\"edit+project\",\"update+project\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('UPLOAD_PROJECT_FILES','student_projects','student','ازاي أرفع ملفات المشروع؟','How do I upload files to my project?','من صفحة المشروع، هتلاقي قسم \"الملفات\" (Files) — اضغط عليه وارفع الملف من جهازك. تقدر كمان تستبدل أو تمسح أي ملف مرفوع من نفس القسم.','On your project\'s page, find the **Files** section and upload files from your device. You can also replace or delete an uploaded file from the same section.','[\"ازاي ارفع ملف للمشروع\",\"فين ارفع ملفات\",\"عايز اضيف ملف للمشروع بتاعي\",\"ازاي اضيف مستندات للمشروع\"]','[\"how do I upload project files\",\"where do I attach files to my project\",\"add documents to project\"]','[\"ازاي اعمل upload لملفات المشروع\"]','[\"ارفع+ملف\",\"اضيف+ملف\",\"upload+file\",\"attach+file\",\"project+file\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('ADD_PROJECT_LINKS','student_projects','student','ازاي أضيف رابط GitHub أو عرض حي للمشروع؟','How do I add a GitHub or live demo link to my project?','من صفحة المشروع، روح لقسم \"الروابط\" (Links)، اضغط \"إضافة رابط\" وحط رابط GitHub أو الموقع الحي (Live Demo) بتاعك. تقدر تعدل أو تمسح أي رابط بعد كده من نفس القسم.','On your project\'s page, go to the **Links** section, click **Add Link**, and paste your GitHub repository or live demo URL. You can edit or remove a link later from the same section.','[\"ازاي اضيف لينك جيت هب للمشروع\",\"عايز اضيف رابط المشروع الحي\",\"ازاي احط رابط جيتهاب\"]','[\"how do I add a github link\",\"add live demo link to project\",\"attach project links\"]','[\"ازاي اضيف github link للمشروع\",\"ازاي اعمل add لل live demo link\"]','[\"اضيف+رابط\",\"رابط+جيتهاب\",\"add+link\",\"github+link\",\"live+demo\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('INVITE_TEAM_MEMBER','student_projects','student','ازاي أضيف زميلي في فريق المشروع؟','How do I add a teammate to my project?','من صفحة المشروع، روح لقسم \"الفريق\" (Team) واضغط \"دعوة عضو\" (Invite). اكتب اسم أو إيميل زميلك، وابعتله الدعوة. لما يقبلها هيظهر في فريق المشروع تلقائيًا.','On your project\'s page, go to the **Team** section and click **Invite Member**. Enter your teammate\'s name or email and send the invitation — once they accept, they\'ll appear in the project\'s team automatically.','[\"ازاي ادعو حد في المشروع\",\"عايز اضيف عضو جديد في الفريق\",\"ازاي اضيف صاحبي في المشروع\",\"ازاي ابعت دعوة لزميلي\"]','[\"how do I invite a team member\",\"add a member to my project team\",\"how do I invite a teammate\"]','[\"ازاي اعمل invite لزميلي\",\"ازاي اضيف team member\"]','[\"دعوة+عضو\",\"اضيف+زميل\",\"اضيف+فريق\",\"invite+member\",\"invite+team\",\"add+teammate\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('REMOVE_TEAM_MEMBER','student_projects','student','ازاي أشيل حد من فريق المشروع؟','How do I remove someone from my project team?','من صفحة المشروع، في قسم \"الفريق\" (Team)، هتلاقي جنب اسم العضو زرار \"إزالة\" (Remove) — اضغط عليه لإزالته من فريق المشروع.','On your project\'s page, in the **Team** section, click **Remove** next to the member\'s name to remove them from the project\'s team.','[\"ازاي احذف عضو من الفريق\",\"عايز اشيل زميلي من المشروع\",\"ازاي امسح حد من فريق المشروع\"]','[\"how do I remove a team member\",\"delete a member from my project\"]','[\"ازاي اعمل remove لعضو من الفريق\"]','[\"شيل+عضو\",\"احذف+عضو\",\"remove+member\",\"remove+team\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('DELETE_PROJECT','student_projects','student','ازاي أحذف مشروعي؟','How do I delete my project?','من صفحة \"مشاريعي\"، افتح المشروع اللي عايز تمسحه ودور على خيار \"حذف\" (Delete) في صفحته. هتحتاج تأكيد الحذف لأن الخطوة دي نهائية.','Open the project from your **Projects** list and look for the **Delete** option on its page. You\'ll need to confirm, since this action is permanent.','[\"ازاي امسح مشروعي\",\"عايز احذف مشروع كامل\",\"ازاي اشيل مشروع من صفحتي\"]','[\"how do I delete a project\",\"remove my project completely\"]','[\"ازاي اعمل delete للمشروع\"]','[\"احذف+مشروع\",\"امسح+مشروع\",\"delete+project\",\"remove+project\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('PROJECT_CODE_REVIEW','student_projects','student','المنصة بتراجع الكود بتاعي بالذكاء الاصطناعي؟','Can UIP review my project code with AI?','أيوه، من صفحة مشروعك تقدر تشغّل \"مراجعة الكود بالذكاء الاصطناعي\" (AI Code Review) — هيحلل الكود المرفوع ويديك ملاحظات وتقييم تلقائي.','Yes — from your project\'s page you can run **AI Code Review**, which analyzes your uploaded code and gives you automated feedback and a score.','[\"فيه مراجعة كود بالذكاء الاصطناعي\",\"ازاي اعمل مراجعة للكود\",\"المنصة بتقيم الكود ازاي\"]','[\"how does AI code review work\",\"run code review on my project\",\"ai code analysis\"]','[\"ازاي اعمل code review للمشروع\"]','[\"مراجعة+كود\",\"تقييم+كود\",\"code+review\",\"ai+review\"]',3,0.82)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('GRADUATION_CERTIFICATE','graduation','student','ازاي أشوف أو أنزل شهادة التخرج بتاعتي؟','How do I view or download my graduation certificate?','من بوابة الطالب روح لصفحة \"التخرج\" (Graduation) في القائمة الجانبية. هتلاقي هناك السجل الأكاديمي (Transcript) وشهادة التخرج (Certificate) بعد اعتمادهم من جامعتك.','From the Student Portal, go to the **Graduation** page in the sidebar. There you\'ll find your academic transcript and your graduation certificate once your university has approved them.','[\"فين شهادة التخرج\",\"ازاي انزل السجل الاكاديمي\",\"عايز اشوف شهادتي\",\"ازاي احمل شهادة التخرج\"]','[\"where is my graduation certificate\",\"download my transcript\",\"view my academic record\"]','[\"ازاي انزل ال certificate\",\"فين ال transcript بتاعي\"]','[\"شهادة+تخرج\",\"سجل+اكاديمي\",\"graduation+certificate\",\"academic+transcript\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('SUPERVISOR_REVIEW_PROJECT','supervision','supervisor','ازاي أراجع وأقيّم مشروع طالب؟','How do I review and grade a student\'s project?','من بوابة المشرف روح لصفحة \"المشاريع\" (Projects)، افتح مشروع الطالب، وتقدر توافق (Approve)، ترفض (Reject)، أو تطلب تعديلات (Request Changes). فيه كمان صفحة \"تقييم\" (Grade) منفصلة لتسجيل الدرجة والملاحظات.','From the Supervisor Portal, go to **Projects**, open the student\'s project, and you can **Approve**, **Reject**, or **Request Changes**. There\'s also a separate **Grade** page to record the score and feedback.','[\"ازاي اوافق على مشروع الطالب\",\"ازاي ارفض مشروع طالب\",\"ازاي احط درجة للمشروع\",\"فين تقييم المشاريع\"]','[\"how do I approve a student project\",\"how do I grade a project\",\"reject a student project\"]','[\"ازاي اعمل approve للمشروع\",\"ازاي اعمل grade للمشروع\"]','[\"وافق+مشروع\",\"رفض+مشروع\",\"قيم+مشروع\",\"approve+project\",\"grade+project\",\"review+project\"]',5,0.78)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('UNIVERSITY_GRADUATION_APPROVAL','graduation','university,faculty','ازاي أعتمد سجل تخرج طالب؟','How do I approve a student\'s graduation record?','من بوابة الجامعة (أو الكلية)، روح لصفحة \"التخرج\" (Graduation)، افتح سجل الطالب، راجع البيانات، وبعدين اضغط \"اعتماد\" (Approve). لو محتاج تعدل بيانات قبل الاعتماد فيه خيار \"تعديل\" (Edit) في نفس الصفحة، وخيار \"إلغاء الاعتماد\" (Revoke) لو احتجت ترجع فيه.','From the University (or Faculty) Portal, go to the **Graduation** page, open the student\'s record, review it, then click **Approve**. There\'s an **Edit** option if you need to correct details first, and **Revoke** if you need to undo an approval.','[\"ازاي اعتمد شهادة الطالب\",\"فين اعتماد التخرج\",\"ازاي اراجع سجلات التخرج\"]','[\"how do I approve graduation records\",\"review student graduation\",\"certify a student graduated\"]','[\"ازاي اعمل approve للتخرج\"]','[\"اعتماد+تخرج\",\"اعتماد+شهادة\",\"approve+graduation\",\"graduation+record\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('SEND_MESSAGE','messaging',NULL,'ازاي أبعت رسالة لحد على المنصة؟','How do I send a message to someone on UIP?','من صفحة \"الرسائل\" (Messages) في بوابتك، اضغط \"رسالة جديدة\"، دور على الشخص اللي عايز تكلمه، واكتب رسالتك وابعتها.','From your portal\'s **Messages** page, click **New Message**, search for the person you want to reach, and send your message.','[\"ازاي ابعت ماسدج\",\"فين الرسائل\",\"عايز اكلم حد على المنصة\",\"ازاي اراسل شخص\"]','[\"how do I send a message\",\"where do I message someone\",\"start a new conversation\"]','[\"ازاي ابعت message\",\"ازاي اعمل new message\"]','[\"ابعت+رسالة\",\"راسل+حد\",\"send+message\",\"new+message\"]',3,0.82)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('NOTIFICATIONS_MANAGE','notifications',NULL,'ازاي أدير أو أعلّم الإشعارات كمقروءة؟','How do I manage notifications or mark them as read?','من صفحة \"الإشعارات\" (Notifications) تقدر تعلّم أي إشعار كمقروء، تثبته (Pin)، تؤرشفه، أو تمسحه. وفيه زرار \"تعليم الكل كمقروء\" (Mark all as read) لو عايز تنضف القايمة مرة واحدة.','From the **Notifications** page you can mark any notification as read, pin it, archive it, or delete it. There\'s a **Mark all as read** button if you want to clear everything at once.','[\"فين الإشعارات\",\"ازاي اعلم الاشعارات مقروءة\",\"ازاي امسح اشعار\",\"عايز اثبت اشعار مهم\"]','[\"how do I mark notifications as read\",\"clear all notifications\",\"pin a notification\"]','[\"ازاي اعمل mark as read للاشعارات\"]','[\"علم+اشعار\",\"اشعارات+مقروءة\",\"mark+read\",\"notifications+settings\"]',3,0.82)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('CHANGE_PASSWORD','settings',NULL,'ازاي أغيّر كلمة المرور بتاعتي وأنا داخل حسابي؟','How do I change my password while logged in?','من صفحة \"الإعدادات\" (Settings) في بوابتك، روح لقسم \"كلمة المرور\" (Password)، اكتب كلمة المرور الحالية والجديدة، واحفظ. لو عايز تفعّل حماية إضافية فيه كمان \"التحقق بخطوتين\" (2FA) في نفس الصفحة.','From your portal\'s **Settings** page, go to the **Password** section, enter your current and new password, and save. For extra security, you can also enable **Two-Factor Authentication (2FA)** on the same page.','[\"ازاي اغير الباسورد بتاعي\",\"فين تغيير كلمة المرور\",\"عايز افعل التحقق بخطوتين\",\"ازاي افعل 2fa\"]','[\"how do I update my password\",\"change password from settings\",\"enable two-factor authentication\"]','[\"ازاي اعمل change password\",\"ازاي افعل ال 2fa\"]','[\"غير+باسورد\",\"تغيير+كلمة\",\"change+password\",\"update+password\",\"two+factor\"]',4,0.8)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('EDIT_PROFILE','settings',NULL,'ازاي أعدل بياناتي الشخصية أو صورتي؟','How do I edit my personal info or profile photo?','من صفحة \"الملف الشخصي\" (Profile) في بوابتك، تقدر تعدل بياناتك وترفع صورة شخصية جديدة (Avatar) من نفس الصفحة.','From your portal\'s **Profile** page you can edit your personal details and upload a new profile photo (avatar) — all in the same place.','[\"ازاي اغير صورتي الشخصية\",\"فين تعديل الملف الشخصي\",\"عايز احدث بياناتي\"]','[\"how do I update my profile\",\"change my profile picture\",\"edit personal information\"]','[\"ازاي اعمل update للprofile\",\"ازاي اغير ال avatar بتاعي\"]','[\"عدل+ملف\",\"غير+صورة\",\"edit+profile\",\"update+avatar\",\"profile+photo\"]',3,0.82)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;
INSERT INTO faq_intents (`intent_key`,`category`,`role`,`question_ar`,`question_en`,`answer_ar`,`answer_en`,`aliases_ar`,`aliases_en`,`aliases_mixed`,`keywords`,`priority`,`confidence_threshold`) VALUES ('AI_ASSISTANT_CAPABILITIES','ai_assistant',NULL,'إيه اللي المساعد الذكي بتاع المنصة يقدر يساعدني فيه؟','What can the UIP AI Assistant help me with?','المساعد الذكي بيقدر يساعدك في الأسئلة عن استخدام المنصة (زي إضافة مشروع، أو إدارة الفريق)، ويجاوبك بالعربي أو الإنجليزي حسب اللغة اللي بتكتب بيها. لكنه مش بيقدر يعمل إجراءات فعلية بدالك (زي الموافقة على مشروع) — هو بيوجهك بس ازاي تعملها بنفسك.','The AI Assistant can help answer questions about using the platform (like adding a project or managing your team), and replies in Arabic or English depending on how you write. It can\'t perform actions on your behalf (like approving a project) — it only guides you on how to do it yourself.','[\"المساعد الذكي بيعمل ايه\",\"ايه امكانيات المساعد\",\"هل المساعد يقدر يعمل حاجات بدالي\"]','[\"what does the ai assistant do\",\"ai assistant capabilities\",\"what can the chatbot help with\"]','[\"ال ai assistant بيعمل ايه\"]','[\"المساعد+الذكي\",\"امكانيات+المساعد\",\"ai+assistant\",\"assistant+capabilities\",\"chatbot+help\"]',2,0.82)
  ON DUPLICATE KEY UPDATE updated_at = updated_at;

SET FOREIGN_KEY_CHECKS = 1;
SQL,

        ];
    }
}
