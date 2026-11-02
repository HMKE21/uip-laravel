<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * الغرفة اتحوّلت بين mesh وSFU (MeetingTransportService). كل العملاء بيسمعوه ويعملوا make-before-break:
 * يفتحوا الناقل الجديد الأول، وبعد ما الصوت/الصورة توصل يقفلوا القديم.
 * epoch بيزيد مع كل تحويل — العميل بيتجاهل أي حدث epoch بتاعه أقدم من اللي عنده.
 */
class MeetingTransportChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $meetingUuid,
        public string $mode,
        public int $epoch,
        public string $reason
    ) {
    }

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('meeting.' . $this->meetingUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.transport.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return ['mode' => $this->mode, 'epoch' => $this->epoch, 'reason' => $this->reason];
    }
}
