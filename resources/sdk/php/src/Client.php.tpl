<?php

declare(strict_types=1);

namespace Uipe;

/**
 * Client for the UIPE Developer API (PHP 8.0+, ext-curl, ext-json).
 *
 *   $client = new \Uipe\Client('uipk_test_...');
 *   $client->listMessages(['folder' => 'inbox']);
 *
 * Every operation returns the `data` of the response and throws ApiException on failure.
 * 429 answers (and 502/503/504 for idempotent calls) are retried after the wait the server asks for.
 */
class Client
{
    use Operations;

    public const DEFAULT_BASE_URL = '{{BASE_URL}}';
    public const VERSION = '{{SDK_VERSION}}';

    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE'];
    private const MAX_WAIT = 30.0;

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private string $userAgent;
    /** @var callable(string,string,array<int,string>,?string,float):array{0:int,1:array<string,string>,2:string} */
    private $transport;
    /** @var callable(float):void */
    private $sleeper;
    private ?Response $lastResponse = null;

    /**
     * @param array{base_url?:string,timeout?:float,max_retries?:int,user_agent?:string,transport?:callable,sleep?:callable} $options
     *        `transport` (method, url, header lines, body, timeout) => [status, lowercase headers, raw body] and
     *        `sleep` (seconds) exist so you can test without a network.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->userAgent = (string) ($options['user_agent'] ?? ('uipe-php/' . self::VERSION));
        $this->transport = $options['transport'] ?? [$this, 'curl'];
        $this->sleeper = $options['sleep'] ?? static function (float $s): void {
            usleep((int) ($s * 1000000));
        };
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    // -- helpers used by the generated operations ---------------------------------

    /** @param array<string,mixed> $values */
    protected function expand(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{' . $key . '}', rawurlencode((string) $value), $template);
        }
        return $template;
    }

    /** @param array<string,mixed> $query */
    protected function url(string $path, array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $v) {
                if (is_bool($v)) {
                    $v = $v ? '1' : '0';
                }
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
            }
        }
        return $this->baseUrl . $path . ($pairs ? '?' . implode('&', $pairs) : '');
    }

    // -- requests -----------------------------------------------------------------

    /**
     * Send a request and return the `data` of the response.
     * @param array<string,mixed>|null $query
     * @param array<mixed>|null $body
     * @return mixed
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null)
    {
        return $this->requestRaw($method, $path, $query, $body)->data;
    }

    /**
     * Send a request and return a Response (status, headers, data, meta, message).
     * @param array<string,mixed>|null $query
     * @param array<mixed>|null $body
     */
    public function requestRaw(string $method, string $path, ?array $query = null, ?array $body = null): Response
    {
        $method = strtoupper($method);
        $url = $this->url($path, $query ?? []);
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json', 'User-Agent: ' . $this->userAgent];
        $payload = null;
        if ($body !== null) {
            $payload = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        $attempt = 0;
        while (true) {
            try {
                [$status, $respHeaders, $raw] = ($this->transport)($method, $url, $headers, $payload, $this->timeout);
            } catch (NetworkException $e) {
                if (in_array($method, self::IDEMPOTENT, true) && $attempt < $this->maxRetries) {
                    $this->pause($attempt, null);
                    $attempt++;
                    continue;
                }
                throw $e;
            }
            $retryable = $status === 429 || (in_array($status, [502, 503, 504], true) && in_array($method, self::IDEMPOTENT, true));
            if ($retryable && $attempt < $this->maxRetries) {
                $this->pause($attempt, $respHeaders['retry-after'] ?? null);
                $attempt++;
                continue;
            }
            return $this->parse($status, $respHeaders, $raw);
        }
    }

    private function pause(int $attempt, ?string $retryAfter): void
    {
        $delay = null;
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $delay = min((float) $retryAfter, self::MAX_WAIT);
        }
        if ($delay === null) {
            $delay = min(0.5 * (2 ** $attempt), 8.0) + mt_rand(0, 250) / 1000;
        }
        ($this->sleeper)(max(0.0, $delay));
    }

    /** @param array<string,string> $headers */
    private function parse(int $status, array $headers, string $raw): Response
    {
        $payload = null;
        if ($raw !== '') {
            $payload = json_decode($raw, true);
        }
        $envelope = is_array($payload) ? $payload : [];
        if ($status >= 200 && $status < 300) {
            if ($raw !== '' && $payload === null) {
                throw new ApiException($status, 'The server did not return JSON.', 'invalid_response', null, $headers, $raw);
            }
            return $this->lastResponse = new Response($status, $headers, $envelope['data'] ?? null, $envelope['meta'] ?? null, isset($envelope['message']) ? (string) $envelope['message'] : null);
        }
        $errors = $envelope['errors'] ?? null;
        $code = is_array($errors) && isset($errors['code']) && is_string($errors['code']) ? $errors['code'] : null;
        $message = isset($envelope['message']) && is_string($envelope['message']) && $envelope['message'] !== '' ? $envelope['message'] : 'HTTP ' . $status;
        throw new ApiException($status, $message, $code, $errors, $headers, $raw);
    }

    /**
     * Default transport (curl).
     * @param array<int,string> $headers
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function curl(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new NetworkException(curl_error($ch) ?: 'Request failed');
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $respHeaders, (string) $raw];
    }
}
