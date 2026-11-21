<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantFeatures;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

class TenantCreate extends Command
{
    protected $signature = 'tenant:create
        {name : اسم العميل}
        {--domain= : الدومين اللي العميل هيدخل منه (مطلوب)}
        {--admin-email= : إيميل أول أدمن عند العميل (مطلوب)}
        {--admin-name=Admin : اسم الأدمن}
        {--admin-password= : لو فاضي بيتولّد تلقائيًا ويتطبع مرة واحدة}
        {--slug= : اختياري}
        {--kind=company : company|university|developer}
        {--features= : ميزات تتفعّل من البداية، مفصولة بفاصلة (الافتراضي: ولا واحدة — الأساسيات بس)}';

    protected $description = 'ينشئ عميل معزول: قاعدة بيانات + migrations + أدوار وصلاحيات + أول أدمن + الدومين.';

    public function handle(TenantProvisioner $p): int
    {
        if (!$this->option('domain') || !$this->option('admin-email')) {
            $this->error('--domain و --admin-email مطلوبين.');
            return self::INVALID;
        }
        try {
            $r = $p->create([
                'name' => (string) $this->argument('name'),
                'domain' => (string) $this->option('domain'),
                'admin_email' => (string) $this->option('admin-email'),
                'admin_name' => (string) $this->option('admin-name'),
                'admin_password' => (string) $this->option('admin-password'),
                'slug' => $this->option('slug') ?: null,
                'kind' => (string) $this->option('kind'),
            ], fn (string $m) => $this->line($m));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Tenant created.');

        if ($list = trim((string) $this->option('features'))) {
            $keys = $list === 'all' ? array_keys(TenantFeatures::catalog()) : array_values(array_filter(array_map('trim', explode(',', $list))));
            foreach ($keys as $f) {
                if (!array_key_exists($f, TenantFeatures::catalog())) {
                    $this->warn("ميزة مش معروفة اتخطّت: {$f}");
                    continue;
                }
                TenantFeatures::decide((int) $r['tenant_id'], $f, 'enabled', null, 'enabled at creation');
            }
        }
        $this->table(['Field', 'Value'], [
            ['id', $r['tenant_id']], ['public_id', $r['public_id']], ['database', $r['db_name']],
            ['domain', $r['domain']], ['admin email', $r['admin_email']],
            ['admin password', $r['admin_password'] ?? '(الباسورد اللي حددته)'],
        ]);
        if ($r['admin_password']) {
            $this->warn('احفظ الباسورد ده دلوقتي — مش هيتعرض تاني.');
        }
        return self::SUCCESS;
    }
}
