<?php
// يختبر App\Support\PersistentStorage على filesystem حقيقي (مجلدات مؤقتة) من غير ما نشغّل Laravel كامل.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/ps_' . bin2hex(random_bytes(4));
$base = "$tmp/app"; $vol = "$tmp/vol";
mkdir("$base/public/uploads/reports", 0775, true);
mkdir("$base/storage/app/private/proctor", 0775, true);
mkdir("$base/storage/logs", 0775, true);
file_put_contents("$base/public/uploads/reports/old.pdf", 'PDF-OLD');
file_put_contents("$base/storage/app/private/proctor/snap.jpg", 'SNAP');

$app = new Illuminate\Foundation\Application($base);
$app->instance('config', new Illuminate\Config\Repository(['persistence' => ['path' => $vol, 'links' => [
    'public/uploads' => 'uploads', 'storage/app/private' => 'storage-private',
    'storage/app/exam-exports' => 'exam-exports', 'storage/logs/security' => 'security-logs']]]));
Illuminate\Container\Container::setInstance($app);

use App\Support\PersistentStorage as P;
$pass = 0; $fail = 0;
function check(string $n, bool $c, $x = '') { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n $x\n"; } }

check('plan has 4 links under volume', count(P::plan()) === 4);
check('not linked before apply', P::isLinked() === false);
$strict = P::apply();
check('strict apply refuses when the volume path is not a real mount', count(array_filter($strict, fn($s) => str_contains($s, 'not a mounted volume'))) === 4 && !is_link("$base/public/uploads"), json_encode($strict));
P::ensure();
check('ensure() is a silent no-op when not mounted (build-time safety)', !is_link("$base/public/uploads") && is_file("$base/public/uploads/reports/old.pdf"));
$r = P::apply(false);
check('all 4 linked', array_values($r) === ['linked','linked','linked','linked'], json_encode($r));
check('public/uploads is a symlink to volume', is_link("$base/public/uploads") && readlink("$base/public/uploads") === "$vol/uploads");
check('existing upload moved into volume', file_get_contents("$vol/uploads/reports/old.pdf") === 'PDF-OLD');
check('existing proctoring data moved', file_get_contents("$vol/storage-private/proctor/snap.jpg") === 'SNAP');
check('old file still readable through the path', file_get_contents("$base/public/uploads/reports/old.pdf") === 'PDF-OLD');
file_put_contents("$base/public/uploads/new.txt", 'NEW');
check('new writes land in the volume', is_file("$vol/uploads/new.txt"));
check('missing dirs created + linked (security logs, exports)', is_dir("$vol/security-logs") && is_link("$base/storage/logs/security") && is_link("$base/storage/app/exam-exports"));
check('isLinked() true now', P::isLinked() === true);
$r2 = P::apply(false);
check('second apply is a no-op (ok)', array_values($r2) === ['ok','ok','ok','ok'], json_encode($r2));

// volume re-mounted empty: the symlink is intact but its target is gone -> must be recreated, not left dangling
exec('rm -rf ' . escapeshellarg("$vol/uploads"));
check('dangling link detected as not linked', P::isLinked() === false);
check('apply recreates the missing target dir', P::apply(false)['public/uploads'] === 'ok' && is_dir("$vol/uploads") && is_dir("$base/public/uploads"));
file_put_contents("$base/public/uploads/new.txt", 'NEW');

// conflict: a real dir whose file already exists in the volume -> must NOT overwrite or lose data
@unlink("$base/public/uploads"); mkdir("$base/public/uploads", 0775, true);
file_put_contents("$base/public/uploads/new.txt", 'FROM-IMAGE'); // same name already in volume (content NEW)
$r3 = P::apply(false);
check('conflict reported as error, nothing overwritten', str_starts_with($r3['public/uploads'], 'error') && file_get_contents("$vol/uploads/new.txt") === 'NEW');
check('conflicting source left untouched', file_get_contents("$base/public/uploads/new.txt") === 'FROM-IMAGE' && !is_link("$base/public/uploads"));

// wrong symlink target is reported, not silently re-pointed
$tmp2 = "$tmp/other"; mkdir($tmp2);
@unlink("$base/storage/logs/security"); symlink($tmp2, "$base/storage/logs/security");
check('foreign symlink reported', str_starts_with(P::apply(false)['storage/logs/security'], 'error: symlink points to'));

// no env => feature fully off
$app->instance('config', new Illuminate\Config\Repository(['persistence' => ['path' => null, 'links' => []]]));
check('no PERSISTENT_STORAGE_PATH => no plan, apply is empty, ensure is silent', P::root() === null && P::plan() === [] && P::apply() === []);

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
