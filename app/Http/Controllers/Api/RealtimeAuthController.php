<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * POST /api/v1/realtime/auth — the Pusher-protocol handshake for the person's
 * own private channel (private-App.Models.User.{id}). Echo posts socket_id +
 * channel_name; we answer with the signature only if the channel is theirs.
 * Same signing as MeetingSignalingService::signChannel (HMAC-SHA256 with the Reverb secret).
 */
class RealtimeAuthController extends Controller
{
    public function auth(Request $request)
    {
        $uid = (int) $request->attributes->get('uip_user_id');
        $socketId = (string) $request->input('socket_id', '');
        $channel = (string) $request->input('channel_name', '');

        if (!$uid || !preg_match('/^\d+\.\d+$/', $socketId) || $channel !== 'private-App.Models.User.' . $uid) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');
        $signature = hash_hmac('sha256', $socketId . ':' . $channel, $secret);

        return response()->json(['auth' => $key . ':' . $signature]);
    }
}
