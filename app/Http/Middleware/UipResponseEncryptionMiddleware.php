<?php

namespace App\Http\Middleware;

use App\Services\ResponseCryptoService;
use App\Support\PlatformPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Encrypts every JSON API response body for clients that negotiated a key
 * (header X-Enc-Kid, see SecureHandshakeController). Files, streams and empty
 * bodies are left alone.
 *
 * RESPONSE_ENC_ENFORCE=true: a write request (POST/PUT/PATCH/DELETE) without a valid key is refused
 * before any controller runs; a GET/HEAD without a key still runs, but if its answer is JSON it is
 * withheld (428) — so images, downloads and other non-fetch loads keep working.
 */
class UipResponseEncryptionMiddleware
{
    public function __construct(private ResponseCryptoService $crypto)
    {
    }

    private function reject(string $code, int $status = 428, string $reason = '')
    {
        return response()->json([
            'success' => false,
            'message' => $code === 'enc_bad_request' ? 'Invalid or expired secure request.' : ('Secure channel required.' . ($reason !== '' ? " ({$code}: {$reason})" : '')),
            'data'    => null,
            'errors'  => ['code' => $code] + ($reason !== '' ? ['reason' => $reason] : []),
            'meta'    => (object) [],
        ], $status)->header('Cache-Control', 'no-store');
    }

    private function isFileLike($response): bool
    {
        return $response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || in_array($response->getStatusCode(), [204, 205, 304], true)
            || !str_contains((string) $response->headers->get('Content-Type', ''), 'json');
    }

    /** Encryption disabled by the admin: never encrypt, never demand a key, but understand leftover encrypted bodies. */
    private function handlePlain(Request $request, Closure $next, string $kid, ?array $keys)
    {
        $isRead = in_array($request->method(), ['GET', 'HEAD'], true);
        if (!$isRead && $request->isJson()) {
            $raw = (string) $request->getContent();
            $env = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($env) && ($env['enc'] ?? null) === 'v1' && isset($env['d'])) {
                if ($kid === '' || ($env['kid'] ?? '') !== $kid || $keys === null) {
                    return $this->reject('enc_key_expired');
                }
                $body = $this->crypto->decryptRequest((string) $env['d'], $keys['req'], $kid, $request->method(), '/' . ltrim($request->path(), '/'));
                if ($body === null) {
                    return $this->reject('enc_bad_request', 400);
                }
                $request->setJson(new InputBag($body));
                $request->attributes->set('uip_enc_request', true);
            }
        }

        $response = $next($request);
        $response->headers->set('X-Enc', '0'); // tells the SPA the channel is plain right now

        return $response;
    }

    public function handle(Request $request, Closure $next)
    {
        if (!config('secure_transport.enabled') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }
        foreach ((array) config('secure_transport.except', []) as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        $kid  = (string) $request->header('X-Enc-Kid', '');
        $keys = $kid !== '' ? $this->crypto->keysFor($kid) : null;
        $why  = ($kid !== '' && $keys === null) ? 'not_in_cache' : '';   // diagnostics: shown in the 428 so a screenshot says which check failed
        // A key id presented by a different client / account than the one that negotiated it is treated as expired (client re-handshakes).
        if ($keys !== null && !$this->crypto->bindClient($kid, ResponseCryptoService::clientFingerprint($request), (string) $request->header('Authorization', ''))) {
            $why  = $this->crypto->lastFailure ?: 'bind';
            $keys = null;
        }

        // Admin → Platform Policy switched the envelope OFF: answers are plain JSON, enforcement is lifted.
        // A tab that still holds an old key may keep sending encrypted bodies for a while, so those are still
        // decrypted (or answered 428 so the client re-handshakes, learns encryption is off and sends plain).
        if (!PlatformPolicy::encryptionOn()) {
            return $this->handlePlain($request, $next, $kid, $keys);
        }

        $key  = $keys['resp'] ?? null;
        $enforce = PlatformPolicy::responseEncEnforced();
        $isRead  = in_array($request->method(), ['GET', 'HEAD'], true);

        if ($key === null) {
            if ($kid !== '') {
                \Illuminate\Support\Facades\Log::notice('Secure channel: key refused', ['reason' => $why, 'kid' => substr($kid, 0, 8), 'path' => $request->path(), 'client' => (string) $request->header('X-UIP-Client', '')]);

                return $this->reject('enc_key_expired', 428, $why); // client re-handshakes and retries
            }
            if (!$enforce) {
                return $next($request);
            }
            if (!$isRead) {
                return $this->reject('enc_required');
            }
            // Keyless read under enforcement: let it run, but never hand out a plain JSON body.
            $response = $next($request);

            return $this->isFileLike($response) ? $response : $this->reject('enc_required');
        }

        // Encrypted request body (JSON envelope) -> decrypt and expose it as the normal JSON input.
        if (!$isRead && $request->isJson()) {
            $raw = (string) $request->getContent();
            $env = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($env) && ($env['enc'] ?? null) === 'v1' && isset($env['d']) && ($env['kid'] ?? '') === $kid) {
                $body = $this->crypto->decryptRequest((string) $env['d'], $keys['req'], $kid, $request->method(), '/' . ltrim($request->path(), '/'));
                if ($body === null) {
                    return $this->reject('enc_bad_request', 400);
                }
                $request->setJson(new InputBag($body));
                $request->attributes->set('uip_enc_request', true);
            } elseif ($raw !== '' && PlatformPolicy::requestEncEnforced()) {
                return $this->reject('enc_required');
            }
        }

        $response = $next($request);

        if ($this->isFileLike($response)) {
            return $response;
        }

        $status   = $response->getStatusCode();
        $envelope = json_encode(['enc' => 'v1', 'kid' => $kid, 'd' => $this->crypto->encrypt((string) $response->getContent(), $key, $kid, $status)]);

        $response->setContent($envelope);
        $response->headers->set('X-Enc', '1');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');

        return $response;
    }
}
