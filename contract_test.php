<?php
/**
 * سكريبت مقارنة بسيط: يبعت نفس الـ request للـ API القديم واللارافيل الجديد
 * ويقارن الـ response JSON. شغّله بـ: php contract_test.php
 *
 * عدّل الـ URLs والبيانات التجريبية تحت حسب بيئتك.
 */

$OLD_BASE = 'http://localhost/UIP-local/public/api/v1';
$NEW_BASE = 'http://uip-laravel.test/api/v1';

function callApi(string $base, string $path, string $method = 'POST', array $body = []): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode($body, true)];
}

function compareStructure($a, $b, string $path = ''): array
{
    $diffs = [];
    if (gettype($a) !== gettype($b)) {
        $diffs[] = "$path: type mismatch (" . gettype($a) . " vs " . gettype($b) . ")";
        return $diffs;
    }
    if (is_array($a)) {
        $keysA = array_keys($a);
        $keysB = array_keys($b);
        foreach (array_diff($keysA, $keysB) as $missing) {
            $diffs[] = "$path.$missing: موجود في القديم مش موجود في الجديد";
        }
        foreach (array_diff($keysB, $keysA) as $extra) {
            $diffs[] = "$path.$extra: موجود في الجديد مش موجود في القديم";
        }
        foreach ($keysA as $k) {
            if (in_array($k, $keysB, true)) {
                $diffs = array_merge($diffs, compareStructure($a[$k], $b[$k], "$path.$k"));
            }
        }
    }
    return $diffs;
}

$cases = [
    ['name' => 'login - invalid creds', 'path' => '/auth/login', 'method' => 'POST', 'body' => ['email' => 'nouser@example.com', 'password' => 'wrong']],
    ['name' => 'refresh-token - invalid', 'path' => '/auth/refresh-token', 'method' => 'POST', 'body' => ['refresh_token' => 'not-a-real-token']],
    ['name' => 'register - invalid role', 'path' => '/auth/register', 'method' => 'POST', 'body' => [
        'full_name' => 'Test User', 'email' => 'newuser' . time() . '@example.com',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'admin',
    ]],
    ['name' => 'forgot-password - unknown email', 'path' => '/auth/forgot-password', 'method' => 'POST', 'body' => ['email' => 'unknown' . time() . '@example.com']],
    ['name' => 'reset-password - invalid token', 'path' => '/auth/reset-password', 'method' => 'POST', 'body' => [
        'token' => 'not-a-real-token', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
    ]],
    ['name' => 'verify-email - invalid token', 'path' => '/auth/verify-email?token=not-real', 'method' => 'GET', 'body' => []],
    ['name' => 'confirm-email-change - invalid token', 'path' => '/auth/confirm-email-change?token=not-real', 'method' => 'GET', 'body' => []],
    ['name' => 'roles - list', 'path' => '/auth/roles', 'method' => 'GET', 'body' => []],
    ['name' => 'universities - list', 'path' => '/auth/universities', 'method' => 'GET', 'body' => []],
    ['name' => 'sessions - no auth', 'path' => '/auth/sessions', 'method' => 'GET', 'body' => []],
];

foreach ($cases as $case) {
    $old = callApi($OLD_BASE, $case['path'], $case['method'], $case['body']);
    $new = callApi($NEW_BASE, $case['path'], $case['method'], $case['body']);

    echo "=== {$case['name']} ===\n";
    echo "status: old={$old['status']} new={$new['status']} " . ($old['status'] === $new['status'] ? '✓' : '✗ MISMATCH') . "\n";

    $diffs = compareStructure($old['json'], $new['json']);
    if (empty($diffs)) {
        echo "structure: ✓ متطابق\n";
    } else {
        echo "structure: ✗ اختلافات:\n";
        foreach ($diffs as $d) echo "  - $d\n";
    }
    echo "\n";
}
