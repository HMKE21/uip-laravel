<?php

namespace App\Console\Commands;

use App\Services\ResponseCryptoService;
use Illuminate\Console\Command;

/**
 * Operator-only debugging: decrypts a captured envelope using the live session key in the server cache.
 *   php artisan enc:decrypt <kid> <d> --dir=resp --status=200
 *   php artisan enc:decrypt <kid> <d> --dir=req --method=POST --path=/api/v1/auth/login
 * Works only while the key is still cached (RESPONSE_ENC_KEY_TTL) and only with server access.
 */
class DecryptSecurePayload extends Command
{
    protected $signature = 'enc:decrypt {kid} {d} {--dir=resp : resp|req} {--status=200} {--method=POST} {--path=}';
    protected $description = 'Decrypt a captured API envelope with the cached session key (debugging).';

    public function handle(ResponseCryptoService $crypto): int
    {
        $keys = $crypto->keysFor((string) $this->argument('kid'));
        if (!$keys) {
            $this->error('Key not found (expired or wrong kid).');
            return self::FAILURE;
        }
        $kid = (string) $this->argument('kid');
        $raw = ResponseCryptoService::b64uDecode((string) $this->argument('d'));
        $iv = substr($raw, 0, 12); $tag = substr($raw, -16); $ct = substr($raw, 12, -16);

        if ($this->option('dir') === 'req') {
            $aad = "uip-req|$kid|" . strtoupper((string) $this->option('method')) . '|' . $this->option('path');
            $key = $keys['req'];
        } else {
            $aad = "uip-resp|$kid|" . (int) $this->option('status');
            $key = $keys['resp'];
        }
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            $this->error('Decryption failed (wrong aad/status/method/path or tampered data).');
            return self::FAILURE;
        }
        $this->line($plain);
        return self::SUCCESS;
    }
}
