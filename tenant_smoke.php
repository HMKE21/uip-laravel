<?php
/**
 * اختبار عزل سريع من غير HTTP/nginx/Vercel: بيشغّل الـ kernel جوه نفس الـ process.
 * التشغيل (من مجلد المشروع):  php tenant_smoke.php boss@demo.com 'الباسورد' demo.example.com
 * بيقفل مؤقتًا "القناة الآمنة" (enc_required) في الـ process ده بس، من غير ما يغيّر أي إعداد على السيرفر.
 */
use Illuminate\Http\Request;

[$_, $email, $pass, $domain] = $argv + [null, 'boss@demo.com', '', 'demo.example.com'];

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

function hit($kernel, string $method, string $uri, array $body = [], array $headers = []): array
{
    config(['secure_transport.enforce' => false, 'secure_transport.enforce_requests' => false]);
    \App\Support\PlatformPolicy::flush();
    $req = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], $body ? json_encode($body) : null);
    foreach ($headers as $k => $v) {
        $req->headers->set($k, $v);
    }
    $res = $kernel->handle($req);
    $kernel->terminate($req, $res);
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: ['raw' => substr($res->getContent(), 0, 150)]];
}
function show(string $title, array $r, bool $ok): void
{
    printf("%s %s\n   HTTP %d  %s\n\n", $ok ? '✅' : '❌', $title, $r[0], substr(json_encode($r[1], JSON_UNESCAPED_UNICODE), 0, 160));
}

$login = '/api/v1/auth/login';
$tenantH = ['X-Tenant-Domain' => $domain];

$r1 = hit($kernel, 'POST', $login, ['email' => $email, 'password' => $pass], $tenantH);
$token = $r1[1]['data']['access_token'] ?? ($r1[1]['access_token'] ?? null);
show("1) دخول العميل بإيميله + الهيدر (المتوقع: 200 وتوكن)", $r1, $r1[0] === 200 && $token);

$r2 = hit($kernel, 'POST', $login, ['email' => $email, 'password' => $pass]);
show("2) نفس الإيميل من غير هيدر = قاعدة UIPE (المتوقع: يفشل)", $r2, $r2[0] >= 400 && $r2[0] < 500);

$central = \Illuminate\Support\Facades\DB::table('users')->orderBy('id')->value('email');
$r3 = hit($kernel, 'POST', $login, ['email' => (string) $central, 'password' => 'x'], $tenantH);
show("3) إيميل من UIPE ($central) عند العميل (المتوقع: يفشل)", $r3, $r3[0] >= 400 && $r3[0] < 500);

$r4 = hit($kernel, 'POST', $login, [], ['X-Tenant-Domain' => 'nobody.example.com']);
show("4) دومين مجهول (المتوقع: 404)", $r4, $r4[0] === 404);

if ($token) {
    $auth = ['Authorization' => 'Bearer ' . $token];
    $r5 = hit($kernel, 'GET', '/api/v1/session/heartbeat', [], $auth + $tenantH);
    show("5) توكن العميل عند العميل (المتوقع: مش 401)", $r5, $r5[0] !== 401);
    $r6 = hit($kernel, 'GET', '/api/v1/session/heartbeat', [], $auth);
    show("6) نفس التوكن على منصة UIPE (المتوقع: 401)", $r6, $r6[0] === 401);
} else {
    echo "⚠️ مفيش توكن من الخطوة 1، فمش هقدر أختبر 5 و6. شوف رد الخطوة 1 فوق.\n";
}
