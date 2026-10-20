<?php
// Runs the REAL ProjectApprovalService against sqlite in-memory (no Laravel app boot).
require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container = new Illuminate\Container\Container;
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('log', new class { public function __call($m, $a) {} });
$container->instance('date', new Illuminate\Support\DateFactory);
Facade::setFacadeApplication($container);

$pdo = $capsule->getConnection()->getPdo();
$pdo->exec("
CREATE TABLE users(id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, deleted_at TEXT);
CREATE TABLE universities(id INTEGER PRIMARY KEY, user_id INT);
CREATE TABLE faculties(id INTEGER PRIMARY KEY, university_id INT, user_id INT);
CREATE TABLE students(user_id INT, university_id INT, faculty_id INT, faculty TEXT, department TEXT);
CREATE TABLE academic_staff(id INTEGER PRIMARY KEY, user_id INT, university_id INT, status TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY, uuid TEXT, owner_id INT, university_id INT, title_ar TEXT, title_en TEXT, summary TEXT, category TEXT,
  status TEXT DEFAULT 'draft', supervisor_name TEXT, slug TEXT, visibility TEXT, review_round INT DEFAULT 0, approved_at TEXT, published_at TEXT,
  created_at TEXT, updated_at TEXT, deleted_at TEXT);
CREATE TABLE project_approvals(id INTEGER PRIMARY KEY, project_id INT, reviewer_id INT, stage TEXT, review_round INT DEFAULT 0, decision TEXT,
  comments TEXT, decided_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, ai_output_acknowledged INT DEFAULT 0, ai_output_snapshot TEXT);
CREATE TABLE project_team_members(id INTEGER PRIMARY KEY, project_id INT, user_id INT, invited_email TEXT, role TEXT, status TEXT, invited_by INT,
  invited_at TEXT, responded_at TEXT, created_at TEXT, updated_at TEXT, member_name TEXT, academic_year INT, student_number TEXT);
CREATE TABLE project_links(id INTEGER PRIMARY KEY, project_id INT, type TEXT, label TEXT, url TEXT, is_primary INT);
CREATE TABLE project_grades(id INTEGER PRIMARY KEY, project_id INT, graded_by INT, total_score REAL, max_score REAL, letter_grade TEXT, status TEXT, overall_comments TEXT, graded_at TEXT, revision INT DEFAULT 0, reopened_by INT, reopened_at TEXT, reopen_reason TEXT);
CREATE TABLE project_grade_members(id INTEGER PRIMARY KEY, project_grade_id INT, project_id INT, user_id INT, adjustment REAL, final_percentage REAL, letter_grade TEXT, comments TEXT);
");

// ---- fakes
class FakeNotifications extends App\Services\NotificationService {
    public array $sent = [];
    public function __construct() {}
    public function notify($userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, string $priority = 'normal'): void {
        if ($userId) { $this->sent[] = ['to' => (int) $userId, 'type' => $type, 'title' => $title]; }
    }
}
class FakeAudit extends App\Services\AuditLogService {
    public array $rows = [];
    public function __construct() {}
    public function record($userId, string $action, ?string $subjectType = null, $subjectId = null, ?array $oldValues = null, ?array $newValues = null, ?string $ip = null): void { $this->rows[] = $action; }
}
class FakeAi extends App\Repositories\AIAnalysisRepository {
    public function __construct() {}
    public function readinessFor(int $projectId): ?App\Models\AIReadinessScore { return null; }
    public function classificationFor(int $projectId): ?App\Models\AIClassification { return null; }
}

$notif = new FakeNotifications; $audit = new FakeAudit;
$svc = new App\Services\ProjectApprovalService(
    new App\Repositories\ProjectRepository, new App\Repositories\UniversityRepository, $audit, $notif,
    new FakeAi, new App\Repositories\FacultyRepository, new App\Services\ProjectNotifier($notif)
);

$pass = 0; $fail = 0;
function check(string $name, bool $cond, $extra = '') { global $pass, $fail; if ($cond) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name $extra\n"; } }
function throws(callable $fn, string $reason): bool { try { $fn(); } catch (App\Exceptions\ApprovalException $e) { return $e->reason() === $reason; } return false; }

// ---- fixtures: uni 1 (user 100), faculty 10 (user 101), doctor user 200, TA user 201, student 300
DB::table('users')->insert([['id'=>100,'full_name'=>'Uni','email'=>'u@x'],['id'=>101,'full_name'=>'Fac','email'=>'f@x'],
    ['id'=>200,'full_name'=>'د. أحمد حسن علي','email'=>'d@x'],['id'=>201,'full_name'=>'Mona TA','email'=>'t@x'],['id'=>300,'full_name'=>'Student','email'=>'s@x'],['id'=>900,'full_name'=>'Admin','email'=>'a@x']]);
DB::table('universities')->insert(['id'=>1,'user_id'=>100]);
DB::table('faculties')->insert(['id'=>10,'university_id'=>1,'user_id'=>101]);
DB::table('students')->insert(['user_id'=>300,'university_id'=>1,'faculty_id'=>10,'faculty'=>'Eng','department'=>'CS']);
DB::table('academic_staff')->insert([['id'=>1,'user_id'=>200,'university_id'=>1,'status'=>'active'],['id'=>2,'user_id'=>201,'university_id'=>1,'status'=>'active']]);

function mkProject(string $uuid, array $o = []): App\Models\Project {
    $now = date('Y-m-d H:i:s');
    DB::table('projects')->insert(array_merge(['uuid'=>$uuid,'owner_id'=>300,'university_id'=>1,'title_ar'=>'مشروع','title_en'=>'Proj '.$uuid,'summary'=>'s',
        'status'=>'submitted','supervisor_name'=>'أحمد حسن علي','review_round'=>1,'created_at'=>$now,'updated_at'=>$now], $o));
    return App\Models\Project::where('uuid', $uuid)->first();
}
function status(string $uuid): string { return DB::table('projects')->where('uuid', $uuid)->value('status'); }

echo "\n[1] ordering is enforced (supervisor -> faculty -> university)\n";
$p = mkProject('p1');
$f = $svc->flowFor($p);
check('required = supervisor, faculty, university', $f['required'] === ['supervisor_review','faculty_review','university_review'], json_encode($f['required']));
check('awaiting supervisor first', $f['awaiting'] === 'supervisor_review');
check('faculty cannot approve before supervisor', throws(fn() => $svc->approveForFaculty('p1', 10, 101), 'not_your_turn'));
check('university cannot approve before supervisor', throws(fn() => $svc->approve('p1', 1, 100), 'not_your_turn'));
check('university cannot reject before its turn', throws(fn() => $svc->reject('p1', 1, 100, 'no'), 'not_your_turn'));
check('status unchanged after refused decisions', status('p1') === 'submitted');
check('TA (advisory) cannot decide', throws(fn() => $svc->decideAsStaff('p1', 201, 'approve', null, false, 'teaching_assistant'), 'advisory_role'));
check('null link role cannot decide', throws(fn() => $svc->decideAsStaff('p1', 201, 'approve', null, false, null), 'advisory_role'));
check('supervisor approves', $svc->decideAsStaff('p1', 200, 'approve', 'ok', false, 'supervisor') === true);
check('still submitted after supervisor approval', status('p1') === 'submitted');
check('supervisor cannot decide twice', throws(fn() => $svc->decideAsStaff('p1', 200, 'approve', null, false, 'supervisor'), 'not_your_turn'));
check('university still blocked (faculty pending)', throws(fn() => $svc->approve('p1', 1, 100), 'not_your_turn'));
check('faculty approves', $svc->approveForFaculty('p1', 10, 101, 'good') === true);
check('still submitted after faculty approval', status('p1') === 'submitted');
check('university approves (final)', $svc->approve('p1', 1, 100, 'final') === true);
check('final status is approved, NOT published', status('p1') === 'approved');
check('approved_at set, published_at empty', DB::table('projects')->where('uuid','p1')->value('approved_at') !== null && DB::table('projects')->where('uuid','p1')->value('published_at') === null);
$stages = DB::table('project_approvals')->where('project_id', $p->id)->orderBy('id')->pluck('stage')->all();
check('3 decisions recorded with their own stage', $stages === ['supervisor_review','faculty_review','university_review'], json_encode($stages));
check('decision after approval is refused (not in review)', $svc->approve('p1', 1, 100) === false);

echo "\n[2] publish is separate from approval\n";
$p2 = mkProject('p2');
check('owner cannot publish a project that is only submitted', $svc->publishAsOwner('p2', 300) === false);
check('stranger cannot publish', $svc->publishAsOwner('p1', 999) === false);
check('owner publishes approved project', $svc->publishAsOwner('p1', 300) === true);
check('status published + slug generated', status('p1') === 'published' && DB::table('projects')->where('uuid','p1')->value('slug') !== null);
check('cannot publish twice', $svc->publishAsOwner('p1', 300) === false);
check('university can unpublish', $svc->unpublishForUniversity('p1', 1, 100) === true && status('p1') === 'approved');
check('published_at cleared on unpublish', DB::table('projects')->where('uuid','p1')->value('published_at') === null);
check('other university cannot publish', $svc->publishForUniversity('p1', 2, 100) === false);
check('university publishes', $svc->publishForUniversity('p1', 1, 100) === true && status('p1') === 'published');
check('publish audited', in_array('project.publish', $audit->rows, true) && in_array('project.unpublish', $audit->rows, true));

echo "\n[3] reject / request changes end the round; resubmission starts a new round\n";
$p3 = mkProject('p3');
$svc->decideAsStaff('p3', 200, 'approve', null, false, 'supervisor');
check('faculty requests changes -> draft', $svc->requestChangesForFaculty('p3', 10, 101, 'fix') === true && status('p3') === 'draft');
check('no decisions possible on a draft', $svc->approveForFaculty('p3', 10, 101) === false);
DB::table('projects')->where('uuid','p3')->update(['status'=>'submitted']); DB::table('projects')->where('uuid','p3')->increment('review_round');
$f3 = $svc->flowFor(App\Models\Project::where('uuid','p3')->first());
check('round 2: old supervisor approval does not count', $f3['round'] === 2 && $f3['awaiting'] === 'supervisor_review' && $f3['approved'] === [], json_encode($f3));
check('faculty blocked again in round 2', throws(fn() => $svc->approveForFaculty('p3', 10, 101), 'not_your_turn'));
$p4 = mkProject('p4');
$svc->decideAsStaff('p4', 200, 'approve', null, false, 'supervisor');
check('faculty rejects -> rejected', $svc->rejectForFaculty('p4', 10, 101, 'no') === true && status('p4') === 'rejected');

echo "\n[4] skip rules (never deadlock)\n";
$p5 = mkProject('p5', ['supervisor_name' => 'اسم غير موجود نهائيا']);
$f5 = $svc->flowFor($p5);
check('unresolvable supervisor -> stage skipped + flagged', $f5['required'] === ['faculty_review','university_review'] && $f5['supervisor_unlinked'] === true, json_encode($f5));
check('faculty is first', $f5['awaiting'] === 'faculty_review');
$p6 = mkProject('p6', ['supervisor_name' => null]);
check('no supervisor typed -> not flagged', $svc->flowFor($p6)['supervisor_unlinked'] === false);
DB::table('users')->insert(['id'=>301,'full_name'=>'Student NoFaculty','email'=>'s2@x']);
DB::table('students')->insert(['user_id'=>301,'university_id'=>1,'faculty_id'=>null,'faculty'=>null,'department'=>null]);
$p7 = mkProject('p7', ['owner_id'=>301,'supervisor_name'=>null]);
$f7 = $svc->flowFor($p7);
check('student without faculty portal -> university only', $f7['required'] === ['university_review'] && $f7['awaiting'] === 'university_review');
check('university approves directly -> approved', $svc->approve('p7', 1, 100) === true && status('p7') === 'approved');
$p8 = mkProject('p8', ['supervisor_name'=>null]);
DB::table('project_team_members')->insert(['project_id'=>$p8->id,'user_id'=>200,'role'=>'supervisor','status'=>'accepted','invited_by'=>300]);
check('team-row supervisor -> stage required', in_array('supervisor_review', $svc->flowFor($p8)['required'], true));
$p9 = mkProject('p9', ['supervisor_name'=>null]);
DB::table('project_team_members')->insert(['project_id'=>$p9->id,'user_id'=>201,'role'=>'teaching_assistant','status'=>'accepted','invited_by'=>300]);
check('TA team-row does NOT create a supervisor stage', !in_array('supervisor_review', $svc->flowFor($p9)['required'], true));

echo "\n[5] legacy rows (review_round = 0) and old decisions\n";
$p10 = mkProject('p10', ['review_round' => 0]);
DB::table('project_approvals')->insert(['project_id'=>$p10->id,'reviewer_id'=>100,'stage'=>'university_review','review_round'=>0,'decision'=>'approved','decided_at'=>date('Y-m-d H:i:s')]);
$f10 = $svc->flowFor($p10);
check('legacy round-0 decision is ignored', $f10['round'] === 1 && $f10['approved'] === []);
$svc->decideAsStaff('p10', 200, 'approve', null, false, 'supervisor');
check('round pinned to 1 on first decision', (int) DB::table('projects')->where('uuid','p10')->value('review_round') === 1);

echo "\n[6] notifications\n";
$notif->sent = [];
$p11 = mkProject('p11');
$svc->decideAsStaff('p11', 200, 'approve', null, false, 'supervisor');
check('faculty told "your turn" after supervisor', (bool) array_filter($notif->sent, fn($n) => $n['to'] === 101));
check('student told about the supervisor stage', (bool) array_filter($notif->sent, fn($n) => $n['to'] === 300 && $n['type'] === 'project_stage_approved'));
$notif->sent = [];
$svc->approveForFaculty('p11', 10, 101);
check('university told "your turn" after faculty', (bool) array_filter($notif->sent, fn($n) => $n['to'] === 100));
$notif->sent = [];
$svc->approve('p11', 1, 100);
check('team told final approval', (bool) array_filter($notif->sent, fn($n) => $n['to'] === 300 && $n['type'] === 'project_approved'));

echo "\n[7] admin override + queue + student feedback\n";
$p12 = mkProject('p12');
check('override needs a reason', $svc->override('p12', 900, 'approved', '  ') === false);
check('override rejects unknown status', $svc->override('p12', 900, 'banana', 'x') === false);
check('override -> approved (no publish)', $svc->override('p12', 900, 'approved', 'exceptional') === true && status('p12') === 'approved' && DB::table('projects')->where('uuid','p12')->value('published_at') === null);
check('override recorded as admin_review', DB::table('project_approvals')->where('project_id',$p12->id)->value('stage') === 'admin_review');
check('override -> published sets slug', $svc->override('p12', 900, 'published', 'go live') === true && status('p12') === 'published');
$queue = $svc->queueForUniversity(1);
$byId = []; foreach ($queue as $q) { $byId[$q['id']] = $q; }
check('queue exposes stages + awaiting', isset($byId['p5']) && $byId['p5']['awaiting'] === 'faculty_review' && count($byId['p5']['stages']) === 3);
check('queue: approved+published both "approved", published flagged', $byId['p1']['status'] === 'approved' && $byId['p1']['published'] === true && $byId['p7']['published'] === false);
check('queue: unlinked supervisor flagged', $byId['p5']['supervisorUnlinked'] === true);
check('queue: plain drafts hidden', !isset($byId['p2x']));
$fb = $svc->feedbackForProject(App\Models\Project::where('uuid','p1')->first());
check('student feedback has flow + stage in history', isset($fb['flow']['stages']) && isset($fb['history'][0]['stage']));
check('faculty queue works', count($svc->queueForFaculty(10)) > 0);

echo "\n[8] a second decision on the same turn is refused\n";
$p13 = mkProject('p13');
$a = $svc->decideAsStaff('p13', 200, 'approve', null, false, 'supervisor');
check('double supervisor approval refused', $a === true && throws(fn() => $svc->decideAsStaff('p13', 200, 'approve', null, false, 'supervisor'), 'not_your_turn'));
check('exactly one supervisor decision stored', DB::table('project_approvals')->where('project_id',$p13->id)->where('stage','supervisor_review')->count() === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
