# UIPE Developer API — PHP SDK

Generated from the OpenAPI spec of **{{API_TITLE}} {{API_VERSION}}** (`php artisan developer:sdk`). Do not edit by hand. PHP 8.0+, `ext-curl`, no other dependencies.

```php
$client = new \Uipe\Client('uipk_test_...');       // an API key or an OAuth access token
$me = $client->getMe();
$messages = $client->listMessages(['folder' => 'inbox']);
```

Path parameters are positional (`$client->getMessage($id)`), then the body (when the operation has one), then the query array. Every operation returns the `data` of the response and throws `\Uipe\ApiException` (`status`, `errorCode`, `getMessage()`, `retryAfter()`) on failure. `429` answers (and `502/503/504` for GET/PUT/DELETE) are retried after the wait the server asks for. `$client->requestRaw(...)` and `$client->lastResponse()` expose headers (`rateLimit()`, `mode()`).

## Verify a webhook

```php
use Uipe\ReplayGuard;
use Uipe\SignatureException;
use Uipe\Webhooks;

$raw = file_get_contents('php://input');                       // the raw body, before json_decode
try {
    $event = Webhooks::constructEvent($raw, $_SERVER['HTTP_X_UIPE_SIGNATURE'] ?? null, getenv('UIPE_WEBHOOK_SECRET'), null, $guard);
} catch (SignatureException $e) {
    http_response_code(400);
    exit($e->reason);            // signature_mismatch, timestamp_too_old, replayed_event ...
}
http_response_code(200);         // answer first, work after
```

`$guard` is a `ReplayGuard`. php-fpm workers do not share memory, so keep event ids in Redis or a unique DB index and pass your own object with `firstSeen(string $eventId): bool`. The window is {{TOLERANCE}} seconds in both directions. Pass an array of secrets while rotating a webhook secret.

## Operations

{{OPERATIONS_TABLE}}

## Tests

```
php tests/run.php
```
