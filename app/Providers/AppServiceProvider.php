<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Support\PersistentStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', fn (array $config) => new BrevoTransport((string) ($config['key'] ?? '')));

        // Rate limiters لانضمام الاجتماعات والزيارات العامة. قبل كده كانت throttle:N,1 العادية بتتحسب على IP بس،
        // ولو الـ IP الحقيقي مش واصل (UIP_PROXY_SECRET مش مضبوط على Vercel) كل الزوار بيشاركوا IP سيرفر Vercel
        // فبيتخطّوا الحد في ثواني ويجيلهم 429. دلوقتي المفتاح = IP + كود الاجتماع + بصمة الجهاز (User-Agent/لغة/Bearer)،
        // فكل شخص ليه bucket مستقل، ومحاولات تخمين كود الاجتماع لسه محدودة.
        $fingerprint = static function (Request $request): string {
            return sha1(implode('|', [
                $request->ip(),
                (string) $request->userAgent(),
                (string) $request->header('Accept-Language', ''),
                (string) $request->header('Authorization', ''),
            ]));
        };
        RateLimiter::for('meeting-join-info', fn (Request $r) => Limit::perMinute(90)->by('mji|' . $fingerprint($r)));
        RateLimiter::for('meeting-join-verify', fn (Request $r) => Limit::perMinute(30)->by('mjv|' . $fingerprint($r) . '|' . $r->route('joinToken')));
        RateLimiter::for('meeting-join-request', fn (Request $r) => Limit::perMinute(40)->by('mjr|' . $fingerprint($r) . '|' . $r->route('joinToken')));
        // تسجيل الدخول: قبل كده throttle:20,1 على الـ IP بس، فطلاب الجامعة على نفس الواي فاي (أو كل الزوار لو الـ IP الحقيقي مش واصل)
        // كانوا بيستهلكوا الحد مع بعض. دلوقتي: محاولات كل (إيميل + جهاز) محدودة، وفوقها سقف كبير للـ IP نفسه ضد التخمين الجماعي.
        // قفل الحساب بعد المحاولات الفاشلة (AccountLockoutService) لسه شغال زي ما هو.
        RateLimiter::for('auth-login', fn (Request $r) => [
            Limit::perMinute(10)->by('lg|' . sha1(strtolower(trim((string) $r->input('email'))) . '|' . $fingerprint($r))),
            Limit::perMinute(300)->by('lgip|' . $r->ip()),
        ]);
        RateLimiter::for('public-visits-read', fn (Request $r) => Limit::perMinute(240)->by('pvr|' . $fingerprint($r)));
        RateLimiter::for('public-visits-write', fn (Request $r) => Limit::perMinute(60)->by('pvw|' . $fingerprint($r)));

        // Admin → Meetings Control: the plain throttle:N,1 is keyed by IP only (the JWT user is not a Laravel Auth user), and every
        // unnamed throttle on the same IP shares ONE counter — so the admin got "too many attempts" on "Test connection" after a few
        // clicks plus normal page traffic (or other people behind the same proxy IP). Now the bucket is the signed-in admin themself.
        $adminActor = static function (Request $request) use ($fingerprint): string {
            $uid = (int) $request->attributes->get('uip_user_id');

            return $uid > 0 ? 'u' . $uid : 'f' . $fingerprint($request);
        };
        // The admin role (shown as "سوبر أدمن" in the UI — there is no separate super-admin role in the API) is never throttled here;
        // anyone else who somehow reaches these routes (uip.admin blocks them first) still gets a per-person cap.
        $isAdmin = static fn (Request $r): bool => $r->attributes->get('uip_role') === 'admin';
        RateLimiter::for('admin-control-test', fn (Request $r) => $isAdmin($r) ? Limit::none() : Limit::perMinute(20)->by('act|' . $adminActor($r)));
        RateLimiter::for('admin-control-write', fn (Request $r) => $isAdmin($r) ? Limit::none() : Limit::perMinute(120)->by('acw|' . $adminActor($r)));

        // Settings saved on Admin → Platform Policy override the matching .env values at runtime (unset ones keep the .env value).
        \App\Support\PlatformPolicy::applyRuntimeConfig();

        // لو PERSISTENT_STORAGE_PATH متظبط (Volume على Railway) اربط مسارات الكتابة بيه. idempotent ورخيص.
        if (!$this->app->runningUnitTests()) {
            PersistentStorage::ensure();
        }
    }
}
