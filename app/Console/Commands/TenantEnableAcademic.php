<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يصلّح منصة موجودة (اتعملت قبل التحديث): يفعّل الميزات الأكاديمية ويدّي أدمن المنصة دور الجامعة.
 *   php artisan tenant:enable-academic 9
 *   php artisan tenant:enable-academic 9 --features=academic,meetings,exams,drive
 */
class TenantEnableAcademic extends Command
{
    protected $signature = 'tenant:enable-academic {tenant : رقم المنصة (tenants.id)} {--features=academic,meetings : ميزات بتتفعّل، مفصولة بفاصلة} {--no-role : من غير إعطاء دور الجامعة للأدمن}';
    protected $description = 'Enable academic features for an isolated workspace and give its admin the university role';

    public function handle(): int
    {
        $id = (int) $this->argument('tenant');
        $t = DB::connection(TenantContext::centralConnection())->table('tenants')->where('id', $id)->first();
        if (!$t || ($t->mode ?? '') !== 'isolated') {
            $this->error('Isolated workspace not found.');
            return self::FAILURE;
        }
        $catalog = array_keys(TenantFeatures::catalog());
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('features')))) as $f) {
            if (!in_array($f, $catalog, true)) {
                $this->warn("Unknown feature: {$f}");
                continue;
            }
            TenantFeatures::decide($id, $f, 'enabled', null, 'enabled by tenant:enable-academic');
            $this->info("Enabled: {$f}");
        }
        if (!$this->option('no-role')) {
            TenantContext::run($t, function () {
                $adminRole = DB::table('roles')->where('slug', 'admin')->value('id');
                $uid = $adminRole ? DB::table('user_roles')->where('role_id', $adminRole)->orderBy('assigned_at')->value('user_id') : null;
                if (!$uid) {
                    $this->warn('No admin account found in this workspace.');
                    return;
                }
                $this->info(TenantProvisioner::grantUniversityRole((int) $uid) ? "University role granted to user #{$uid}." : "User #{$uid} already has the university role.");
            });
        }
        return self::SUCCESS;
    }
}
