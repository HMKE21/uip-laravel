<?php

namespace App\Services;

use App\Events\Meetings\RemoteControlChanged;
use App\Events\Meetings\RemoteControlInput;
use App\Events\Meetings\RemoteControlRequested;
use App\Models\Meeting;
use Illuminate\Support\Facades\Cache;

/**
 * التحكم عن بُعد داخل الاجتماع (زي Zoom "Remote Control").
 *
 * القواعد:
 *  - لازم صاحب الشاشة يوافق صراحة (طلب -> موافقة)، والموافقة بتتحط في Cache مؤقتًا (6 ساعات كحد أقصى).
 *  - متحكم واحد بس في نفس الوقت لكل اجتماع، وصاحب الشاشة يقدر يوقف التحكم في أي لحظة.
 *  - التحكم بيقف تلقائيًا لو صاحب الشاشة وقّف المشاركة أو خرج، أو المتحكم خرج.
 *  - أحداث الإدخال بتعدّي على السيرفر (مش مباشرة بين المتصفحين) عشان نتأكد إن المرسل هو المتحكم المصرّح.
 */
class MeetingRemoteControlService
{
    private const TTL_MINUTES = 360;
    private const PENDING_TTL_SECONDS = 120;
    private const MAX_EVENTS_PER_BATCH = 40;

    public function __construct(private MeetingSignalingService $signaling)
    {
    }

    private function stateKey(Meeting $m): string { return 'meeting:' . $m->uuid . ':remote-control'; }
    private function pendingKey(Meeting $m, string $controllerKey): string { return 'meeting:' . $m->uuid . ':rc-pending:' . $controllerKey; }

    /** @return array{controller_key:string, target_key:string, controller_name:string}|null */
    public function current(Meeting $meeting): ?array
    {
        return Cache::get($this->stateKey($meeting));
    }

    /** @throws \InvalidArgumentException|\RuntimeException */
    public function request(Meeting $meeting, array $controller, string $targetKey): void
    {
        if ($controller['key'] === $targetKey) {
            throw new \InvalidArgumentException('You cannot request control of your own screen.');
        }
        $target = $this->signaling->resolveTargetActor($meeting, $targetKey);
        if (!($target['model']->screen_sharing ?? false)) {
            throw new \RuntimeException('This participant is not sharing their screen right now.');
        }
        if ($this->current($meeting)) {
            throw new \RuntimeException('Someone is already controlling a shared screen. Ask them to stop first.');
        }

        Cache::put($this->pendingKey($meeting, $controller['key']), $targetKey, now()->addSeconds(self::PENDING_TTL_SECONDS));
        event(new RemoteControlRequested($meeting->uuid, $controller['key'], $controller['display_name'], $targetKey));
    }

    /** صاحب الشاشة بيرد على الطلب. */
    public function respond(Meeting $meeting, array $owner, string $controllerKey, bool $allow): void
    {
        $pending = Cache::pull($this->pendingKey($meeting, $controllerKey));
        if ($pending !== $owner['key']) {
            throw new \RuntimeException('This request has expired or is not addressed to you.');
        }
        $controller = $this->signaling->resolveTargetActor($meeting, $controllerKey);

        if (!$allow) {
            event(new RemoteControlChanged($meeting->uuid, 'denied', $controllerKey, $controller['display_name'], $owner['key']));
            return;
        }
        if ($this->current($meeting)) {
            throw new \RuntimeException('Someone is already controlling a shared screen.');
        }

        Cache::put($this->stateKey($meeting), [
            'controller_key' => $controllerKey, 'controller_name' => $controller['display_name'], 'target_key' => $owner['key'],
        ], now()->addMinutes(self::TTL_MINUTES));
        event(new RemoteControlChanged($meeting->uuid, 'granted', $controllerKey, $controller['display_name'], $owner['key']));
    }

    /** بيوقفه صاحب الشاشة أو المتحكم نفسه أو الهوست. */
    public function stop(Meeting $meeting, array $actor): void
    {
        $state = $this->current($meeting);
        if (!$state) {
            return;
        }
        $isParty = in_array($actor['key'], [$state['controller_key'], $state['target_key']], true);
        if (!$isParty && !in_array($actor['role'], ['host', 'co_host'], true)) {
            throw new \RuntimeException('Only the screen owner, the controller or the host can stop remote control.');
        }
        $this->clear($meeting, $state);
    }

    /** بيتنادى لما حد يخرج أو يوقف مشاركة الشاشة. */
    public function stopIfInvolved(Meeting $meeting, string $actorKey): void
    {
        $state = $this->current($meeting);
        if ($state && in_array($actorKey, [$state['controller_key'], $state['target_key']], true)) {
            $this->clear($meeting, $state);
        }
    }

    private function clear(Meeting $meeting, array $state): void
    {
        Cache::forget($this->stateKey($meeting));
        event(new RemoteControlChanged($meeting->uuid, 'stopped', $state['controller_key'], $state['controller_name'], $state['target_key']));
    }

    /** @param array<int,array<string,mixed>> $events */
    public function relayInput(Meeting $meeting, array $controller, array $events): void
    {
        $state = $this->current($meeting);
        if (!$state || $state['controller_key'] !== $controller['key']) {
            throw new \RuntimeException('You are not allowed to control this screen.');
        }
        $clean = [];
        foreach (array_slice($events, 0, self::MAX_EVENTS_PER_BATCH) as $e) {
            $type = $e['type'] ?? null;
            if (in_array($type, ['move', 'down', 'up', 'click', 'dblclick', 'contextmenu'], true)) {
                $clean[] = [
                    'type' => $type,
                    'x' => max(0, min(1, (float) ($e['x'] ?? 0))),
                    'y' => max(0, min(1, (float) ($e['y'] ?? 0))),
                    'button' => (int) ($e['button'] ?? 0),
                ];
            } elseif ($type === 'wheel') {
                $clean[] = [
                    'type' => 'wheel',
                    'x' => max(0, min(1, (float) ($e['x'] ?? 0))),
                    'y' => max(0, min(1, (float) ($e['y'] ?? 0))),
                    'dx' => max(-2000, min(2000, (float) ($e['dx'] ?? 0))),
                    'dy' => max(-2000, min(2000, (float) ($e['dy'] ?? 0))),
                ];
            } elseif (in_array($type, ['keydown', 'keyup'], true)) {
                $clean[] = [
                    'type' => $type,
                    'key' => mb_substr((string) ($e['key'] ?? ''), 0, 24),
                    'code' => mb_substr((string) ($e['code'] ?? ''), 0, 24),
                    'ctrl' => (bool) ($e['ctrl'] ?? false), 'shift' => (bool) ($e['shift'] ?? false),
                    'alt' => (bool) ($e['alt'] ?? false), 'meta' => (bool) ($e['meta'] ?? false),
                ];
            }
        }
        if ($clean) {
            event(new RemoteControlInput($meeting->uuid, $controller['key'], $state['target_key'], $clean));
        }
    }
}
