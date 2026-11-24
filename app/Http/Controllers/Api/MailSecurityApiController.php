<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailAuditService;
use App\Services\Mail\MailBounceService;
use App\Services\Mail\MailQueueService;
use App\Services\Mail\MailSecurityService;
use App\Services\Mail\MailTenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/mail-admin/security — UIPE Mail المرحلة 7: الإدارة والأمان.
 *  - university (أدمن الجامعة): كل حاجة على جامعته بس.
 *  - admin (المنصة): من غير university_id = كل المنصة (قراءة + قواعد IP المنصة + إجراءات على أي صندوق)، ومعاه university_id = نفس نطاق أدمن الجامعة.
 *  - faculty وباقي الأدوار: ممنوعين (بيانات أمنية).
 * الخصوصية: مفيش موضوع أو نص رسائل في أي رد هنا.
 */
class MailSecurityApiController extends Controller
{
    public function __construct(
        private MailSecurityService $security,
        private MailAuditService $audit,
        private MailBounceService $bounces,
        private MailQueueService $queue,
        private MailTenantService $tenants,
    ) {
    }

    /** @return array{role:string,uid:int,universityId:?int}|null  universityId=null → المنصة كلها (أدمن بس). */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        $uid = (int) $r->attributes->get('uip_user_id');
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $uid)->value('id');
            return $id ? ['role' => $role, 'uid' => $uid, 'universityId' => (int) $id] : null;
        }
        if ($role === 'admin') {
            $raw = $r->input('university_id', $r->query('university_id'));
            if ($raw === null || $raw === '' || $raw === 'all') {
                return ['role' => $role, 'uid' => $uid, 'universityId' => null];
            }
            $id = (int) $raw;
            return $id && DB::table('universities')->where('id', $id)->exists() ? ['role' => $role, 'uid' => $uid, 'universityId' => $id] : null;
        }
        return null;
    }

    private function run(Request $r, callable $fn, int $okStatus = 200)
    {
        try {
            $s = $this->scope($r);
            if (!$s) {
                return $this->apiError('Mail security is available to university and platform admins only.', null, 403);
            }
            return $this->apiSuccess($fn($s), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    /** سجل تدقيق لإجراء أدمن (عمره ما بيكسر العملية). */
    private function log(Request $r, array $s, string $action, string $summary, array $ctx = []): void
    {
        $tenantId = $s['universityId'] ? DB::table('mail_tenants')->where('university_id', $s['universityId'])->value('id') : null;
        $this->audit->record($action, $summary, $ctx + ['university_id' => $s['universityId'], 'tenant_id' => $tenantId ? (int) $tenantId : null,
            'actor_user_id' => $s['uid'], 'actor_role' => $s['role'], 'ip' => (string) $r->ip()]);
    }

    private function page(Request $r, int $default = 30): array
    {
        return [max(1, (int) $r->query('page', 1)), min(100, max(5, (int) $r->query('per_page', $default)))];
    }

    /* ------------------------------------------------------------------ overview */

    public function overview(Request $r)
    {
        return $this->run($r, function ($s) {
            $u = $s['universityId'];
            $this->queue->runFallbackIfStale();
            $out = ['scope' => $u === null ? 'platform' : 'university', 'university_id' => $u,
                'security' => $this->security->overview($u), 'bounces' => $this->bounces->stats($u), 'queue' => $this->queue->stats($u)];
            if ($u !== null) {
                $t = $this->tenants->tenantByUniversity($u);
                $out['policy'] = $t ? $this->security->policy($t) : null;
            }
            return $out;
        });
    }

    /* ------------------------------------------------------------------ security events */

    public function events(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            [$page, $per] = $this->page($r, 25);
            return $this->security->events($s['universityId'], $r->only(['status', 'type', 'severity']), $page, $per);
        });
    }

    public function resolveEvent(Request $r, int $id)
    {
        $in = $r->validate(['status' => 'required|in:resolved,ignored', 'note' => 'nullable|string|max:250']);
        return $this->run($r, function ($s) use ($r, $id, $in) {
            $res = $this->security->resolveEvent($s['universityId'], $id, $in['status'], $in['note'] ?? null, $s['uid']);
            $this->log($r, $s, 'mail.security.event_' . $in['status'], "Security event #{$id} marked {$in['status']}", ['target_type' => 'security_event', 'target_id' => $id]);
            return $res;
        });
    }

    /* ------------------------------------------------------------------ audit log */

    public function auditLog(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            [$page, $per] = $this->page($r);
            return $this->audit->list($s['universityId'], $r->only(['action', 'account_id', 'actor_user_id', 'from', 'to', 'q']), $page, $per);
        });
    }

    /** CSV (حد أقصى 5000 صف). الخلايا اللي بتبدأ بـ = + - @ بتتأمّن ضد Excel formula injection. */
    public function auditExport(Request $r)
    {
        $s = $this->scope($r);
        if (!$s) {
            return $this->apiError('Mail security is available to university and platform admins only.', null, 403);
        }
        $filters = $r->only(['action', 'account_id', 'actor_user_id', 'from', 'to', 'q']);
        $this->log($r, $s, 'mail.audit.exported', 'Audit log exported to CSV');
        $safe = fn ($v) => ($v = (string) $v) !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;

        return response()->streamDownload(function () use ($s, $filters, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'time_utc', 'university_id', 'actor_id', 'actor_name', 'actor_role', 'mailbox', 'action', 'target', 'summary', 'ip']);
            $sent = 0;
            for ($p = 1; $p <= 50 && $sent < 5000; $p++) {
                $res = $this->audit->list($s['universityId'], $filters, $p, 100);
                foreach ($res['items'] as $it) {
                    fputcsv($out, [$it['id'], $it['created_at'], $it['university_id'], $it['actor']['id'], $safe($it['actor']['name']), $it['actor']['role'],
                        $safe($it['account']['address'] ?? ''), $it['action'], trim(($it['target_type'] ?? '') . ' ' . ($it['target_id'] ?? '')), $safe($it['summary']), $it['ip']]);
                    $sent++;
                }
                if (count($res['items']) < 100) {
                    break;
                }
            }
            fclose($out);
        }, 'uipe-mail-audit-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /* ------------------------------------------------------------------ IP rules */

    public function ipRules(Request $r)
    {
        return $this->run($r, fn ($s) => ['rules' => $this->security->rules($s['universityId']), 'your_ip' => (string) $r->ip()]);
    }

    public function addIpRule(Request $r)
    {
        $in = $r->validate(['cidr' => 'required|string|max:50', 'action' => 'sometimes|in:block,allow', 'note' => 'nullable|string|max:190', 'expires_at' => 'nullable|date']);
        return $this->run($r, function ($s) use ($r, $in) {
            $res = $this->security->addRule($s['universityId'], $in['cidr'], $in['action'] ?? 'block', $in['note'] ?? null, $in['expires_at'] ?? null, $s['uid'], (string) $r->ip());
            $this->log($r, $s, 'mail.ip_rule.added', "IP rule added: {$res['action']} {$res['cidr']}", ['target_type' => 'ip_rule', 'target_id' => $res['id'], 'meta' => ['cidr' => $res['cidr'], 'action' => $res['action']]]);
            return $res;
        }, 201);
    }

    public function removeIpRule(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $this->security->removeRule($s['universityId'], $id, (string) $r->ip());
            $this->log($r, $s, 'mail.ip_rule.removed', "IP rule #{$id} removed", ['target_type' => 'ip_rule', 'target_id' => $id]);
            return null;
        });
    }

    /* ------------------------------------------------------------------ suppression list + bounces */

    public function suppressions(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            [$page, $per] = $this->page($r);
            return $this->security->suppressions($s['universityId'], $r->query('q') ? (string) $r->query('q') : null, $page, $per);
        });
    }

    public function addSuppression(Request $r)
    {
        $in = $r->validate(['address' => 'required|string|max:190', 'note' => 'nullable|string|max:190']);
        return $this->run($r, function ($s) use ($r, $in) {
            $this->security->addSuppression($s['universityId'], $in['address'], 'manual', $in['note'] ?? null, $s['uid']);
            $this->log($r, $s, 'mail.suppression.added', 'Address added to suppression list', ['meta' => ['address' => strtolower(trim($in['address']))]]);
            return null;
        }, 201);
    }

    public function removeSuppression(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $this->security->removeSuppression($s['universityId'], $id);
            $this->log($r, $s, 'mail.suppression.removed', "Suppression #{$id} removed", ['target_type' => 'suppression', 'target_id' => $id]);
            return null;
        });
    }

    public function bounces(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            [$page, $per] = $this->page($r);
            return $this->bounces->list($s['universityId'], $r->only(['type', 'q']), $page, $per);
        });
    }

    /* ------------------------------------------------------------------ queue */

    public function queueStats(Request $r)
    {
        return $this->run($r, fn ($s) => $this->queue->stats($s['universityId']));
    }

    public function deliveries(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            [$page, $per] = $this->page($r);
            return $this->queue->deliveries($s['universityId'], $r->only(['status']), $page, $per);
        });
    }

    public function retryDelivery(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $res = $this->queue->requeue($s['universityId'], $id);
            $this->log($r, $s, 'mail.delivery.requeued', "External delivery #{$id} requeued", ['target_type' => 'delivery', 'target_id' => $id]);
            return $res;
        });
    }

    /* ------------------------------------------------------------------ policy (limits + retention + auto-suspend) */

    public function policy(Request $r)
    {
        return $this->run($r, function ($s) {
            if ($s['universityId'] === null) {
                throw new \DomainException('Pass university_id to view a university policy.', 422);
            }
            $t = $this->tenants->tenantByUniversity($s['universityId']);
            if (!$t) {
                throw new \DomainException('Mail tenant not found.', 404);
            }
            return $this->security->policy($t);
        });
    }

    public function updatePolicy(Request $r)
    {
        $in = $r->validate([
            'retention_trash_days' => 'sometimes|nullable|integer', 'retention_spam_days' => 'sometimes|nullable|integer',
            'retention_sent_days' => 'sometimes|nullable|integer', 'retention_all_days' => 'sometimes|nullable|integer',
            'sec_max_per_hour' => 'sometimes|nullable|integer', 'sec_max_recipients_per_hour' => 'sometimes|nullable|integer',
            'sec_max_external_per_hour' => 'sometimes|nullable|integer', 'sec_auto_suspend' => 'sometimes|boolean',
        ]);
        return $this->run($r, function ($s) use ($r, $in) {
            if ($s['universityId'] === null) {
                throw new \DomainException('Pass university_id to change a university policy.', 422);
            }
            $t = $this->tenants->tenantByUniversity($s['universityId']);
            if (!$t) {
                throw new \DomainException('Mail tenant not found.', 404);
            }
            [$policy, $changes] = $this->security->updatePolicy((int) $t->id, $in);
            if ($changes) {
                $this->log($r, $s, 'mail.policy.updated', 'Security/retention policy updated: ' . implode(', ', array_keys($changes)), ['target_type' => 'tenant', 'target_id' => $t->id, 'meta' => $changes]);
            }
            return $policy;
        });
    }

    /* ------------------------------------------------------------------ mailboxes: legal hold / reinstate */

    public function flagged(Request $r)
    {
        return $this->run($r, fn ($s) => ['accounts' => $this->security->flaggedAccounts($s['universityId'])]);
    }

    public function legalHold(Request $r, int $id)
    {
        $in = $r->validate(['on' => 'required|boolean', 'reason' => 'nullable|string|max:190']);
        return $this->run($r, function ($s) use ($r, $id, $in) {
            $on = filter_var($in['on'], FILTER_VALIDATE_BOOLEAN);
            if ($on && trim((string) ($in['reason'] ?? '')) === '') {
                throw new \DomainException('A reason is required to place a legal hold.', 422);
            }
            $res = $this->security->setLegalHold($s['universityId'], $id, $on, $in['reason'] ?? null);
            $this->log($r, $s, $on ? 'mail.legal_hold.on' : 'mail.legal_hold.off', ($on ? 'Legal hold enabled' : 'Legal hold lifted') . " on mailbox #{$id}",
                ['account_id' => $id, 'target_type' => 'account', 'target_id' => $id, 'meta' => $on ? ['reason' => mb_substr((string) $in['reason'], 0, 190)] : null]);
            return $res;
        });
    }

    public function reinstate(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $res = $this->security->reinstate($s['universityId'], $id);
            $this->log($r, $s, 'mail.account.reinstated', "Mailbox #{$id} reinstated after security suspension", ['account_id' => $id, 'target_type' => 'account', 'target_id' => $id]);
            return $res;
        });
    }
}
