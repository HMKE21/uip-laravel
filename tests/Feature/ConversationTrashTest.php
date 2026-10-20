<?php

namespace Tests\Feature;

use App\Services\ConversationTrashService;
use App\Services\MessagingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سلة محذوفات المحادثات (30 يوم). ⚠️ كُتب من غير تشغيل (مفيش vendor/PHP 8.4 في بيئة الكتابة) — شغّله عندك.
 */
class ConversationTrashTest extends TestCase
{
    use DatabaseTransactions;

    private ConversationTrashService $trash;
    private MessagingService $messaging;
    private int $alice;
    private int $bob;
    private array $convs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->trash = app(ConversationTrashService::class);
        $this->messaging = app(MessagingService::class);
        $this->alice = $this->makeUser('alice');
        $this->bob = $this->makeUser('bob');
        for ($i = 0; $i < 3; $i++) {
            $id = (int) DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            foreach ([$this->alice, $this->bob] as $uid) {
                DB::table('conversation_participants')->insert(['conversation_id' => $id, 'user_id' => $uid, 'joined_at' => now()]);
            }
            $this->convs[] = $id;
        }
    }

    private function makeUser(string $tag): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => ucfirst($tag), 'email' => $tag . '_' . uniqid() . '@test.local',
            'password_hash' => bcrypt('secret'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function inboxIds(int $uid, bool $trashed = false): array
    {
        return array_column($this->messaging->inboxForUser($uid, $trashed), 'id');
    }

    #[Test]
    public function delete_moves_selected_chats_to_trash_for_that_user_only_and_restore_brings_them_back(): void
    {
        $this->assertSame(2, $this->trash->delete($this->alice, [$this->convs[0], $this->convs[1]]));

        $this->assertSame([$this->convs[2]], $this->inboxIds($this->alice));
        $this->assertEqualsCanonicalizing([$this->convs[0], $this->convs[1]], $this->inboxIds($this->alice, true));
        $this->assertCount(3, $this->inboxIds($this->bob)); // الطرف التاني مش متأثر

        $this->trash->restore($this->alice, [$this->convs[0]]);
        $this->assertEqualsCanonicalizing([$this->convs[0], $this->convs[2]], $this->inboxIds($this->alice));
    }

    #[Test]
    public function delete_all_and_empty_trash_remove_everything_permanently_for_the_user(): void
    {
        $this->assertSame(3, $this->trash->delete($this->alice, [], true));
        $this->assertSame([], $this->inboxIds($this->alice));

        $this->assertSame(3, $this->trash->purge($this->alice, [], true));
        $this->assertSame([], $this->inboxIds($this->alice, true));
        $this->assertCount(3, $this->inboxIds($this->bob));

        // آخر عضو بيحذف نهائيًا → المحادثة نفسها بتتمسح
        $this->trash->delete($this->bob, [$this->convs[0]]);
        $this->trash->purge($this->bob, [$this->convs[0]]);
        $this->assertFalse(DB::table('conversations')->where('id', $this->convs[0])->exists());
    }

    #[Test]
    public function purge_only_touches_trashed_chats_and_expired_ones_are_swept(): void
    {
        $this->assertSame(0, $this->trash->purge($this->alice, [$this->convs[2]])); // مش في السلة

        $this->trash->delete($this->alice, [$this->convs[1]]);
        DB::table('conversation_participants')->where('conversation_id', $this->convs[1])->where('user_id', $this->alice)
            ->update(['deleted_at' => now()->subDays(ConversationTrashService::RETENTION_DAYS + 1)]);

        $this->assertGreaterThanOrEqual(1, $this->trash->purgeExpired());
        $this->assertSame([], $this->inboxIds($this->alice, true));
    }

    #[Test]
    public function a_new_message_brings_a_trashed_chat_back(): void
    {
        $this->trash->delete($this->alice, [$this->convs[0]]);
        $this->messaging->sendMessage($this->convs[0], $this->bob, 'hello');
        $this->assertContains($this->convs[0], $this->inboxIds($this->alice));
    }

    #[Test]
    public function days_left_counts_down_from_thirty(): void
    {
        $this->assertSame(30, ConversationTrashService::daysLeft(now()->toDateTimeString()));
        $this->assertSame(1, ConversationTrashService::daysLeft(now()->subDays(29)->toDateTimeString()));
        $this->assertSame(0, ConversationTrashService::daysLeft(now()->subDays(31)->toDateTimeString()));
    }
}
