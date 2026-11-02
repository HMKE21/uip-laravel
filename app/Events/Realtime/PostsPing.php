<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "منشور اتغيّر" على قناة مشتركة لكل المسجّلين (posts.feed) — للمنشورات العامة بس.
 * الحمولة صغيرة ومفيهاش أي محتوى ولا أعداد (الكاتب ممكن يخفي العدّادات): id + نوع التغيير بس،
 * والمتصفح بعدها بيجيب البيانات الفعلية من الـ API العادي المحمي بصلاحيات الرؤية.
 */
class PostsPing implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public array $data = [])
    {
    }

    /** @return array<int,PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('posts.feed')];
    }

    public function broadcastAs(): string
    {
        return 'posts';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return $this->data + ['t' => (int) (microtime(true) * 1000)];
    }
}
