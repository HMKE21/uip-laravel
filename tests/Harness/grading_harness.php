<?php
// Runs the REAL ProjectGradingService + GraduationRepository query against sqlite in-memory.
require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container = new Illuminate\Container\Container;
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('log', new class { public function __call($m, $a) {} });
$container->instance('date', new Illuminate\Support\DateFactory);
Facade::setFacadeApplication($container);

$capsule->getConnection()->getPdo()->exec("
CREATE TABLE users(id INTEGER PRIMARY KEY, full_name TEXT, email TEXT);
CREATE TABLE students(id INTEGER PRIMARY KEY, user_id INT);
CREATE TABLE graduation_records(id INTEGER PRIMARY KEY, student_id INT, status TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY, uuid TEXT, owner_id INT, title_ar TEXT, title_en TEXT, status TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE project_team_members(id INTEGER PRIMARY KEY, project_id INT, user_id INT, role TEXT, status TEXT);
CREATE TABLE project_grades(id INTEGER PRIMARY KEY, project_id INT UNIQUE, graded_by INT, total_score REAL, max_score REAL DEFAULT 0, letter_grade TEXT, status TEXT DEFAULT 'draft', overall_comments TEXT, graded_at TEXT, revision INT DEFAULT 0, reopened_by INT, reopened_at TEXT, reopen_reason TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE project_grade_criteria(id INTEGER PRIMARY KEY, project_grade_id INT, criterion TEXT, max_score REAL, score REAL, comments TEXT, sort_order INT, created_at TEXT, updated_at TEXT);
CREATE TABLE project_grade_members(id INTEGER PRIMARY KEY, project_grade_id INT, project_id INT, user_id INT, adjustment REAL DEFAULT 0, final_percentage REAL, letter_grade TEXT, comments TEXT, updated_by INT, created_at TEXT, updated_at TEXT, UNIQUE(project_grade_id,user_id));
CREATE TABLE project_grade_revisions(id INTEGER PRIMARY KEY, project_grade_id INT, project_id INT, revision INT, action TEXT, actor_id INT, actor_role TEXT, reason TEXT, total_score REAL, max_score REAL, letter_grade TEXT, snapshot TEXT, created_at TEXT);
");

class FakeNotifications extends App\Services\NotificationService {
    public array $sent = [];
    public function __construct() {}
    public function notify($userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, string $priority = 'normal'): void { if ($userId) { $this->sent[] = ['to' => (int) $userId, 'type' => $type]; } }
}
class FakeAudit extends App\Services\AuditLogService {
    public array $rows = [];
    public function __construct() {}
    public function record($userId, string $action, ?string $subjectType = null, $subjectId = null, ?array $oldValues = null, ?array $newValues = null, ?string $ip = null): void { $this->rows[] = $action; }
}
$notif = new FakeNotifications; $audit = new FakeAudit;
$grades = new App\Repositories\ProjectGradeRepository;
$svc = new App\Services\ProjectGradingService($grades, $audit, $notif, new App\Services\ProjectNotifier($notif));

$pass = 0; $fail = 0;
function check(string $n, bool $c, $x = '') { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n $x\n"; } }

foreach ([[1,'Owner'],[2,'Member B'],[3,'Member C'],[4,'Doctor'],[5,'Outsider']] as [$id,$n]) { DB::table('users')->insert(['id'=>$id,'full_name'=>$n,'email'=>"u$id@x"]); }
foreach ([1,2,3,5] as $uid) { DB::table('students')->insert(['id'=>$uid*10,'user_id'=>$uid]); }
DB::table('projects')->insert(['id'=>1,'uuid'=>'p1','owner_id'=>1,'title_ar'=>'م','title_en'=>'P1','status'=>'approved']);
DB::table('project_team_members')->insert([
  ['project_id'=>1,'user_id'=>2,'role'=>'student_member','status'=>'accepted'],
  ['project_id'=>1,'user_id'=>3,'role'=>'collaborator','status'=>'accepted'],
  ['project_id'=>1,'user_id'=>4,'role'=>'supervisor','status'=>'accepted'],
]);
$P = App\Models\Project::find(1);
$crit = [['criterion'=>'Report','max_score'=>50,'score'=>40],['criterion'=>'Demo','max_score'=>50,'score'=>40]]; // 80%

echo "\n[1] members\n";
check('members = owner + student/collab only (not the doctor)', $svc->gradeMemberIds($P) === [1,2,3], json_encode($svc->gradeMemberIds($P)));

echo "\n[2] individual adjustments\n";
$r = $svc->saveGrade(1, 4, $crit, 'ok', false, 'en', [['user_id'=>2,'adjustment'=>-15,'comments'=>'low contribution']]);
check('draft with member adjustment saved', $r['success'] === true, json_encode($r));
$mg = array_column($svc->memberGrades(1), null, 'user_id');
check('owner gets project grade (80% = B)', (float) $mg[1]['final_percentage'] === 80.0 && $mg[1]['letter_grade'] === 'B');
check('member B: 80-15 = 65% = D', (float) $mg[2]['final_percentage'] === 65.0 && $mg[2]['letter_grade'] === 'D');
check('adjustment without reason refused', $svc->saveGrade(1, 4, $crit, null, false, 'en', [['user_id'=>3,'adjustment'=>5]])['success'] === false);
check('non-member refused', $svc->saveGrade(1, 4, $crit, null, false, 'en', [['user_id'=>5,'adjustment'=>5,'comments'=>'x']])['success'] === false);
check('out-of-range adjustment refused', $svc->saveGrade(1, 4, $crit, null, false, 'en', [['user_id'=>3,'adjustment'=>150,'comments'=>'x']])['success'] === false);
$svc->saveGrade(1, 4, $crit, null, false, 'en', [['user_id'=>3,'adjustment'=>30,'comments'=>'led the project']]);
$mg = array_column($svc->memberGrades(1), null, 'user_id');
check('clamped at 100%', (float) $mg[3]['final_percentage'] === 100.0 && $mg[3]['letter_grade'] === 'A');
check('omitting members keeps saved adjustments', ($svc->saveGrade(1, 4, $crit, null, false, 'en')['success'] ?? false) && (float) array_column($svc->memberGrades(1), null, 'user_id')[2]['adjustment'] === -15.0);

echo "\n[3] finalize locks the grade\n";
$notif->sent = [];
$r = $svc->saveGrade(1, 4, $crit, 'final', true, 'en');
check('finalized', $r['success'] === true && $grades->findByProject(1)->status === 'final');
check('revision = 1 + finalized log', (int) $grades->findByProject(1)->revision === 1 && DB::table('project_grade_revisions')->where('action','finalized')->count() === 1);
check('individual notification to adjusted members', (bool) array_filter($notif->sent, fn($n) => $n['to'] === 2 && $n['type'] === 'project_graded_individual'));
$r = $svc->saveGrade(1, 4, [['criterion'=>'Report','max_score'=>50,'score'=>10]], null, false, 'en');
check('saving a locked grade is refused', $r['success'] === false && ($r['code'] ?? '') === 'grade_locked');
check('locked grade unchanged', (float) $grades->findByProject(1)->total_score === 80.0);
check('finalize again on locked is refused too', ($svc->saveGrade(1, 4, $crit, null, true, 'en')['code'] ?? '') === 'grade_locked');

echo "\n[4] graduation reads the individual grade\n";
$g2 = $grades->finalGradesForUser(2); $g3 = $grades->finalGradesForUser(3); $g1 = $grades->finalGradesForUser(1); $g5 = $grades->finalGradesForUser(5);
check('member B sees own letter D (not the project B)', ($g2[0]['letter_grade'] ?? null) === 'D' && $g2[0]['project_letter_grade'] === 'B');
check('non-owner collaborator now has a final grade (A)', ($g3[0]['letter_grade'] ?? null) === 'A');
check('owner keeps project grade', ($g1[0]['letter_grade'] ?? null) === 'B');
check('outsider has none', $g5 === []);

echo "\n[5] reopen with reason\n";
check('reason required', ($svc->reopen(1, 4, 'supervisor', '  ')['code'] ?? '') === 'reason_required');
check('draft grade cannot be reopened', ($svc->reopen(99, 4, 'supervisor', 'x')['success'] ?? true) === false);
$notif->sent = [];
$r = $svc->reopen(1, 4, 'supervisor', 'scoring error in demo');
check('reopened', $r['success'] === true && $grades->findByProject(1)->status === 'draft');
check('reason + actor stored', $grades->findByProject(1)->reopen_reason === 'scoring error in demo' && (int) $grades->findByProject(1)->reopened_by === 4);
check('reopen logged + audited + team notified', DB::table('project_grade_revisions')->where('action','reopened')->count() === 1 && in_array('project.grade_reopen', $audit->rows, true) && (bool) array_filter($notif->sent, fn($n) => $n['type'] === 'project_grade_reopened'));
check('while reopened graduation sees no final grade', $grades->finalGradesForUser(1) === []);
$r = $svc->saveGrade(1, 4, [['criterion'=>'Report','max_score'=>50,'score'=>50],['criterion'=>'Demo','max_score'=>50,'score'=>45]], 'fixed', true, 'en');
check('re-finalize works: revision 2, 95% = A', $r['success'] === true && (int) $grades->findByProject(1)->revision === 2 && $grades->findByProject(1)->letter_grade === 'A');
check('reopen state cleared on finalize', $grades->findByProject(1)->reopen_reason === null);
check('history keeps both rounds', count($svc->revisions(1)) === 3);

echo "\n[6] graduated member: only the university may reopen\n";
DB::table('graduation_records')->insert(['student_id'=>20,'status'=>'graduated']); // member B (students.id 20)
check('supervisor blocked', ($svc->reopen(1, 4, 'supervisor', 'late fix')['code'] ?? '') === 'graduated_requires_university');
check('faculty blocked', ($svc->reopen(1, 6, 'faculty', 'late fix')['code'] ?? '') === 'graduated_requires_university');
check('grade still final', $grades->findByProject(1)->status === 'final');
check('university can reopen', ($svc->reopen(1, 7, 'university', 'committee decision')['success'] ?? false) === true);
check('revision records the actor role', DB::table('project_grade_revisions')->where('actor_role','university')->count() === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
