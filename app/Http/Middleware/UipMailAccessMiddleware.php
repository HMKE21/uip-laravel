<?php

namespace App\Http\Middleware;

use App\Services\Mail\MailAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * UIPE Mail — إدارة الوصول: بيمنع الدخول للبريد (/api/v1/mail/*) لو دور المستخدم أو استثناؤه "ممنوع من استخدام البريد".
 * مش متركّب على /v1/mail-admin عمدًا (الأدمن لازم يفضل قادر يدخل ويصلّح القواعد). لازم يتحط بعد uip.auth.
 */
class UipMailAccessMiddleware
{
    public function __construct(private MailAccessService $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        if ($request->isMethod('OPTIONS') || !$userId) {
            return $next($request);
        }

        $accountId = (int) ($request->query('account_id') ?: $request->header('X-Mail-Account'));
        $q = DB::table('mail_accounts')->where('user_id', $userId);
        $acc = $accountId
            ? (clone $q)->where('id', $accountId)->first(['university_id', 'tenant_id'])
            : (clone $q)->orderByDesc('is_default')->orderBy('id')->first(['university_id', 'tenant_id']);
        $uni = $acc && $acc->tenant_id && $acc->university_id ? (int) $acc->university_id : null;

        $reason = $this->access->useBlockReason($userId, $uni);
        if ($reason === null) {
            return $next($request);
        }

        return response()->json([
            'success' => false, 'message' => $reason, 'data' => ['mail_access_denied' => true],
            'errors' => ['code' => 'mail_access_denied'], 'meta' => (object) [],
        ], 403)->header('Cache-Control', 'no-store');
    }
}
