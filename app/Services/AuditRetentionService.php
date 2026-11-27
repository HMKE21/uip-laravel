<?php

namespace App\Services;

use App\Services\Tenancy\TenantStorage;
use App\Support\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * سياسة الاحتفاظ بسجلات التدقيق وسجلات الأمان (بند 6).
 *
 * المسح بيشتغل على "بداية" السلسلة بس (الأقدم أولًا، تسلسل متصل) وبيسيب مرساة (anchor) موقّعة فيها آخر هاش اتمسح،
 * فالتحقق من السلامة بيكمّل من أول صف فاضل من غير ما يبان كأنه تلاعب. قبل المسح (لو مفعّل) الصفوف بتتأرشف في ملف JSONL
 * فيه الهاشات (يتحقق منه لوحده) + SHA-256 للملف بيتسجّل في المرساة. الـ legal hold بيوقف أي مسح.
 */
class AuditRetentionService
{
    public function __construct(
        private ComplianceSettings $settings,
        private AuditChainService $chain,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->settings->get('audit_retention');
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function update(array $in, ?int $userId, ?string $ip = null): array
    {
        $old = $this->get();
        $auditDays = (int) ($in['audit_days'] ?? $old['audit_days']);
        $logDays = (int) ($in['security_log_days'] ?? $old['security_log_days']);
        if ($auditDays < ComplianceSettings::MIN_AUDIT_DAYS || $auditDays > 3650) {
            throw new \InvalidArgumentException('Audit log retention must be between ' . ComplianceSettings::MIN_AUDIT_DAYS . ' and 3650 days.');
        }
        if ($logDays < ComplianceSettings::MIN_LOG_DAYS || $logDays > 3650) {
            throw new \InvalidArgumentException('Security log retention must be between ' . ComplianceSettings::MIN_LOG_DAYS . ' and 3650 days.');
        }
        $hold = !empty($in['legal_hold']);
        $reason = trim((string) ($in['legal_hold_reason'] ?? $old['legal_hold_reason']));
        if ($hold && $reason === '') {
            throw new \InvalidArgumentException('A legal hold needs a reason (case / request reference).');
        }
        $new = [
            'enabled'               => !empty($in['enabled']),
            'audit_days'            => $auditDays,
            'security_log_days'     => $logDays,
            'archive_before_delete' => array_key_exists('archive_before_delete', $in) ? !empty($in['archive_before_delete']) : (bool) $old['archive_before_delete'],
            'legal_hold'            => $hold,
            'legal_hold_reason'     => mb_substr($reason, 0, 300),
        ];
        $this->settings->put('audit_retention', $new, $userId);
        $this->auditLog->record($userId, 'compliance.retention.updated', 'ComplianceSettings', null, $old, $new, $ip);

        return $new;
    }

    /** ما كان هيتمسح لو الأمر اتنفّذ دلوقتي. @return array<string,mixed> */
    public function preview(): array
    {
        $cfg = $this->get();
        $cutoff = now()->subDays((int) $cfg['audit_days']);
        $plan = $this->plan($cutoff->format('Y-m-d H:i:s'));
        $files = $this->oldLogFiles((int) $cfg['security_log_days']);

        return [
            'settings'         => $cfg,
            'cutoff'           => $cutoff->format('Y-m-d H:i:s'),
            'audit_rows'       => $plan['count'],
            'audit_oldest'     => $plan['from_at'],
            'audit_newest'     => $plan['to_at'],
            'unsealed_old'     => (int) DB::table('audit_logs')->whereNull('chain_seq')->where('created_at', '<', $cutoff)->count(),
            'log_files'        => count($files),
            'log_files_oldest' => $files ? basename($files[0]) : null,
            'total_rows'       => (int) DB::table('audit_logs')->count(),
            'blocked_reason'   => $cfg['legal_hold'] ? 'legal_hold' : (!$cfg['enabled'] ? 'disabled' : null),
        ];
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function run(?int $userId, bool $scheduled = false, ?string $ip = null): array
    {
        $cfg = $this->get();
        if ($cfg['legal_hold']) {
            throw new \RuntimeException('Retention is paused by a legal hold.');
        }
        if (!$cfg['enabled']) {
            throw new \RuntimeException('Automatic retention is disabled. Enable it in the retention settings first.');
        }

        // خلّي أي صف قديم متختم قبل ما نحسب (المسح بيشتغل على الصفوف المتختمة بس).
        $this->chain->sealPending();

        $cutoff = now()->subDays((int) $cfg['audit_days'])->format('Y-m-d H:i:s');
        $this->finishInterrupted();
        $plan = $this->plan($cutoff);
        $deleted = 0;
        $archive = null;

        if ($plan['count'] > 0) {
            $last = DB::table('audit_logs')->where('chain_seq', $plan['to_seq'])->first(['entry_hash']);
            if (!$last) {
                throw new \RuntimeException('The retention boundary entry was not found.');
            }
            if ($cfg['archive_before_delete']) {
                $archive = $this->archive((int) $plan['from_seq'], (int) $plan['to_seq']);
            }
            // المرساة الأول (قبل المسح): لو العملية وقفت في النص، التحقق يفضل سليم والمسح بيتكمّل في التشغيل الجاي.
            DB::table('audit_chain_anchors')->insert([
                'up_to_seq'       => $plan['to_seq'],
                'up_to_hash'      => $last->entry_hash,
                'pruned_count'    => $plan['count'],
                'cutoff_at'       => $cutoff,
                'from_created_at' => $plan['from_at'],
                'to_created_at'   => $plan['to_at'],
                'archive_path'    => $archive['path'] ?? null,
                'archive_sha256'  => $archive['sha256'] ?? null,
                'signature'       => AuditChain::sign((int) $plan['to_seq'], (string) $last->entry_hash, (int) $plan['count']),
                'run_by'          => $userId,
                'created_at'      => now(),
            ]);
            $deleted = $this->deleteUpTo((int) $plan['to_seq']);
        }

        $files = $this->deleteLogFiles((int) $cfg['security_log_days']);

        $result = ['audit_rows_deleted' => $deleted, 'log_files_deleted' => $files, 'archive' => $archive, 'cutoff' => $cutoff];
        $this->auditLog->record($userId, $scheduled ? 'compliance.retention.scheduled_run' : 'compliance.retention.run', 'ComplianceSettings', null, null,
            ['audit_rows_deleted' => $deleted, 'log_files_deleted' => $files, 'cutoff' => $cutoff, 'archive_sha256' => $archive['sha256'] ?? null], $ip);

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $limit = 20): array
    {
        return DB::table('audit_chain_anchors')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * أطول بداية متصلة من السلسلة قبل الـ cutoff: S = (أقل seq لصف جديد) - 1، ومبنمسحش آخر صف في السلسلة.
     *
     * @return array{count:int,from_seq:?int,to_seq:?int,from_at:?string,to_at:?string}
     */
    private function plan(string $cutoff): array
    {
        $empty = ['count' => 0, 'from_seq' => null, 'to_seq' => null, 'from_at' => null, 'to_at' => null];
        $head = (int) DB::table('audit_chain_state')->where('id', 1)->value('last_seq');
        $first = DB::table('audit_logs')->whereNotNull('chain_seq')->min('chain_seq');
        if ($head < 2 || $first === null) {
            return $empty;
        }
        $minNew = DB::table('audit_logs')->whereNotNull('chain_seq')->where('created_at', '>=', $cutoff)->min('chain_seq');
        $to = min(($minNew !== null ? (int) $minNew : $head + 1) - 1, $head - 1);
        if ($to < (int) $first) {
            return $empty;
        }
        $q = DB::table('audit_logs')->whereNotNull('chain_seq')->where('chain_seq', '<=', $to);

        return [
            'count'    => (int) $q->count(),
            'from_seq' => (int) $first,
            'to_seq'   => $to,
            'from_at'  => (clone $q)->min('created_at'),
            'to_at'    => (clone $q)->max('created_at'),
        ];
    }

    /** لو تشغيل سابق وقف بعد ما كتب المرساة وقبل ما يخلّص المسح. */
    private function finishInterrupted(): void
    {
        $anchor = DB::table('audit_chain_anchors')->orderByDesc('up_to_seq')->first(['up_to_seq']);
        if ($anchor) {
            $this->deleteUpTo((int) $anchor->up_to_seq);
        }
    }

    private function deleteUpTo(int $seq): int
    {
        $total = 0;
        do {
            $n = DB::table('audit_logs')->whereNotNull('chain_seq')->where('chain_seq', '<=', $seq)->limit(1000)->delete();
            $total += $n;
        } while ($n > 0);

        return $total;
    }

    /** @return array{path:string,sha256:string,rows:int} */
    private function archive(int $fromSeq, int $toSeq): array
    {
        $dir = TenantStorage::storagePath('app/audit-archive');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the archive folder.');
        }
        $name = sprintf('audit-%d-%d-%s.jsonl', $fromSeq, $toSeq, date('Ymd_His'));
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $fh = fopen($path, 'w');
        if (!$fh) {
            throw new \RuntimeException('Could not write the archive file.');
        }
        $rows = 0;
        $last = $fromSeq - 1;
        while (true) {
            $chunk = DB::table('audit_logs')->whereNotNull('chain_seq')->where('chain_seq', '>', $last)->where('chain_seq', '<=', $toSeq)->orderBy('chain_seq')->limit(1000)->get();
            if ($chunk->isEmpty()) {
                break;
            }
            foreach ($chunk as $r) {
                fwrite($fh, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $rows++;
                $last = (int) $r->chain_seq;
            }
        }
        fclose($fh);
        @chmod($path, 0640);

        return ['path' => 'app/audit-archive/' . $name, 'sha256' => hash_file('sha256', $path), 'rows' => $rows];
    }

    /** @return string[] ملفات storage/logs/security/YYYY-MM-DD.log الأقدم من $days (الأقدم أولًا) */
    private function oldLogFiles(int $days): array
    {
        $dir = TenantStorage::storagePath('logs/security');
        if (!is_dir($dir)) {
            return [];
        }
        $limit = date('Y-m-d', strtotime("-{$days} days"));
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $f) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', basename($f), $m) && $m[1] < $limit) {
                $out[] = $f;
            }
        }
        sort($out);

        return $out;
    }

    private function deleteLogFiles(int $days): int
    {
        $n = 0;
        foreach ($this->oldLogFiles($days) as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
