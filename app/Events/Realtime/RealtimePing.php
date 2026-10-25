<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Something changed, look now" — a tiny push to a person's private channel
 * (App.Models.User.{id}, already authorised in routes/channels.php).
 *
 * ShouldBroadcastNow on purpose: no queue worker in between, so the push
 * leaves in the same request cycle (the middleware sends it AFTER the HTTP
 * response is out, so the writer never waits for it).
 *
 * The payload is small: topic + conversation/node id (and, for a new notification, its short title/body — the channel is private to the owner).
 * For chat and My PC the browser then fetches the real data through the normal,
 * permission-checked API — so message bodies and files never travel over the socket.
 */
class RealtimePing implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /** @param int[] $userIds */
    public function __construct(
        public array $userIds,
        public string $topic,
        public array $data = []
    ) {
    }

    /** @return array<int,PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('App.Models.User.' . $id),
            array_values(array_unique(array_map('intval', $this->userIds)))
        );
    }

    public function broadcastAs(): string
    {
        return 'rt';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['topic' => $this->topic, 'data' => $this->data, 't' => (int) (microtime(true) * 1000)];
    }
}
