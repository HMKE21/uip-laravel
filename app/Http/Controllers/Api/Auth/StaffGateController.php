<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use App\Support\StaffGate;
use Illuminate\Http\Request;

/**
 * POST /api/v1/auth/staff-gate { code } -> { gate_token, expires_in }
 * GET  /api/v1/auth/staff-gate           -> { required }   (lets the SPA skip the gate when it is switched off)
 *
 * The answer never says which group a code belongs to; a wrong code only says "wrong", and repeated
 * wrong codes lock the caller out (escalating). The ticket is what LoginController demands for portal=staff.
 */
class StaffGateController extends Controller
{
    public function status()
    {
        return $this->apiSuccess(['required' => StaffGate::enabled()], 'OK')->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $ip = (string) $request->ip();

        if (!StaffGate::enabled()) {
            return $this->apiSuccess(['gate_token' => null, 'expires_in' => 0], 'Gate disabled.');
        }

        if ($wait = StaffGate::retryAfter($ip)) {
            return response()->json([
                'success' => false,
                'message' => $ar ? 'محاولات كتيرة. حاول تاني بعد شوية.' : 'Too many attempts. Try again later.',
                'data'    => ['retry_after' => $wait],
                'errors'  => ['code' => 'staff_gate_locked'],
                'meta'    => (object) [],
            ], 429)->header('Retry-After', (string) $wait);
        }

        $code = trim((string) $request->input('code', ''));
        $group = ($code !== '' && strlen($code) <= 64) ? StaffGate::groupFor($code) : null;

        if ($group === null) {
            $left = StaffGate::registerFailure($ip);
            SecurityLog::write('Staff gate - wrong code', ['ip' => $ip, 'attempts_left' => $left]);

            return response()->json([
                'success' => false,
                'message' => $ar ? 'الكود غير صحيح.' : 'Incorrect code.',
                'data'    => ['attempts_left' => $left, 'retry_after' => $left === 0 ? StaffGate::retryAfter($ip) : 0],
                'errors'  => ['code' => 'staff_gate_invalid'],
                'meta'    => (object) [],
            ], 422);
        }

        StaffGate::clearFailures($ip);
        SecurityLog::write('Staff gate - passed', ['ip' => $ip, 'group' => $group]);

        return $this->apiSuccess([
            'gate_token' => StaffGate::issue($group),
            'expires_in' => (int) config('staff_gate.ttl', 1800),
        ], 'OK')->header('Cache-Control', 'no-store');
    }
}
