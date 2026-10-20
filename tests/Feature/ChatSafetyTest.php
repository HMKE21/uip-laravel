<?php

namespace Tests\Feature;

use App\Services\ChatSafetyService;
use App\Services\MessagingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الشات: حظر المستخدمين + بلاغات الرسائل (ChatSafetyService + hooks في MessagingService).
 * ⚠️ كُتب من غير ما يتشغّل (البيئة اللي اتكتب فيها مفيهاش PHP 8.4/vendor) — شغّله عندك قبل الاعتماد عليه.
 */
class ChatSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private ChatSafetyService $safety;
    private MessagingService $messaging;
    private int $universityId;
    private int $universityUserId;
    private int $conversationId;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->safety = app(ChatSafetyService::class);
        $this->messaging = app(MessagingService::class);

        $this->universityUserId = $this->makeUser('uni');
        $this->universityId = $this->makeUniversity($this->universityUserId, 'Test University');

        $this->alice = $this->makeUser('alice');
        $this->bob = $this->makeUser('bob');
        foreach ([$this->alice, $this->bob] as $uid) {
            DB::table('students')->insert(['user_id' => $uid, 'university_id' => $this->universityId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $conversation = DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        $this->conversationId = (int) $conversation;
        foreach ([$this->alice, $this->bob] as $uid) {
            DB::table('conversation_participants')->insert(['conversation_id' => $this->conversationId, 'user_id' => $uid, 'joined_at' => now()]);
        }
    }

    /** يشتغل على الجدول الحقيقي (official_name_*) وعلى جدول الـ test-support (name). */
    private function makeUniversity(int $userId, string $name): int
    {
        $row = ['user_id' => $userId];
        if (Schema::hasColumn('universities', 'official_name_en')) {
            $row += ['official_name_en' => $name, 'official_name_ar' => $name];
        } else {
            $row += ['name' => $name];
        }
        return (int) DB::table('universities')->insertGetId($row);
    }

    private function makeUser(string $tag): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => ucfirst($tag), 'email' => $tag . '_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function blocking_stops_messages_in_both_directions_and_unblock_restores_them(): void
    {
        $this->safety->block($this->alice, $this->bob);

        try {
            $this->messaging->sendMessage($this->conversationId, $this->alice, 'hi');
            $this->fail('Blocker should not be able to send.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('blocked', strtolower($e->getMessage()));
        }
        try {
            $this->messaging->sendMessage($this->conversationId, $this->bob, 'hello?');
            $this->fail('Blocked user should not be able to send.');
        } catch (\RuntimeException $e) {
            // generic wording — the blocked person is not told they were blocked
            $this->assertStringNotContainsString('blocked', strtolower($e->getMessage()));
        }

        $thread = $this->messaging->threadForUser($this->conversationId, $this->alice);
        $this->assertFalse($thread['conversation']['can_send']);
        $this->assertSame('blocked_by_me', $thread['conversation']['send_block']);
        $this->assertSame('blocked_by_peer', $this->messaging->threadForUser($this->conversationId, $this->bob)['conversation']['send_block']);

        $this->assertCount(1, $this->safety->blockedList($this->alice));
        $this->safety->unblock($this->alice, $this->bob);
        $this->assertCount(0, $this->safety->blockedList($this->alice));
        $this->assertNotEmpty($this->messaging->sendMessage($this->conversationId, $this->bob, 'back again'));
    }

    #[Test]
    public function cannot_block_yourself(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->safety->block($this->alice, $this->alice);
    }

    #[Test]
    public function reporting_a_message_is_visible_to_the_university_and_not_duplicated(): void
    {
        $sent = $this->messaging->sendMessage($this->conversationId, $this->bob, 'bad words');

        $result = $this->safety->report($this->alice, ['message_id' => $sent['id'], 'reason' => 'harassment', 'also_block' => true]);
        $this->assertTrue($result['blocked']);
        $this->assertTrue($this->safety->hasBlocked($this->alice, $this->bob));

        $report = DB::table('chat_reports')->where('id', $result['id'])->first();
        $this->assertSame($this->bob, (int) $report->reported_user_id);
        $this->assertSame($this->universityId, (int) $report->university_id);
        $this->assertSame('bad words', $report->message_snapshot);

        $queue = $this->safety->reportsFor('university', $this->universityUserId);
        $this->assertCount(1, $queue['items']);
        $this->assertSame(1, $queue['counts']['pending']);

        $this->expectException(\RuntimeException::class);
        $this->safety->report($this->alice, ['message_id' => $sent['id'], 'reason' => 'spam']);
    }

    #[Test]
    public function other_roles_cannot_open_the_queue_and_university_cannot_see_foreign_reports(): void
    {
        $sent = $this->messaging->sendMessage($this->conversationId, $this->bob, 'x');
        $this->safety->report($this->alice, ['message_id' => $sent['id'], 'reason' => 'spam']);

        $otherUniUser = $this->makeUser('other');
        $this->makeUniversity($otherUniUser, 'Other University');
        $this->assertCount(0, $this->safety->reportsFor('university', $otherUniUser)['items']);

        $this->expectException(\RuntimeException::class);
        $this->safety->reportsFor('student', $this->alice);
    }

    #[Test]
    public function closing_a_report_records_the_reviewer_and_notifies_the_reporter(): void
    {
        $sent = $this->messaging->sendMessage($this->conversationId, $this->bob, 'x');
        $id = $this->safety->report($this->alice, ['message_id' => $sent['id'], 'reason' => 'spam'])['id'];

        $this->safety->updateStatus('university', $this->universityUserId, $id, 'resolved', 'handled', null, $this->messaging);

        $row = DB::table('chat_reports')->where('id', $id)->first();
        $this->assertSame('resolved', $row->status);
        $this->assertSame($this->universityUserId, (int) $row->reviewed_by);
        $this->assertTrue(DB::table('notifications')->where('user_id', $this->alice)->where('type', 'chat_report_update')->exists());
    }
}
