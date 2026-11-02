<?php

namespace App\Services;

use App\Events\Meetings\MeetingTransportChanged;
use App\Models\Meeting;
use Illuminate\Support\Facades\Cache;

/**
 * قرار "الغرفة دي تشتغل mesh ولا SFU" + سجل جلسات/ترَكات الـ SFU لكل اجتماع.
 *
 * القرار على مستوى الغرفة كلها (مش كل مشارك لوحده)، عشان الكل يبعت ويستقبل من نفس المكان. الأولوية:
 *   1) SFU مقفول/مش متاح/متحظور (فشل عند ناس كتير)  → mesh
 *   2) force=sfu|mesh من الإعدادات                    → زي ما هو
 *   3) العدد ≥ min_participants أو > maxMeshParticipants → sfu
 *   4) العدد ≥ 3 وجودة الـ mesh ضعيفة عند ≥40% من المشاركين وSFU سليم → sfu (الأحسن جودة)
 *   5) غير كده → mesh (أرخص وأقل تأخير في الغرف الصغيرة)
 * Hysteresis: بعد ما نتحول SFU منرجعش mesh إلا لما العدد ينزل لـ 2 أو أقل، وبعد min_dwell_seconds على الأقل.
 * decide() دالة نقية (من غير cache/DB) عشان تتختبر لوحدها.
 */
class MeetingTransportService
{
    private const TTL = 21600; // 6 ساعات
    private const FRESH_SECONDS = 30; // تقرير أقدم من كده مايتحسبش

    public function __construct(private CloudflareSfuService $sfu, private MeetingPolicyService $policy)
    {
    }

    // ------------------------------------------------------------------ policy

    /**
     * @param array{current:string,changed_at:int,now:int,count:int,sfu_available:bool,blocked:bool,force:string,min_participants:int,max_mesh:int,min_dwell:int,mesh_poor_ratio:float,sfu_sample_ok:bool} $in
     * @return array{mode:string,reason:string}
     */
    public static function decide(array $in): array
    {
        $current = $in['current'] === 'sfu' ? 'sfu' : 'mesh';

        if (!$in['sfu_available']) {
            return ['mode' => 'mesh', 'reason' => 'sfu_unavailable'];
        }
        if ($in['blocked']) {
            return ['mode' => 'mesh', 'reason' => 'sfu_blocked'];
        }
        if ($in['force'] === 'mesh') {
            return ['mode' => 'mesh', 'reason' => 'forced_mesh'];
        }
        if ($in['force'] === 'sfu') {
            return ['mode' => 'sfu', 'reason' => 'forced_sfu'];
        }

        $count = $in['count'];
        $threshold = max(3, min($in['min_participants'], $in['max_mesh'] + 1));
        $wantSfu = null;
        $reason = '';

        if ($count >= $threshold || $count > $in['max_mesh']) {
            $wantSfu = true; $reason = 'size';
        } elseif ($count >= 3 && $in['mesh_poor_ratio'] >= 0.4 && $in['sfu_sample_ok']) {
            $wantSfu = true; $reason = 'quality';
        } else {
            $wantSfu = false; $reason = 'small_room';
        }

        // hysteresis: SFU لازم يفضل لحد ما الغرفة تبقى 1:1 (أو فاضية)
        if ($current === 'sfu' && !$wantSfu) {
            if ($count > 2) {
                return ['mode' => 'sfu', 'reason' => 'sticky'];
            }
            $reason = 'shrunk';
        }

        $mode = $wantSfu ? 'sfu' : 'mesh';
        if ($mode !== $current && ($in['now'] - $in['changed_at']) < $in['min_dwell']) {
            return ['mode' => $current, 'reason' => 'dwell']; // لسه مش وقت التحويل
        }

        return ['mode' => $mode, 'reason' => $reason];
    }

    // ------------------------------------------------------------------ state

    /** @return array<string,mixed> */
    private function state(Meeting $meeting): array
    {
        $s = Cache::get($this->key($meeting, 'state'));

        return is_array($s) ? $s : ['mode' => 'mesh', 'epoch' => 0, 'reason' => 'init', 'changed_at' => 0, 'blocked_until' => 0, 'reports' => []];
    }

    private function save(Meeting $meeting, array $state): void
    {
        Cache::put($this->key($meeting, 'state'), $state, self::TTL);
    }

    private function key(Meeting $meeting, string $part): string
    {
        return 'meeting_sfu:' . $meeting->id . ':' . $part;
    }

    /** @return array{mode:string,epoch:int,reason:string} */
    public function current(Meeting $meeting): array
    {
        $s = $this->state($meeting);

        return ['mode' => (string) $s['mode'], 'epoch' => (int) $s['epoch'], 'reason' => (string) $s['reason']];
    }

    /**
     * تقرير دوري من كل عميل: ['transport'=>mesh|sfu,'quality'=>..., 'sfu_ok'=>bool|null, 'sfu_rtt_ms'=>int|null, 'sfu_failed'=>bool]
     * وبعدها نعيد تقييم القرار (متهرّب بحد أقصى مرة كل 3 ثواني للاجتماع).
     * @param array<string,mixed> $report
     * @return array{mode:string,epoch:int,reason:string}
     */
    public function report(Meeting $meeting, array $actor, array $report, int $presentCount): array
    {
        $this->locked($meeting, function () use ($meeting, $actor, $report) {
            $s = $this->state($meeting);
            $s['reports'][$actor['key']] = [
                'at'         => time(),
                'transport'  => ($report['transport'] ?? 'mesh') === 'sfu' ? 'sfu' : 'mesh',
                'quality'    => (string) ($report['quality'] ?? 'good'),
                'sfu_ok'     => array_key_exists('sfu_ok', $report) ? (bool) $report['sfu_ok'] : null,
                'sfu_rtt_ms' => isset($report['sfu_rtt_ms']) ? (int) $report['sfu_rtt_ms'] : null,
                'sfu_failed' => !empty($report['sfu_failed']),
            ];
            $this->save($meeting, $s);
        });

        return $this->evaluate($meeting, $presentCount, false);
    }

    /** يقيّم ويحدّث الحالة ويبثّ حدث لو الوضع اتغيّر. */
    public function evaluate(Meeting $meeting, int $presentCount, bool $force = true): array
    {
        if (!$force && !Cache::add($this->key($meeting, 'eval'), 1, 3)) {
            return $this->current($meeting);
        }

        $changed = null;
        $this->locked($meeting, function () use ($meeting, $presentCount, &$changed) {
            $s = $this->state($meeting);
            $now = time();
            $fresh = array_filter((array) $s['reports'], fn ($r) => ($now - (int) ($r['at'] ?? 0)) <= self::FRESH_SECONDS);
            $s['reports'] = $fresh; // نمسح القديم
            $n = max(1, count($fresh));

            $maxMesh = max(2, $this->policy->maxMeshParticipants());
            $failed = count(array_filter($fresh, fn ($r) => !empty($r['sfu_failed'])));
            $poor = count(array_filter($fresh, fn ($r) => ($r['transport'] ?? 'mesh') === 'mesh' && in_array($r['quality'] ?? 'good', ['poor', 'reconnecting'], true)));
            $meshN = max(1, count(array_filter($fresh, fn ($r) => ($r['transport'] ?? 'mesh') === 'mesh')));
            $probed = array_filter($fresh, fn ($r) => $r['sfu_ok'] !== null);
            $sfuOk = count(array_filter($probed, fn ($r) => $r['sfu_ok'] === true));
            $sfuSampleOk = $probed ? ($sfuOk / count($probed)) >= 0.6 : true;

            // فشل SFU: غرفة صغيرة (mesh ينفع) → فشل واحد يكفي. غرفة كبيرة → لازم ≥30% (وإلا العميل الواحد يعيد المحاولة بس).
            $blockNow = $presentCount <= $maxMesh ? $failed >= 1 : ($failed / $n) >= 0.3;
            if ($blockNow) {
                $s['blocked_until'] = $now + MeetingAdminSettings::sfu()['block_seconds'];
            }

            $cfg = MeetingAdminSettings::sfu();
            $d = self::decide([
                'current'          => (string) $s['mode'],
                'changed_at'       => (int) $s['changed_at'],
                'now'              => $now,
                'count'            => $presentCount,
                'sfu_available'    => $this->sfu->enabled(),
                'blocked'          => $now < (int) $s['blocked_until'],
                'force'            => $cfg['mode'],
                'min_participants' => $cfg['min_participants'],
                'max_mesh'         => $maxMesh,
                'min_dwell'        => $blockNow ? 0 : $cfg['min_dwell_seconds'],
                'mesh_poor_ratio'  => $poor / $meshN,
                'sfu_sample_ok'    => $sfuSampleOk,
            ]);

            if ($d['mode'] !== $s['mode']) {
                $s['mode'] = $d['mode'];
                $s['epoch'] = (int) $s['epoch'] + 1;
                $s['changed_at'] = $now;
                $s['reason'] = $d['reason'];
                $changed = ['mode' => $s['mode'], 'epoch' => $s['epoch'], 'reason' => $s['reason']];
            }
            $this->save($meeting, $s);
        });

        if ($changed) {
            try {
                broadcast(new MeetingTransportChanged($meeting->uuid, $changed['mode'], $changed['epoch'], $changed['reason']));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Meetings: transport broadcast failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->current($meeting);
    }

    // ------------------------------------------------------------------ sessions / tracks registry

    /** @return array<string,array{pub:?string,subs:array<int,string>,tracks:array<int,array{trackName:string,kind:string}>}> */
    private function registry(Meeting $meeting): array
    {
        $r = Cache::get($this->key($meeting, 'registry'));

        return is_array($r) ? $r : [];
    }

    public function registerSession(Meeting $meeting, string $actorKey, string $sessionId, string $role): void
    {
        $this->locked($meeting, function () use ($meeting, $actorKey, $sessionId, $role) {
            $r = $this->registry($meeting);
            $e = $r[$actorKey] ?? ['pub' => null, 'subs' => [], 'tracks' => []];
            if ($role === 'pub') {
                $e['pub'] = $sessionId;
                $e['tracks'] = []; // جلسة نشر جديدة = ترَكات قديمة ماتنفعش
            } else {
                $e['subs'] = array_values(array_unique(array_merge($e['subs'], [$sessionId])));
            }
            $r[$actorKey] = $e;
            Cache::put($this->key($meeting, 'registry'), $r, self::TTL);
        });
    }

    public function ownsSession(Meeting $meeting, string $actorKey, string $sessionId): bool
    {
        $e = $this->registry($meeting)[$actorKey] ?? null;

        return $e && ($e['pub'] === $sessionId || in_array($sessionId, $e['subs'], true));
    }

    public function isPublisherSession(Meeting $meeting, string $actorKey, string $sessionId): bool
    {
        $e = $this->registry($meeting)[$actorKey] ?? null;

        return $e && $e['pub'] === $sessionId;
    }

    /** هل sessionId ده جلسة نشر لحد تاني في نفس الاجتماع؟ (يمنع سحب ترَكات من اجتماع تاني). */
    public function isOtherPublisherInMeeting(Meeting $meeting, string $actorKey, string $sessionId): bool
    {
        foreach ($this->registry($meeting) as $k => $e) {
            if ($k !== $actorKey && ($e['pub'] ?? null) === $sessionId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array{trackName:string,kind:string}> $tracks */
    public function recordTracks(Meeting $meeting, string $actorKey, array $tracks): void
    {
        $this->locked($meeting, function () use ($meeting, $actorKey, $tracks) {
            $r = $this->registry($meeting);
            if (!isset($r[$actorKey])) {
                return;
            }
            $byName = [];
            foreach (array_merge($r[$actorKey]['tracks'], $tracks) as $t) {
                $byName[$t['trackName']] = $t;
            }
            $r[$actorKey]['tracks'] = array_values($byName);
            Cache::put($this->key($meeting, 'registry'), $r, self::TTL);
        });
    }

    public function removeActor(Meeting $meeting, string $actorKey): void
    {
        $this->locked($meeting, function () use ($meeting, $actorKey) {
            $r = $this->registry($meeting);
            unset($r[$actorKey]);
            Cache::put($this->key($meeting, 'registry'), $r, self::TTL);
        });
    }

    /** اللي لسه ناشر فعلًا: [{key, session_id, tracks:[{trackName,kind}]}] */
    public function peers(Meeting $meeting): array
    {
        $out = [];
        foreach ($this->registry($meeting) as $k => $e) {
            if (!empty($e['pub']) && !empty($e['tracks'])) {
                $out[] = ['key' => $k, 'session_id' => $e['pub'], 'tracks' => $e['tracks']];
            }
        }

        return $out;
    }

    private function locked(Meeting $meeting, callable $fn): void
    {
        try {
            Cache::lock($this->key($meeting, 'lock'), 5)->block(3, $fn);
        } catch (\Throwable $e) {
            // cache driver من غير locks أو timeout: ننفّذ من غير قفل (أسوأ حالة سباق نادر على تقرير واحد)
            $fn();
        }
    }
}
