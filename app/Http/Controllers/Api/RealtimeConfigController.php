<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * GET /api/v1/realtime/config — PUBLIC connection info for the Reverb WebSocket server.
 *
 * Why this exists: the browser used to read VITE_REVERB_* at BUILD time. When those were missing on Vercel the
 * client silently fell back to `wss://<frontend-host>:8080` (a Vercel domain has no WebSocket server) and every
 * meeting / live update failed. Now the API (which already has the right REVERB_* values) tells the browser where
 * Reverb really is, so no frontend rebuild is needed when the Reverb domain changes.
 *
 * Only the PUBLIC key/host/port/scheme are returned — never REVERB_APP_SECRET.
 */
class RealtimeConfigController extends Controller
{
    public function show()
    {
        $opts = (array) config('broadcasting.connections.reverb.options', []);
        $host = trim((string) ($opts['host'] ?? ''));
        $host = preg_replace('#^https?://#i', '', $host);
        $host = rtrim((string) preg_replace('#[/:].*$#', '', $host));
        $scheme = strtolower((string) ($opts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
        $port = (int) ($opts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $key = (string) config('broadcasting.connections.reverb.key');

        return response()->json([
            'enabled' => $key !== '' && $host !== '',
            'key'     => $key,
            'host'    => $host,
            'port'    => $port ?: ($scheme === 'https' ? 443 : 80),
            'scheme'  => $scheme,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
