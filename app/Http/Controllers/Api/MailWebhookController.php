<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailBounceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/mail/webhooks/brevo — أحداث Brevo (delivered / hard_bounce / soft_bounce / blocked / spam / unsubscribed...).
 * عام (من غير JWT) بس محمي بتوكن سري: UIPE_MAIL_WEBHOOK_SECRET، يتبعت في الهيدر X-Webhook-Token (أو Authorization: Bearer) أو ?token=.
 * لو التوكن مش متظبط في .env الـ endpoint بيرد 404 (مقفول). بيقبل حدث واحد أو مصفوفة أحداث (حد أقصى 200).
 */
class MailWebhookController extends Controller
{
    public function brevo(Request $r, MailBounceService $svc)
    {
        $secret = (string) config('mailbox.webhook_secret', '');
        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }
        $given = (string) ($r->header('X-Webhook-Token') ?: '');
        if ($given === '' && preg_match('/^Bearer\s+(.+)$/i', (string) $r->header('Authorization'), $m)) {
            $given = trim($m[1]);
        }
        if ($given === '') {
            $given = (string) $r->query('token', '');
        }
        if (!hash_equals($secret, $given)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $body = $r->json()->all();
        $events = array_is_list($body) ? $body : [$body];
        $stat = ['received' => 0, 'recorded' => 0, 'duplicate' => 0, 'ignored' => 0, 'errors' => 0];
        foreach (array_slice($events, 0, 200) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $stat['received']++;
            try {
                $res = $svc->handle($e);
                $stat[$res] = ($stat[$res] ?? 0) + 1;
            } catch (\Throwable $ex) {
                $stat['errors']++;
                Log::warning('UIPE Mail: webhook event failed', ['error' => $ex->getMessage()]);
            }
        }
        // لو كل الأحداث فشلت بخطأ داخلي نرجّع 500 عشان Brevo يعيد المحاولة؛ غير كده 200.
        return response()->json(['success' => true, 'data' => $stat], $stat['received'] > 0 && $stat['errors'] === $stat['received'] ? 500 : 200);
    }
}
