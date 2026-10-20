<?php
// يختبر App\Support\DriveArchive (تنضيف الأسماء + قراءة zip بأمان) بدون Laravel ولا DB:  php tests/Harness/drive_archive_harness.php
require dirname(__DIR__, 2) . '/app/Support/DriveException.php';
require dirname(__DIR__, 2) . '/app/Support/DriveArchive.php';

use App\Support\DriveArchive as D;
use App\Support\DriveException;

$pass = 0; $fail = 0;
function check(string $n, bool $c, $x = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n $x\n"; } }
function throws(callable $f): bool { try { $f(); return false; } catch (DriveException) { return true; } }

check('plain name kept', D::cleanName('report.pdf') === 'report.pdf');
check('illegal chars replaced', D::cleanName('a/b\\c:d*e?f"g<h>i|j') === 'a_b_c_d_e_f_g_h_i_j');
check('control chars removed', D::cleanName("na\x00me\x1F.txt") === 'name.txt');
check('trailing dots/spaces trimmed', D::cleanName('  hello. . ') === 'hello');
check('dot names rejected', D::cleanName('.') === '' && D::cleanName('..') === '' && D::cleanName('   ') === '');
check('arabic kept', D::cleanName('تقرير المشروع.docx') === 'تقرير المشروع.docx');
$long = str_repeat('x', 400) . '.pdf';
check('long name keeps extension', str_ends_with(D::cleanName($long), '.pdf') && mb_strlen(D::cleanName($long)) <= 200);
check('dotfile stays a dotfile', D::cleanName('.env') === '.env');

$taken = ['a.txt' => 1, 'a (2).txt' => 1, 'Makefile' => 1, '.env' => 1];
$ex = fn ($n) => isset($taken[$n]);
check('unique: free name unchanged', D::uniqueName('b.txt', $ex) === 'b.txt');
check('unique: skips taken suffixes', D::uniqueName('a.txt', $ex) === 'a (3).txt');
check('unique: no extension', D::uniqueName('Makefile', $ex) === 'Makefile (2)');
check('unique: dotfile', D::uniqueName('.env', $ex) === '.env (2)');

check('segments normal', D::segments('a/b/c.txt') === ['a', 'b', 'c.txt']);
check('segments backslash', D::segments('a\\b\\c.txt') === ['a', 'b', 'c.txt']);
check('segments dir trailing slash', D::segments('a/b/') === ['a', 'b']);
check('segments ./ prefix', D::segments('./a/./b') === ['a', 'b']);
check('junk skipped', D::isJunk('__MACOSX/a') && D::isJunk('x/.DS_Store') && !D::isJunk('x/y.txt'));
check('zip-slip ../ rejected', D::segments('../x') === null);
check('zip-slip nested rejected', D::segments('a/../../x') === null);
check('absolute path rejected', D::segments('/etc/passwd') === null);
check('windows drive rejected', D::segments('C:\\Windows\\x') === null);
check('NUL rejected', D::segments("a\0b") === null);

function mkzip(array $files, array $dirs = []): string {
    $p = tempnam(sys_get_temp_dir(), 'dz'); @unlink($p); $p .= '.zip';
    $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE);
    foreach ($dirs as $d) { $z->addEmptyDir($d); }
    foreach ($files as $n => $c) { $z->addFromString($n, $c); }
    $z->close();
    return $p;
}
function openz(string $p): ZipArchive { $z = new ZipArchive(); $z->open($p); return $z; }

$p = mkzip(['proj/a.txt' => 'hello', 'proj/src/b.txt' => 'world!!', '__MACOSX/x' => 'junk', 'proj/.DS_Store' => 'j'], ['proj/empty']);
$z = openz($p); $e = D::scan($z, 100, 10000);
check('scan: junk filtered, 3 entries', count($e) === 3, count($e));
check('scan: total size', array_sum(array_column($e, 'size')) === 12);
check('scan: dir flagged', count(array_filter($e, fn ($x) => $x['dir'])) === 1);
$tmp = tempnam(sys_get_temp_dir(), 'ce');
$idx = array_values(array_filter($e, fn ($x) => $x['segments'] === ['proj', 'a.txt']))[0]['index'];
check('copyEntry content', D::copyEntry($z, $idx, $tmp, 1000) === 5 && file_get_contents($tmp) === 'hello');
check('copyEntry: byte budget enforced', throws(fn () => D::copyEntry($z, $idx, $tmp, 3)));
$z->close();

$p2 = mkzip(['../evil.txt' => 'x']);
check('scan: zip-slip archive rejected', throws(fn () => D::scan(openz($p2), 100, 10000)));
check('scan: too many entries', throws(fn () => D::scan(openz($p), 1, 10000)));
check('scan: declared size over budget', throws(fn () => D::scan(openz($p), 100, 5)));

$p3 = mkzip(['ok.txt' => 'ok']);
$z3 = new ZipArchive(); $z3->open($p3); $z3->addFromString('link', 'ok.txt');
$z3->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16); $z3->close();
$e3 = D::scan(openz($p3), 100, 10000);
check('scan: symlink entry skipped', count($e3) === 1 && $e3[0]['segments'] === ['ok.txt']);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
