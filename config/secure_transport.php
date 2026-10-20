<?php

/**
 * Application-layer response encryption (on top of TLS).
 * RESPONSE_ENC_ENFORCE=true  -> API JSON requests without a valid session key are refused (428).
 * RESPONSE_ENC_ENFORCE=false -> clients that send X-Enc-Kid get encrypted responses, others plain.
 */
return [
    'enabled' => (bool) env('RESPONSE_ENC_ENABLED', true),
    'enforce' => (bool) env('RESPONSE_ENC_ENFORCE', false),
    // true -> JSON request bodies must arrive encrypted (uploads/multipart are exempt).
    'enforce_requests' => (bool) env('REQUEST_ENC_ENFORCE', false),
    'key_ttl' => (int) env('RESPONSE_ENC_KEY_TTL', 7200), // seconds a negotiated key lives
    // Paths (relative to the app root) never encrypted / never demanding a key.
    'except'  => ['api/v1/secure/handshake', 'api/v1/secure/selftest', 'api/v1/app-config', 'api/v1/github/callback'],
];
