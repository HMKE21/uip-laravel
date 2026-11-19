<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UIPE Mail — المرحلة 5: إنشاء الصندوق المؤسسي تلقائيًا للعضو (طالب/موظف/كلية/جامعة) بدل ما الأدمن يدوس Provision.
 * بيتنادى من: تسجيل/دعوة الطالب والموظف، الموافقة على طلب الانضمام، فتح البريد (كسول، مرة كل 5 دقايق لكل مستخدم)،
 * وأمر mail:identity-sync المجدول. idempotent ومبيرميش استثناءات أبدًا (forUser).
 */
class MailProvisioningService
{
    public function __construct(private MailTenantService $tenants)
    {
    }

    /** نداء آمن: أي خطأ بيتبلع. $force=false → مرة كل 5 دقايق لكل مستخدم (للنداء الكسول). */
    public static function forUser(int $userId, bool $force = true): void
    {
        try {
            if (!$force && !Cache::add('mailprov:' . $userId, 1, 300)) {
                return;
            }
            app(self::class)->provisionUser($userId);
        } catch (\Throwable $e) {
            Log::warning('Mail auto-provision skipped: ' . $e->getMessage());
        }
    }

    /** @return array<int,array{type:string,university_id:int}> الأدوار المؤسسية اللي المستخدم ده يستحق عليها صندوق. */
    public function memberships(int $userId): array
    {
        $out = [];
        if ($uni = DB::table('students')->where('user_id', $userId)->value('university_id')) {
            $out[] = ['type' => 'student', 'university_id' => (int) $uni];
        }
        if ($uni = DB::table('academic_staff')->where('user_id', $userId)->where('status', 'active')->value('university_id')) {
            $out[] = ['type' => 'staff', 'university_id' => (int) $uni];
        }
        if ($uni = DB::table('faculties')->where('user_id', $userId)->value('university_id')) {
            $out[] = ['type' => 'faculty', 'university_id' => (int) $uni];
        }
        if ($uni = DB::table('universities')->where('user_id', $userId)->value('id')) {
            $out[] = ['type' => 'university', 'university_id' => (int) $uni];
        }
        return $out;
    }

    /** @return int عدد الصناديق اللي اتعملت */
    public function provisionUser(int $userId): int
    {
        $created = 0;
        foreach ($this->memberships($userId) as $m) {
            $t = $this->tenants->tenantByUniversity($m['university_id'], false);
            if (!$t || !($t->auto_provision ?? true) || $t->status !== 'active') {
                continue;
            }
            try {
                $res = $this->tenants->provisionMembers($m['university_id'], [$m['type']], null, $userId);
                $created += (int) $res['created'];
            } catch (\DomainException) {
                // مفيش نطاق مفعّل/الجامعة موقوفة: مفيش صندوق دلوقتي، والمزامنة المجدولة هتحاول بعدين.
            }
        }
        return $created;
    }

    /**
     * المزامنة المجدولة: كل الـ tenants اللي auto_provision فيها شغّال.
     * @return array{tenants:int,created:int,failed:int,remaining:int}
     */
    public function syncAll(): array
    {
        $sum = ['tenants' => 0, 'created' => 0, 'failed' => 0, 'remaining' => 0];
        $tenants = DB::table('mail_tenants')->where('status', 'active')
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('mail_tenants', 'auto_provision'), fn ($q) => $q->where('auto_provision', 1))->get();
        foreach ($tenants as $t) {
            if (!DB::table('mail_domains')->where('tenant_id', $t->id)->where('is_primary', 1)->where('status', 'active')->exists()) {
                continue;
            }
            try {
                $r = $this->tenants->provisionMembers((int) $t->university_id, MailTenantService::MEMBER_TYPES);
            } catch (\DomainException) {
                continue;
            }
            $sum['tenants']++;
            $sum['created'] += $r['created'];
            $sum['failed'] += $r['failed'];
            $sum['remaining'] += $r['remaining'];
            if ($r['created'] || $r['failed']) {
                DB::table('provisioning_runs')->insert(['tenant_id' => $t->id, 'university_id' => $t->university_id, 'kind' => 'auto', 'total' => $r['created'] + $r['failed'],
                    'created' => $r['created'], 'failed' => $r['failed'], 'created_at' => now()]);
            }
        }
        return $sum;
    }
}
