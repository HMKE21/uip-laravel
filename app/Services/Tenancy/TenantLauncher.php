<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تنفيذ إطلاق المنصة في الخلفية وتسجيل التقدّم في tenant_launches (الواجهة بتسأل عن الحالة كل ثانية ونص).
 * الباسورد بيتخزن مشفّر لحد ما الإطلاق يخلص وبعدها بيتمسح؛ الباسورد المتولّد بيترجع للمالك مرة واحدة بس.
 */
final class TenantLauncher
{
    private const STEPS = [
        'Creating database' => ['database', 12],
        'Running migrations' => ['migrations', 25],
        'Copying roles' => ['roles', 75],
        'Creating first admin' => ['admin', 85],
    ];

    public function __construct(private TenantService $tenants)
    {
    }

    private function set(int $id, array $data): void
    {
        // دايمًا على قاعدة المنصة الأم: الـ callback بيتنادى وإحنا جوّه سياق قاعدة العميل الجديدة.
        DB::connection(TenantContext::centralConnection())->table('tenant_launches')->where('id', $id)->update($data + ['updated_at' => now()]);
    }

    public function run(int $id): void
    {
        $row = DB::table('tenant_launches')->where('id', $id)->first();
        if (!$row || $row->status !== 'queued') {
            return;
        }
        @set_time_limit(900);
        @ignore_user_abort(true);
        $this->set($id, ['status' => 'running', 'step' => 'database', 'progress' => 5]);
        try {
            $p = json_decode(Crypt::decryptString((string) $row->payload), true, 512, JSON_THROW_ON_ERROR);
            $userId = (int) $row->user_id;
            $r = app(TenantProvisioner::class)->create([
                'name' => $p['name'], 'domain' => null, 'slug' => null, 'kind' => $p['kind'], 'owner_user_id' => $userId,
                'admin_email' => $p['admin_email'], 'admin_name' => $p['admin_name'] ?: 'Admin', 'admin_password' => $p['admin_password'] ?? '',
                'on_created' => fn (int $tid, string $slug) => $this->set($id, ['tenant_id' => $tid, 'slug' => $slug]),
            ], function (string $m) use ($id) {
                foreach (self::STEPS as $prefix => [$step, $pct]) {
                    if (str_starts_with($m, $prefix)) {
                        $this->set($id, ['step' => $step, 'progress' => $pct]);
                        break;
                    }
                }
            });
            $tid = (int) $r['tenant_id'];
            $this->set($id, ['step' => 'access', 'progress' => 90, 'tenant_id' => $tid, 'slug' => $r['slug']]);
            DB::table('tenant_members')->insert(['tenant_id' => $tid, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            $this->tenants->audit($userId, $tid, 'launch', (string) $p['kind']);

            $this->set($id, ['step' => 'features', 'progress' => 93]);
            $wanted = (array) ($p['features'] ?? []);
            $auto = array_values(array_intersect($wanted, (array) config('tenancy.launch.auto_features', [])));
            $pending = array_values(array_diff($wanted, $auto));
            foreach ($auto as $f) {
                TenantFeatures::decide($tid, $f, 'enabled', null, 'enabled at launch');
            }
            if ($pending) {
                TenantFeatures::request($tid, $pending, $userId, 'requested at launch');
            }

            $this->set($id, ['step' => 'site', 'progress' => 96]);
            if (!empty($p['template'])) {
                try { // فشل الموقع مينفعش يبوّظ الإطلاق: يختار القالب بعدين من منشئ الموقع
                    $site = app(TenantSiteService::class);
                    $site->applyTemplate($userId, $tid, ['key' => $p['template']]);
                    $site->publish($userId, $tid);
                } catch (\Throwable $e) {
                    Log::warning('Launch: site template not applied', ['tenant' => $tid, 'error' => $e->getMessage()]);
                }
            }
            $url = TenantAddress::urlFor($tid);
            $result = [
                'slug' => $r['slug'], 'url' => $url, 'login_url' => $url ? $url . '/login' : null, 'admin_email' => $p['admin_email'],
                'admin_password' => $r['admin_password'], // متولّد: بيترجع مرة واحدة
                'features_enabled' => $auto, 'features_pending' => $pending,
            ];
            $this->set($id, ['status' => 'done', 'step' => 'done', 'progress' => 100, 'payload' => null, 'result' => Crypt::encryptString(json_encode($result, JSON_UNESCAPED_UNICODE))]);
        } catch (\Throwable $e) {
            Log::error('Platform launch failed', ['launch' => $id, 'error' => $e->getMessage()]);
            $msg = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Could not create the platform. Please try again in a minute.';
            $this->set($id, ['status' => 'failed', 'payload' => null, 'error' => $msg]);
        }
    }

    /** @return array<string,mixed>|null */
    public function status(int $userId, string $uuid): ?array
    {
        $row = DB::table('tenant_launches')->where('uuid', $uuid)->where('user_id', $userId)->first();
        return $row ? $this->present($userId, $row) : null;
    }

    /** آخر إطلاق لسه المستخدم ما شافش نتيجته (شغّال أو خلص من أقل من ساعة). */
    public function active(int $userId, bool $peek = false): ?array
    {
        if ($peek) { // للبانر بس: من غير ما نستهلك النتيجة (الباسورد المتولّد لازم يوصل للويزارد)
            $r = DB::table('tenant_launches')->where('user_id', $userId)->whereIn('status', ['queued', 'running'])->where('updated_at', '>', now()->subMinutes(20))->orderByDesc('id')->first(['uuid', 'status', 'step', 'progress']);
            return $r ? ['launch_id' => $r->uuid, 'status' => $r->status, 'step' => $r->step, 'progress' => (int) $r->progress] : null;
        }
        $row = DB::table('tenant_launches')->where('user_id', $userId)->whereNull('seen_at')->where('created_at', '>', now()->subHour())->orderByDesc('id')->first();
        return $row ? $this->present($userId, $row) : null;
    }

    private function present(int $userId, object $row): array
    {
        if (in_array($row->status, ['queued', 'running'], true) && strtotime((string) $row->updated_at . ' UTC') < time() - 1200) {
            $this->set((int) $row->id, ['status' => 'failed', 'payload' => null, 'error' => 'The launch took too long and was stopped. Please try again.']);
            $row = DB::table('tenant_launches')->where('id', $row->id)->first();
        }
        $out = ['launch_id' => $row->uuid, 'status' => $row->status, 'step' => $row->step, 'progress' => (int) $row->progress, 'slug' => $row->slug, 'error' => $row->error];
        if ($row->status === 'done') {
            $res = json_decode(Crypt::decryptString((string) $row->result), true) ?: [];
            $out['launch'] = $res;
            $out['tenant'] = $row->tenant_id ? $this->tenants->show($userId, (int) $row->tenant_id)['tenant'] : null;
            unset($res['admin_password']); // المتولّد بيظهر مرة واحدة
            $this->set((int) $row->id, ['seen_at' => now(), 'result' => Crypt::encryptString(json_encode($res, JSON_UNESCAPED_UNICODE))]);
        } elseif ($row->status === 'failed') {
            $this->set((int) $row->id, ['seen_at' => now()]);
        }
        return $out;
    }
}
