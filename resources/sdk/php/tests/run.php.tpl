<?php

declare(strict_types=1);

// No dependencies: php tests/run.php  (exit code 1 on failure)

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Uipe\\') === 0) {
        $file = __DIR__ . '/../src/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Uipe\ApiException;
use Uipe\Client;
use Uipe\NetworkException;
use Uipe\ReplayGuard;
use Uipe\SignatureException;
use Uipe\Webhooks;

// Fixed vector published by the API at /docs/webhook-verification. Every UIPE SDK must accept it.
const SECRET = {{V_SECRET}};
const TIMESTAMP = {{V_TIMESTAMP}};
const BODY = {{V_BODY}};
const SIGNATURE = {{V_SIGNATURE}};
const HEADER = {{V_HEADER}};

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FAIL  $name\n";
    }
}
function reason(callable $fn): ?string
{
    try {
        $fn();
    } catch (SignatureException $e) {
        return $e->reason;
    }
    return null;
}

// ---- webhooks ----------------------------------------------------------------
check('vector signature', Webhooks::sign(SECRET, TIMESTAMP, BODY) === SIGNATURE);
check('vector header', Webhooks::buildHeader(SECRET, BODY, TIMESTAMP) === HEADER);
check('fresh delivery', Webhooks::verifySignature(BODY, HEADER, SECRET, null, TIMESTAMP + 10));
check('wrong secret', Webhooks::inspect(BODY, HEADER, 'whsec_other', null, TIMESTAMP)['reason'] === 'signature_mismatch');
check('tampered body', Webhooks::inspect(BODY . ' ', HEADER, SECRET, null, TIMESTAMP)['reason'] === 'signature_mismatch');
check('window upper edge', Webhooks::verifySignature(BODY, HEADER, SECRET, null, TIMESTAMP + 300));
check('too old', Webhooks::inspect(BODY, HEADER, SECRET, null, TIMESTAMP + 301)['reason'] === 'timestamp_too_old');
check('window lower edge', Webhooks::verifySignature(BODY, HEADER, SECRET, null, TIMESTAMP - 300));
check('in the future', Webhooks::inspect(BODY, HEADER, SECRET, null, TIMESTAMP - 301)['reason'] === 'timestamp_in_future');
check('custom tolerance', !Webhooks::verifySignature(BODY, HEADER, SECRET, 60, TIMESTAMP + 61) && Webhooks::verifySignature(BODY, HEADER, SECRET, 60, TIMESTAMP + 60));
check('missing header', Webhooks::inspect(BODY, null, SECRET)['reason'] === 'missing_header' && Webhooks::inspect(BODY, '  ', SECRET)['reason'] === 'missing_header');
foreach (['garbage', 't=abc,v1=' . SIGNATURE, 't=1', 'v1=' . SIGNATURE, 't=1,v1=zz', 't=1,t=2,v1=' . SIGNATURE] as $bad) {
    check('malformed: ' . $bad, Webhooks::inspect(BODY, $bad, SECRET)['reason'] === 'malformed_header');
}
check('missing secret', Webhooks::inspect(BODY, HEADER, [], null, TIMESTAMP)['reason'] === 'missing_secret');
$old = Webhooks::buildHeader('whsec_old', BODY, TIMESTAMP);
check('rotation', Webhooks::verifySignature(BODY, $old, ['whsec_new', 'whsec_old'], null, TIMESTAMP) && !Webhooks::verifySignature(BODY, $old, ['whsec_new'], null, TIMESTAMP));
$other = Webhooks::sign('whsec_other', TIMESTAMP, BODY);
check('several v1', Webhooks::verifySignature(BODY, 't=' . TIMESTAMP . ',v1=' . $other . ',v1=' . SIGNATURE, SECRET, null, TIMESTAMP));
check('unknown keys ignored', Webhooks::verifySignature(BODY, 'v0=whatever,' . HEADER, SECRET, null, TIMESTAMP));

$guard = new ReplayGuard();
$event = Webhooks::constructEvent(BODY, HEADER, SECRET, null, $guard, TIMESTAMP);
check('constructEvent returns the event', is_array($event) && $event['id'] === 'evt_test');
check('replayed event rejected', reason(fn () => Webhooks::constructEvent(BODY, HEADER, SECRET, null, $guard, TIMESTAMP)) === 'replayed_event');
check('constructEvent bad signature', reason(fn () => Webhooks::constructEvent(BODY, HEADER, 'whsec_other', null, null, TIMESTAMP)) === 'signature_mismatch');
check('non JSON body', reason(fn () => Webhooks::constructEvent('not json', Webhooks::buildHeader(SECRET, 'not json', TIMESTAMP), SECRET, null, null, TIMESTAMP)) === 'invalid_payload');
$g = new ReplayGuard(10);
check('guard ttl', $g->firstSeen('evt_1', 100.0) && !$g->firstSeen('evt_1', 105.0) && $g->firstSeen('evt_1', 111.0));
$small = new ReplayGuard(1000, 3);
for ($i = 0; $i < 10; $i++) {
    $small->firstSeen('evt_' . $i, 100.0);
}
check('guard is bounded', $small->size() <= 3);

// ---- client (fake transport, no network) -------------------------------------
$calls = [];
$script = [];
$sleeps = [];
$client = new Client('uipk_test_abc', [
    'base_url' => 'https://api.test/api/dev/v1',
    'max_retries' => 2,
    'sleep' => function (float $s) use (&$sleeps): void {
        $sleeps[] = $s;
    },
    'transport' => function (string $method, string $url, array $headers, ?string $body, float $timeout) use (&$calls, &$script): array {
        $calls[] = [$method, $url, $headers, $body];
        $next = array_shift($script);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    },
]);

$script = [[200, ['x-ratelimit-remaining' => '41'], json_encode(['success' => true, 'data' => ['id' => 7]])]];
$data = $client->getMessage('a b');
check('returns data', $data === ['id' => 7]);
check('path is encoded', $calls[0][0] === 'GET' && $calls[0][1] === 'https://api.test/api/dev/v1/mail/messages/a%20b');
check('bearer header', in_array('Authorization: Bearer uipk_test_abc', $calls[0][2], true));
check('rate limit header', $client->lastResponse()->rateLimit()['remaining'] === 41);

$calls = [];
$script = [[200, [], json_encode(['data' => []])]];
$client->listMessages(['folder' => 'inbox', 'unread' => true, 'skip' => null]);
check('query encoded', strpos($calls[0][1], 'folder=inbox') !== false && strpos($calls[0][1], 'unread=1') !== false && strpos($calls[0][1], 'skip') === false);

$calls = [];
$sleeps = [];
$script = [
    [429, ['retry-after' => '0'], json_encode(['success' => false, 'message' => 'slow down', 'errors' => ['code' => 'rate_limited']])],
    [200, [], json_encode(['data' => ['ok' => true]])],
];
check('429 retried', $client->getMessage(1) === ['ok' => true] && count($calls) === 2 && $sleeps === [0.0]);

$calls = [];
$script = [[422, [], json_encode(['success' => false, 'message' => 'Not in sandbox', 'errors' => ['code' => 'sandbox_unsupported']])]];
try {
    $client->getMessage(1);
    check('error thrown', false);
} catch (ApiException $e) {
    check('error carries status, code and message', $e->status === 422 && $e->errorCode === 'sandbox_unsupported' && $e->getMessage() === 'Not in sandbox');
}

$calls = [];
$script = [[503, [], json_encode(['success' => false, 'message' => 'down'])]];
try {
    $client->sendMessage(['subject' => 'hi']);
    check('POST not retried', false);
} catch (ApiException $e) {
    check('POST not retried on 503', count($calls) === 1 && $calls[0][0] === 'POST' && json_decode((string) $calls[0][3], true) === ['subject' => 'hi']);
}

$calls = [];
$script = [new NetworkException('boom'), [200, [], json_encode(['data' => 1])]];
check('network error retried for GET', $client->getMe() === 1 && count($calls) === 2);

try {
    new Client('');
    check('api key required', false);
} catch (InvalidArgumentException $e) {
    check('api key required', true);
}

echo $failures === 0 ? "OK ($count checks)\n" : "$failures of $count checks failed\n";
exit($failures === 0 ? 0 : 1);
