<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * رسائل نموذج "تواصل معنا" في موقع العميل. الزائر بيبعت (من غير حساب) والمالك/الأدمن بيقراها من لوحة الموقع.
 * الحماية: النموذج لازم يكون مفعّل في الموقع المنشور (section contact بـ form=true)، honeypot، حد يومي للعميل، حد لكل IP في الساعة، ومنع تكرار نفس الرسالة. الـ IP بيتخزّن hash بس.
 */
final class TenantSiteMessages
{
    private static function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private static function db(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection(TenantContext::centralConnection());
    }

    /** في نموذج تواصل مفعّل (قسم contact شغّال بـ form=true) في الرئيسية أو أي صفحة شغّالة؟ */
    public static function hasForm(array $settings): bool
    {
        $groups = [$settings['home']['sections'] ?? []];
        foreach ($settings['pages'] ?? [] as $p) {
            if (!empty($p['enabled'])) {
                $groups[] = $p['sections'] ?? [];
            }
        }
        foreach ($groups as $sections) {
            foreach ($sections as $s) {
                if (($s['type'] ?? '') === 'contact' && !empty($s['enabled']) && !empty($s['props']['form'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $published الإعدادات المنشورة (full) */
    public static function submit(object $tenant, array $published, array $in, string $ip): array
    {
        if (!self::hasForm($published)) {
            self::fail('This site does not accept messages.', 404);
        }
        if (trim((string) ($in['website'] ?? '')) !== '') {
            return ['sent' => true];                        // honeypot: البوت بيفتكر إنه نجح
        }
        $name = TenantSiteSanitizer::text($in['name'] ?? '', 80);
        $email = TenantSiteSanitizer::text($in['email'] ?? '', 150);
        $subject = TenantSiteSanitizer::text($in['subject'] ?? '', 120);
        $message = TenantSiteSanitizer::text($in['message'] ?? '', 2000);
        if (mb_strlen($name) < 2) {
            self::fail('Please enter your name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Please enter a valid email address.');
        }
        if (mb_strlen($message) < 5) {
            self::fail('Please write your message.');
        }
        $id = (int) $tenant->id;
        $perDay = max(1, (int) config('tenancy.site.messages_per_day', 200));
        if (self::db()->table('tenant_site_messages')->where('tenant_id', $id)->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
            self::fail('This site cannot receive more messages right now. Please try again later.', 429);
        }
        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        if (self::db()->table('tenant_site_messages')->where('tenant_id', $id)->where('ip_hash', $ipHash)->where('created_at', '>=', now()->subHour())->count() >= 5) {
            self::fail('Too many messages from your connection. Please try again later.', 429);
        }
        $fp = hash('sha256', mb_strtolower($email) . '|' . $message);
        if (self::db()->table('tenant_site_messages')->where('tenant_id', $id)->where('fingerprint', $fp)->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            return ['sent' => true];
        }
        self::db()->table('tenant_site_messages')->insert([
            'tenant_id' => $id, 'name' => $name, 'email' => $email, 'subject' => $subject !== '' ? $subject : null, 'message' => $message,
            'status' => 'new', 'fingerprint' => $fp, 'ip_hash' => $ipHash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $keep = max(10, (int) config('tenancy.site.max_messages', 1000));
        $old = self::db()->table('tenant_site_messages')->where('tenant_id', $id)->orderByDesc('id')->skip($keep)->take(500)->pluck('id')->all();
        if ($old) {
            self::db()->table('tenant_site_messages')->whereIn('id', $old)->delete();
        }
        return ['sent' => true];
    }

    public static function unread(int $tenantId): int
    {
        return (int) self::db()->table('tenant_site_messages')->where('tenant_id', $tenantId)->where('status', 'new')->count();
    }

    /** @return array{messages:array,total:int,unread:int,page:int,per_page:int} */
    public static function list(int $tenantId, ?string $status, int $page, int $per = 20): array
    {
        $per = max(1, min(50, $per));
        $page = max(1, $page);
        $q = self::db()->table('tenant_site_messages')->where('tenant_id', $tenantId);
        if (in_array($status, ['new', 'read', 'archived'], true)) {
            $q->where('status', $status);
        }
        $total = (int) (clone $q)->count();
        $rows = $q->orderByDesc('id')->skip(($page - 1) * $per)->take($per)->get(['id', 'name', 'email', 'subject', 'message', 'status', 'created_at'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'email' => $m->email, 'subject' => $m->subject, 'message' => $m->message, 'status' => $m->status, 'created_at' => $m->created_at])->all();
        return ['messages' => $rows, 'total' => $total, 'unread' => self::unread($tenantId), 'page' => $page, 'per_page' => $per];
    }

    public static function setStatus(int $tenantId, int $id, string $status): void
    {
        if (!in_array($status, ['new', 'read', 'archived'], true)) {
            self::fail('Invalid status.');
        }
        if (!self::db()->table('tenant_site_messages')->where('tenant_id', $tenantId)->where('id', $id)->update(['status' => $status, 'updated_at' => now()])) {
            if (!self::db()->table('tenant_site_messages')->where('tenant_id', $tenantId)->where('id', $id)->exists()) {
                self::fail('Message not found.', 404);
            }
        }
    }

    public static function delete(int $tenantId, int $id): void
    {
        if (!self::db()->table('tenant_site_messages')->where('tenant_id', $tenantId)->where('id', $id)->delete()) {
            self::fail('Message not found.', 404);
        }
    }
}
