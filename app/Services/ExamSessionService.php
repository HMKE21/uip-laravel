<?php

namespace App\Services;

use App\Helpers\UserAgentParser;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * جلسة واحدة للطالب على المحاولة (single active session).
 *
 * كل جهاز بيفتح المحاولة بياخد token عشوائي (header X-Exam-Session)، والسيرفر بيخزّن hash بس.
 * أي request تاني على المحاولة لازم يشيل الـ token الصح، وإلا 409.
 *
 *  - مفيش جلسة، أو آخر heartbeat أقدم من LIVE_TTL_SECONDS => الجهاز الجديد ياخد الجلسة بدون مشاكل.
 *  - نفس device_id (refresh/تاب جديد)                        => إعادة إصدار token بدون مشاكل.
 *  - جهاز مختلف والجلسة حية                                  => session_conflict (ومفيش أسئلة بتتبعت).
 *  - takeover صريح: بياخد الجلسة، القديم بيتحجب، والحدث بيتسجل مخالفة.
 *
 * حدود صريحة: device_id بييجي من العميل فمش دليل قاطع، والـ IP ممكن يتغير. القيمة الفعلية: منع القراءة/الحفظ
 * المتزامن من جهازين + سجل واضح للمدرس.
 */
class ExamSessionService
{
    public const LIVE_TTL_SECONDS = 90;
    public const TOUCH_THROTTLE_SECONDS = 15;

    public function __construct(
        private ExamSecurityService $security
    ) {
    }

    public function enforced(Exam $exam): bool
    {
        return (bool) $exam->single_session_enabled;
    }

    /**
     * @param array{ip?:?string,user_agent?:?string,device_id?:?string} $ctx
     * @return array{status:string, token:?string, takeover:bool, event:?string}  status: ok | conflict
     */
    public function claim(ExamAttempt $attempt, array $ctx, bool $takeover = false): array
    {
        $ctx = $this->normalizeContext($ctx);

        $result = DB::transaction(function () use ($attempt, $ctx, $takeover) {
            /** @var ExamAttempt $locked */
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $exam = $locked->exam ?? $locked->exam()->first();
            $now = now();

            if (!$this->enforced($exam)) {
                $locked->session_ip = $ctx['ip'];
                $locked->session_user_agent = $ctx['user_agent'];
                $locked->session_device_id = $ctx['device_id'];
                $locked->session_last_seen_at = $now;
                $locked->save();
                return ['status' => 'ok', 'token' => null, 'takeover' => false, 'event' => null];
            }

            $hasSession = $locked->session_token_hash !== null;
            $live = $hasSession
                && $locked->session_last_seen_at !== null
                && $locked->session_last_seen_at->greaterThan($now->copy()->subSeconds(self::LIVE_TTL_SECONDS));
            $sameDevice = $ctx['device_id'] !== null
                && $locked->session_device_id !== null
                && hash_equals($locked->session_device_id, $ctx['device_id']);

            $event = null;
            if (!$hasSession) {
                $event = 'claim';
            } elseif (!$live || $sameDevice) {
                $event = 'resume';
            } elseif ($takeover) {
                $event = 'takeover';
            }

            if ($event === null) {
                $this->logBlocked($locked, $ctx);
                return ['status' => 'conflict', 'token' => null, 'takeover' => false, 'event' => 'blocked'];
            }

            $token = Str::random(48);
            $locked->session_token_hash = hash('sha256', $token);
            $locked->session_last_seen_at = $now;
            $locked->session_ip = $ctx['ip'];
            $locked->session_user_agent = $ctx['user_agent'];
            $locked->session_device_id = $ctx['device_id'];
            $locked->session_claims_count = (int) $locked->session_claims_count + 1;
            $locked->save();

            ExamAttemptSession::create([
                'exam_attempt_id' => $locked->id,
                'event'           => $event,
                'ip'              => $ctx['ip'],
                'user_agent'      => $ctx['user_agent'],
                'device_id'       => $ctx['device_id'],
                'device_label'    => $this->deviceLabel($ctx['user_agent']),
                'created_at'      => $now,
            ]);

            return ['status' => 'ok', 'token' => $token, 'takeover' => $event === 'takeover', 'event' => $event];
        });

        $attempt->refresh();
        return $result;
    }

    /** @return string ok | missing | replaced */
    public function verify(ExamAttempt $attempt, ?string $token): string
    {
        $exam = $attempt->exam ?? $attempt->exam()->first();
        if (!$exam || !$this->enforced($exam)) {
            return 'ok';
        }
        // محاولة اتبدأت قبل ما الميزة تتفعّل — مانحجبهاش، أول claim هيبدأ الحماية.
        if ($attempt->session_token_hash === null) {
            return 'ok';
        }
        if ($token === null || $token === '') {
            return 'missing';
        }
        if (!hash_equals($attempt->session_token_hash, hash('sha256', $token))) {
            return 'replaced';
        }

        $seen = $attempt->session_last_seen_at;
        if ($seen === null || $seen->lessThan(now()->subSeconds(self::TOUCH_THROTTLE_SECONDS))) {
            $now = now();
            ExamAttempt::whereKey($attempt->id)->update(['session_last_seen_at' => $now]);
            $attempt->session_last_seen_at = $now;
        }

        return 'ok';
    }

    public function logForAttempt(ExamAttempt $attempt, int $limit = 50): array
    {
        return ExamAttemptSession::where('exam_attempt_id', $attempt->id)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ExamAttemptSession $s) => [
                'event'        => $s->event,
                'ip'           => $s->ip,
                'device_label' => $s->device_label,
                'user_agent'   => $s->user_agent,
                'device_id'    => $s->device_id !== null ? substr($s->device_id, 0, 8) : null,
                'at'           => $s->created_at,
            ])->all();
    }

    private function logBlocked(ExamAttempt $attempt, array $ctx): void
    {
        // مرة كل دقيقة لكل جهاز بس عشان السجل ما يتملاش.
        $recent = ExamAttemptSession::where('exam_attempt_id', $attempt->id)
            ->where('event', 'blocked')
            ->where('device_id', $ctx['device_id'])
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
        if ($recent) {
            return;
        }

        ExamAttemptSession::create([
            'exam_attempt_id' => $attempt->id,
            'event'           => 'blocked',
            'ip'              => $ctx['ip'],
            'user_agent'      => $ctx['user_agent'],
            'device_id'       => $ctx['device_id'],
            'device_label'    => $this->deviceLabel($ctx['user_agent']),
            'created_at'      => now(),
        ]);
        $this->security->logSystemEvent($attempt, 'session_conflict', [
            'ip'           => $ctx['ip'],
            'device_label' => $this->deviceLabel($ctx['user_agent']),
        ]);
    }

    private function normalizeContext(array $ctx): array
    {
        $device = isset($ctx['device_id']) ? preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $ctx['device_id']) : null;
        return [
            'ip'         => isset($ctx['ip']) ? Str::limit((string) $ctx['ip'], 45, '') : null,
            'user_agent' => isset($ctx['user_agent']) ? Str::limit((string) $ctx['user_agent'], 255, '') : null,
            'device_id'  => ($device !== null && $device !== '') ? substr($device, 0, 64) : null,
        ];
    }

    private function deviceLabel(?string $ua): string
    {
        $p = UserAgentParser::parse($ua);
        return $p['browser'] . ' / ' . $p['os'] . ' (' . $p['device'] . ')';
    }
}
