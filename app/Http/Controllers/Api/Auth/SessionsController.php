<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * يطابق SessionsController القديم — لكن بملحوظة مهمة:
 * القديم بيعتمد على session_id() (PHP session cookie) عشان يحدد "is_current".
 * إحنا هنا Stateless (JWT بس، بدون session)، فمفهوم "الجهاز الحالي" مختلف
 * شكليًا — access token مالوش session_id مرتبط بيه بنفس الطريقة.
 * TODO: لو محتاج "is_current" فعليًا، سجّل session identifier مستقل وقت
 * إصدار كل access token واربطه هنا.
 */
class SessionsController extends Controller
{
    /** GET /api/v1/auth/sessions */
    public function index(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $currentSid = (int) $request->attributes->get('uip_session_id');

        $rows = DB::table('user_sessions')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->orderByDesc('last_activity_at')
            ->get();

        $data = $rows->map(function ($row) use ($currentSid) {
            return [
                'id'               => (int) $row->id,
                'device_label'     => $row->device_label,
                'location_label'   => $row->location_label,
                'ip_address'       => $row->ip_address,
                'is_current'       => $currentSid > 0 && (int) $row->id === $currentSid,
                'last_activity_at' => $row->last_activity_at,
                'created_at'       => $row->created_at,
            ];
        });

        return $this->apiSuccess($data, 'Active sessions retrieved successfully.', 200, ['total' => $data->count()]);
    }

    /** DELETE /api/v1/auth/sessions/{id} */
    public function revoke(Request $request, int $id)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        $owned = DB::table('user_sessions')->where('id', $id)->where('user_id', $userId)->where('is_active', 1)->exists();
        if (!$owned) {
            return $this->apiError('Session not found.', null, 404);
        }
        app(\App\Services\UserSessionService::class)->end($id, 'revoked_by_user', $userId);

        return $this->apiSuccess(null, 'Session revoked successfully.');
    }

    /** GET /api/v1/session/heartbeat — الفرونت بيناديها كل ~20 ثانية طول ما المتصفح مفتوح. */
    public function heartbeat(Request $request)
    {
        $sid = (int) $request->attributes->get('uip_session_id');
        if ($sid > 0) {
            app(\App\Services\UserSessionService::class)->heartbeat($sid);
        }
        return response()->json(['success' => true, 'data' => null, 'message' => 'ok', 'errors' => null, 'meta' => (object) []])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /api/v1/session/release — آخر تبويب اتقفل: الجلسة مبقتش "حية" فجهاز تاني يقدر يدخل. */
    public function release(Request $request)
    {
        $sid = (int) $request->attributes->get('uip_session_id');
        if ($sid > 0) {
            app(\App\Services\UserSessionService::class)->release($sid);
        }
        return response()->json(['success' => true, 'data' => null, 'message' => 'ok', 'errors' => null, 'meta' => (object) []]);
    }
}
