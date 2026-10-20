<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ResponseCryptoService;
use App\Support\PlatformPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** POST /api/v1/secure/handshake { pub } -> { kid, server_pub, salt }  (ephemeral ECDH P-256). */
class SecureHandshakeController extends Controller
{
    public function submit(Request $request, ResponseCryptoService $crypto)
    {
        // Admin switched response encryption off: tell the client plainly, hand out no key.
        if (!PlatformPolicy::encryptionOn()) {
            return $this->apiSuccess(['enabled' => false], 'Secure channel disabled.')->header('Cache-Control', 'no-store');
        }

        $pub = (string) $request->input('pub', '');
        if ($pub === '' || strlen($pub) > 200) {
            return $this->apiError('Invalid key.', null, 422);
        }
        try {
            $res = $crypto->handshake($pub);
        } catch (\Throwable $e) {
            Log::error('Secure handshake failed: ' . $e->getMessage());
            return $this->apiError('Secure channel unavailable.', ['code' => 'handshake_error'], 500);
        }
        if (!$res) {
            Log::warning('Secure handshake rejected: ' . (openssl_error_string() ?: 'invalid client key or server OpenSSL EC support'));
            return $this->apiError('Invalid key.', null, 422);
        }

        return $this->apiSuccess($res, 'Secure channel ready.')->header('Cache-Control', 'no-store');
    }

    /** GET /api/v1/secure/selftest — booleans only; tells the operator what is missing on the server. */
    public function selftest(ResponseCryptoService $crypto)
    {
        return $this->apiSuccess($crypto->selfTest() + ['enabled' => PlatformPolicy::encryptionOn()], 'Self-test finished.')->header('Cache-Control', 'no-store');
    }
}
