<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تفعيل الدومينات تلقائيًا (من غير ضغط "تحقق"): بيتشغّل من الأمر tenant:domains-check كل دقيقة.
 *  1) الدومين pending/verified بيتفحص DNS-ه بجدول متباعد (كل دقيقتين أول ساعة، بعدين 15 دقيقة، بعدين ساعة، وبعد 7 أيام كل 6 ساعات، وبعد 14 يوم مرة يوميًا).
 *  2) أول ما يبقى active بنفتح اتصال TLS على الدومين:443. ده بيحفّز Caddy on-demand TLS يصدر الشهادة (نقطة domain-check بترد 200)، وبعدين بنقرا الشهادة ونسجّل تاريخ انتهائها.
 *  3) الشهادة اللي قربت تنتهي (tenancy.domains.ssl_renew_days) بتتفحص تاني؛ Caddy بيجدّدها لوحده، إحنا بس بنحدّث الحالة.
 * الاتصال بالـ DNS والـ TLS قابل للاستبدال في الاختبارات: TenantDomainAutomation::$tls = fn (string $domain): array{ok:bool,expires_at?:?string,error?:string}.
 */
final class TenantDomainAutomation
{
    /** @var (\Closure(string):array)|null */
    public static ?\Closure $tls = null;

    /** الفاصل (بالدقايق) بين فحصين حسب عمر الدومين (من إضافته). */
    public static function intervalMinutes(Carbon $created): int
    {
        $hours = $created->diffInHours(now(), true);
        return match (true) {
            $hours < 1 => 2,
            $hours < 24 => 15,
            $hours < 168 => 60,
            $hours < 336 => 360,
            default => 1440,
        };
    }

    /** @return array{checked:int,activated:int,ssl:int} */
    public static function run(?int $limit = null): array
    {
        $out = ['checked' => 0, 'activated' => 0, 'ssl' => 0];
        if (!config('tenancy.domains.auto', true)) {
            return $out;
        }
        $limit = max(1, $limit ?? (int) config('tenancy.domains.auto_batch', 50));

        $rows = DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('t.status', 'active')->whereIn('d.status', ['pending', 'verified'])
            ->where(function ($q) {
                $q->whereNull('d.next_check_at')->orWhere('d.next_check_at', '<=', now());
            })
            ->orderBy('d.next_check_at')->limit($limit)->select('d.*')->get();
        foreach ($rows as $d) {
            try {
                $fresh = TenantDomainService::evaluate($d);
                $out['checked']++;
                $next = $fresh->status === 'active' ? null : now()->addMinutes(self::intervalMinutes(Carbon::parse($fresh->created_at ?: now())));
                DB::table('tenant_domains')->where('id', $d->id)->update(['next_check_at' => $next, 'check_count' => (int) ($d->check_count ?? 0) + 1]);
                if ($fresh->status === 'active') {
                    $out['activated']++;
                }
            } catch (\Throwable $e) {
                DB::table('tenant_domains')->where('id', $d->id)->update(['next_check_at' => now()->addMinutes(15)]);
            }
        }

        if (config('tenancy.domains.ssl_probe', true)) {
            $out['ssl'] = self::probePending(min($limit, 10));
        }
        return $out;
    }

    /** الدومينات active اللي شهادتها مش جاهزة (أو قربت تنتهي): نفحصها. بنحدّ العدد لأن كل اتصال ممكن ياخد لحد 8 ثواني. */
    public static function probePending(int $limit = 10): int
    {
        $renew = max(1, (int) config('tenancy.domains.ssl_renew_days', 14));
        $rows = DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('t.status', 'active')->where('d.status', 'active')
            ->where(function ($q) use ($renew) {
                $q->where(function ($q) {
                    $q->whereIn('d.ssl_status', ['none', 'pending'])->where(function ($q) {
                        $q->whereNull('d.ssl_checked_at')->orWhere('d.ssl_checked_at', '<=', now()->subMinute());
                    });
                })->orWhere(function ($q) {
                    $q->where('d.ssl_status', 'failed')->where('d.ssl_checked_at', '<=', now()->subMinutes(10));
                })->orWhere(function ($q) use ($renew) {
                    $q->where('d.ssl_status', 'active')->where('d.ssl_expires_at', '<=', now()->addDays($renew))->where('d.ssl_checked_at', '<=', now()->subHours(6));
                });
            })
            ->orderBy('d.ssl_checked_at')->limit($limit)->select('d.*')->get();
        $n = 0;
        foreach ($rows as $d) {
            self::checkSsl($d);
            $n++;
        }
        return $n;
    }

    /** يفحص شهادة دومين واحد ويحدّث الحالة: active لو التوصيل سليم، pending (أول 30 دقيقة من التفعيل) أو failed بعد كده. */
    public static function checkSsl(object $d): array
    {
        $r = self::probe((string) $d->domain);
        $upd = ['ssl_checked_at' => now(), 'updated_at' => now()];
        if (!empty($r['ok'])) {
            $upd += ['ssl_status' => 'active', 'ssl_error' => null, 'ssl_expires_at' => $r['expires_at'] ?? null];
        } else {
            $young = $d->activated_at && Carbon::parse($d->activated_at)->diffInMinutes(now(), true) < 30;
            $upd += ['ssl_status' => $young ? 'pending' : 'failed', 'ssl_error' => mb_substr((string) ($r['error'] ?? 'The secure connection failed.'), 0, 190)];
        }
        DB::table('tenant_domains')->where('id', $d->id)->update($upd);
        return $r;
    }

    /** اتصال TLS حقيقي بالدومين (SNI + تحقق كامل من الشهادة والاسم). */
    public static function probe(string $domain): array
    {
        if (self::$tls) {
            return (array) (self::$tls)($domain);
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'SNI_enabled' => true, 'peer_name' => $domain, 'capture_peer_cert' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $msg = (string) $errstr;
            if ($msg === '' || stripos($msg, 'verify') !== false || stripos($msg, 'handshake') !== false || stripos($msg, 'certificate') !== false) {
                $msg = 'The certificate is not ready yet. It is requested automatically on the first secure visit and usually takes under a minute.';
            } else {
                $msg = 'Could not open a secure connection: ' . $msg;
            }
            return ['ok' => false, 'error' => mb_substr($msg, 0, 190)];
        }
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
        @fclose($fp);
        $info = $cert ? @openssl_x509_parse($cert) : false;
        $exp = (is_array($info) && !empty($info['validTo_time_t'])) ? Carbon::createFromTimestamp((int) $info['validTo_time_t'])->toDateTimeString() : null;
        return ['ok' => true, 'expires_at' => $exp];
    }
}
