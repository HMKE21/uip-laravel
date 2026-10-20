<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Services\ExamSystemService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لـ Question Versioning (exam_question_versions):
 *
 *  - كل استدعاء لـ ExamSystemService::updateQuestion() بيعمل snapshot
 *    لحالة السؤال *قبل* التعديل — مش بعده.
 *  - الأرقام بتزيد (1, 2, 3...) وكل نسخة بتتحفظ لوحدها، مفيش استبدال
 *    لآخر نسخة (المطلوب صراحة: "سجل نسخة قديمة" مش "آخر حالة بس").
 *  - MCQ: الخيارات (option_text + is_correct) بتتحفظ كجزء من الـ
 *    snapshot، مش بس فيلدز السؤال الأساسية.
 *  - الحذف (soft delete) بيسجل snapshot أخير بـ change_type='deleted'
 *    قبل ما يحصل الحذف.
 */
class ExamQuestionVersioningTest extends TestCase
{
    use DatabaseTransactions;

    private ExamSystemService $service;
    private int $universityId = 1;
    private int $staffId;
    private int $questionBankId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamSystemService::class);

        DB::table('universities')->insert(['id' => $this->universityId, 'name' => 'Test University']);
        $staffUserId = DB::table('users')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Dr. Staff',
            'email' => 'staff_' . uniqid() . '@test.local', 'password_hash' => bcrypt('secret'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->staffId = DB::table('academic_staff')->insertGetId([
            'user_id' => $staffUserId, 'university_id' => $this->universityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->questionBankId = DB::table('question_banks')->insertGetId([
            'university_id' => $this->universityId, 'created_by_academic_staff_id' => $this->staffId,
            'title' => 'Bank', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMcqQuestion(float $marks, array $optionIsCorrect): Question
    {
        $questionId = DB::table('questions')->insertGetId([
            'question_bank_id' => $this->questionBankId, 'type' => 'mcq', 'prompt' => 'Original prompt?',
            'marks' => $marks, 'created_by_academic_staff_id' => $this->staffId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($optionIsCorrect as $i => $isCorrect) {
            DB::table('question_options')->insert([
                'question_id' => $questionId, 'option_text' => "Option $i",
                'is_correct' => $isCorrect, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return Question::findOrFail($questionId);
    }

    #[Test]
    public function updating_a_question_creates_a_version_snapshot_of_the_previous_state(): void
    {
        $question = $this->makeMcqQuestion(5, [true, false]);

        $this->service->updateQuestion($question, ['prompt' => 'Changed prompt?', 'marks' => 8], $this->staffId);

        $versions = DB::table('exam_question_versions')->where('question_id', $question->id)->get();
        $this->assertCount(1, $versions);

        $snapshot = json_decode($versions->first()->snapshot, true);
        $this->assertSame('Original prompt?', $snapshot['prompt'], 'The snapshot must capture the state BEFORE the update, not after.');
        $this->assertEquals(5, $snapshot['marks']);
        $this->assertSame($this->staffId, $versions->first()->changed_by_academic_staff_id);
        $this->assertSame('updated', $versions->first()->change_type);
        $this->assertSame(1, $versions->first()->version_number);
    }

    #[Test]
    public function the_mcq_options_and_correct_answer_are_captured_in_the_snapshot(): void
    {
        $question = $this->makeMcqQuestion(5, [false, true, false]);

        // المدرس بيغيّر الإجابة الصح — أخطر حالة استخدام من التحليل الأصلي.
        $this->service->updateQuestion($question, [
            'options' => [
                ['option_text' => 'Option 0', 'is_correct' => true],
                ['option_text' => 'Option 1', 'is_correct' => false],
                ['option_text' => 'Option 2', 'is_correct' => false],
            ],
        ], $this->staffId);

        $version = DB::table('exam_question_versions')->where('question_id', $question->id)->first();
        $snapshot = json_decode($version->snapshot, true);

        $correctBefore = array_values(array_filter($snapshot['options'], fn ($o) => $o['is_correct']));
        $this->assertCount(1, $correctBefore);
        $this->assertSame('Option 1', $correctBefore[0]['option_text'], 'The OLD correct answer must be preserved in the snapshot.');

        // وبعد التعديل، الإجابة الصح الجديدة فعليًا اتغيّرت في السؤال الحي.
        $liveCorrect = DB::table('question_options')
            ->where('question_id', $question->id)->where('is_correct', true)->pluck('option_text')->all();
        $this->assertSame(['Option 0'], $liveCorrect);
    }

    #[Test]
    public function multiple_edits_accumulate_versions_instead_of_overwriting_the_last_one(): void
    {
        $question = $this->makeMcqQuestion(5, [true, false]);

        $this->service->updateQuestion($question->fresh(), ['prompt' => 'Edit 1'], $this->staffId);
        $this->service->updateQuestion($question->fresh(), ['prompt' => 'Edit 2'], $this->staffId);
        $this->service->updateQuestion($question->fresh(), ['prompt' => 'Edit 3'], $this->staffId);

        $versions = DB::table('exam_question_versions')
            ->where('question_id', $question->id)->orderBy('version_number')->get();

        $this->assertCount(3, $versions, 'Every edit must keep its own row — history, not just the last snapshot.');
        $this->assertSame([1, 2, 3], $versions->pluck('version_number')->all());

        $prompts = $versions->map(fn ($v) => json_decode($v->snapshot, true)['prompt'])->all();
        $this->assertSame(['Original prompt?', 'Edit 1', 'Edit 2'], $prompts, 'Each version stores what the prompt WAS right before that particular edit.');
    }

    #[Test]
    public function deleting_a_question_snapshots_its_final_state_before_the_soft_delete(): void
    {
        $question = $this->makeMcqQuestion(5, [true, false]);

        $this->service->deleteQuestion($question, $this->staffId);

        $this->assertNotNull($question->fresh()->deleted_at, 'Question::delete() should be a soft delete.');

        $version = DB::table('exam_question_versions')->where('question_id', $question->id)->first();
        $this->assertNotNull($version);
        $this->assertSame('deleted', $version->change_type);
        $snapshot = json_decode($version->snapshot, true);
        $this->assertSame('Original prompt?', $snapshot['prompt']);
    }

    #[Test]
    public function version_history_is_returned_most_recent_first(): void
    {
        $question = $this->makeMcqQuestion(5, [true, false]);
        $this->service->updateQuestion($question->fresh(), ['prompt' => 'Edit 1'], $this->staffId);
        $this->service->updateQuestion($question->fresh(), ['prompt' => 'Edit 2'], $this->staffId);

        $history = $this->service->questionVersionHistory($question->id);

        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]->version_number);
        $this->assertSame(1, $history[1]->version_number);
    }
}
