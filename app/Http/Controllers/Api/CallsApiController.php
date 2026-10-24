<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CallService;
use Illuminate\Http\Request;

/**
 * /api/v1/messaging/calls/* — مكالمات 1:1 (صوت أولًا). المستخدم الفاعل دايمًا من uip_user_id (uip.auth)،
 * وصلاحية messaging.use متطبقة على المجموعة كلها في routes/api.php. كل منطق الحالة/الخصوصية في CallService.
 *
 *  GET  /calls/ice            iceServers + flags (video_enabled…)
 *  GET  /calls/poll           ?client_id=&call_id=&after=  → { incoming[], call, signals[] }   (بيتنادى كل ~1-4 ثواني)
 *  POST /calls                { conversation_id, type?, client_id }
 *  POST /calls/{id}/accept    { client_id }
 *  POST /calls/{id}/resume    { client_id }   → rebinds an ACTIVE call to this page after a refresh; returns the call + after_signal_id
 *  POST /calls/{id}/decline
 *  POST /calls/{id}/end       { reason? }
 *  POST /calls/{id}/signal    { client_id, kind: offer|answer|ice|state|resume, payload }
 */
class CallsApiController extends Controller
{
    public function __construct(private CallService $calls)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    public function ice(Request $request)
    {
        return $this->handle(fn () => $this->apiSuccess($this->calls->iceConfig($this->uid($request)), 'ICE configuration retrieved.'));
    }

    public function poll(Request $request)
    {
        return $this->handle(function () use ($request) {
            $callId = $request->query('call_id');
            $data = $this->calls->poll(
                $this->uid($request),
                (string) $request->query('client_id', ''),
                $callId !== null && $callId !== '' ? (int) $callId : null,
                max(0, (int) $request->query('after', 0))
            );
            return $this->apiSuccess($data, 'Calls polled.');
        });
    }

    public function start(Request $request)
    {
        return $this->handle(function () use ($request) {
            $call = $this->calls->start(
                $this->uid($request),
                (int) $request->input('conversation_id'),
                (string) $request->input('type', 'audio'),
                (string) $request->input('client_id', '')
            );
            return $this->apiSuccess($call, 'Call started.', 201);
        });
    }

    public function accept(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->calls->accept($this->uid($request), (int) $id, (string) $request->input('client_id', '')), 'Call accepted.'));
    }

    public function resume(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->calls->resume($this->uid($request), (int) $id, (string) $request->input('client_id', '')), 'Call resumed.'));
    }

    public function decline(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->calls->decline($this->uid($request), (int) $id), 'Call declined.'));
    }

    public function end(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->calls->end($this->uid($request), (int) $id, (string) $request->input('reason', 'hangup')), 'Call ended.'));
    }

    public function signal(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $r = $this->calls->signal(
                $this->uid($request),
                (int) $id,
                (string) $request->input('client_id', ''),
                (string) $request->input('kind', ''),
                $request->input('payload')
            );
            return $this->apiSuccess($r, 'Signal sent.', 201);
        });
    }

    private function handle(\Closure $action)
    {
        try {
            return $action();
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error('Calls DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', ['db' => [(string) ($e->errorInfo[2] ?? 'database error')]], 500);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }
}
