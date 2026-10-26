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
        RateLimiter::for('public-visits-read', fn (Request $r) => Limit::perMinute(240)->by('pvr|' . $fingerprint($r)));
        RateLimiter::for('public-visits-write', fn (Request $r) => Limit::perMinute(60)->by('pvw|' . $fingerprint($r)));

        // لو PERSISTENT_STORAGE_PATH متظبط (Volume على Railway) اربط مسارات الكتابة بيه. idempotent ورخيص.
        if (!$this->app->runningUnitTests()) {
            PersistentStorage::ensure();
        }
    }
}
