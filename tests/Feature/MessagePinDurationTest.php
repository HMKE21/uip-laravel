<?php

namespace Tests\Feature;

use App\Services\MessagingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مدة تثبيت الرسالة (24h / 7d / 30d / forever). ⚠️ كُتب من غير تشغيل (مفيش vendor في بيئة الكتابة) — شغّله عندك.
 */
class MessagePinDurationTest extends TestCase
{
    use DatabaseTransactions;

    private MessagingService $messaging;
    private int $alice;
    private int $conv;
    private int $msg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messaging = app(MessagingService::class);
        $this->alice = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Alice', 'email' => 'a_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->conv = (int) DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('conversation_participants')->insert(['conversation_id' => $this->conv, 'user_id' => $this->alice, 'joined_at' => now()]);
        $this->msg = (int) DB::table('messages')->insertGetId([
            'conversation_id' => $this->conv, 'sender_id' => $this->alice, 'body' => 'hello', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function forever_pin_has_no_expiry_and_timed_pin_expires(): void
    {
        $this->messaging->setPinned($this->msg, $this->alice, true, 'forever');
        $this->assertNull(DB::table('messages')->where('id', $this->msg)->value('pinned_until'));
        $this->assertCount(1, $this->messaging->pinnedMessages($this->conv, $this->alice));

        $this->messaging->setPinned($this->msg, $this->alice, true, '7d');
        $until = strtotime((string) DB::table('messages')->where('id', $this->msg)->value('pinned_until'));
        $this->assertEqualsWithDelta(time() + 7 * 86400, $until, 5);
        $this->assertCount(1, $this->messaging->pinnedMessages($this->conv, $this->alice));

        DB::table('messages')->where('id', $this->msg)->update(['pinned_until' => now()->subMinute()]);
        $this->assertCount(0, $this->messaging->pinnedMessages($this->conv, $this->alice));
    }

    #[Test]
    public function unpin_clears_the_pin_and_invalid_duration_is_rejected(): void
    {
        $this->messaging->setPinned($this->msg, $this->alice, true, '24h');
        $this->messaging->setPinned($this->msg, $this->alice, false);
        $this->assertSame(0, (int) DB::table('messages')->where('id', $this->msg)->value('is_pinned'));
        $this->assertNull(DB::table('messages')->where('id', $this->msg)->value('pinned_until'));

        $this->expectException(\InvalidArgumentException::class);
        $this->messaging->setPinned($this->msg, $this->alice, true, '5y');
    }
}
