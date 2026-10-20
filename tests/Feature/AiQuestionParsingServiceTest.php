<?php

namespace Tests\Feature;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\AiQuestionParsingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests لـ AiQuestionParsingService — الـ AI fallback لصندوق
 * "Add Questions" الجماعي لما الـ regex parser بتاع الفرونت
 * (questionParsing.js) ميعرفش يفهم النص (فقرات من غير علامات، خلط لغات،
 * أشكال مختلفة في نفس اللصقة). نفس أسلوب mocking الموجود في
 * ExamAiGradingTest: أي نداء حقيقي لـ AIClient متبدّل بـ mock — مفيش أي
 * اتصال شبكة فعلي هنا.
 *
 *  - رد AI صالح: كل سؤال بيترجع بالـ type الصح والـ options/correct_answer
 *    المتوقعة.
 *  - mcq من غير صح واحد محدد بالظبط (صفر أو أكتر من صح) بيترجع essay بدل
 *    ما نخمّن إجابة صحيحة — نفس مبدأ "متختلقش" اللي service docblock بيقوله.
 *  - true_false/short_answer من غير correct_answer واضح بيفضل null، مش
 *    بيتخترع.
 *  - فشل الـ AI (استثناء من الـ client، أو رد مش JSON) بيرمي RuntimeException
 *    واضح، مفيش نتيجة جزئية أو وهمية.
 *  - isAvailable() بترجع حالة AIClient::isConfigured() زي ما هي.
 */
class AiQuestionParsingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeService(AIClient $client): AiQuestionParsingService
    {
        return new AiQuestionParsingService($client, app(SettingRepository::class));
    }

    #[Test]
    public function it_returns_a_structured_question_list_from_a_valid_ai_response(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andReturn(json_encode([
            'questions' => [
                [
                    'type' => 'mcq',
                    'prompt' => 'What is the capital of Egypt?',
                    'options' => [
                        ['option_text' => 'Cairo', 'is_correct' => true],
                        ['option_text' => 'Alexandria', 'is_correct' => false],
                        ['option_text' => 'Giza', 'is_correct' => false],
                    ],
                    'correct_answer' => null,
                ],
                [
                    'type' => 'true_false',
                    'prompt' => 'صح ولا خطأ: الشمس تشرق من الغرب.',
                    'options' => [],
                    'correct_answer' => 'false',
                ],
                [
                    'type' => 'essay',
                    'prompt' => 'Discuss the causes of the French Revolution.',
                    'options' => [],
                    'correct_answer' => null,
                ],
            ],
        ]));

        $result = $this->makeService($fakeClient)->parse('some messy pasted text');

        $this->assertCount(3, $result);

        $this->assertSame('mcq', $result[0]['type']);
        $this->assertSame('What is the capital of Egypt?', $result[0]['prompt']);
        $this->assertCount(3, $result[0]['options']);
        $this->assertTrue($result[0]['options'][0]['is_correct']);
        $this->assertNull($result[0]['correct_answer']);

        $this->assertSame('true_false', $result[1]['type']);
        $this->assertSame('false', $result[1]['correct_answer']);

        $this->assertSame('essay', $result[2]['type']);
        $this->assertSame([], $result[2]['options']);
    }

    #[Test]
    public function an_mcq_without_exactly_one_correct_option_is_downgraded_to_essay_instead_of_guessing(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andReturn(json_encode([
            'questions' => [
                [
                    // No option marked correct — the AI couldn't tell.
                    'type' => 'mcq',
                    'prompt' => 'Pick the odd one out.',
                    'options' => [
                        ['option_text' => 'Apple', 'is_correct' => false],
                        ['option_text' => 'Banana', 'is_correct' => false],
                    ],
                    'correct_answer' => null,
                ],
                [
                    // Two options marked correct — ambiguous, still not trusted.
                    'type' => 'mcq',
                    'prompt' => 'Pick a fruit.',
                    'options' => [
                        ['option_text' => 'Apple', 'is_correct' => true],
                        ['option_text' => 'Banana', 'is_correct' => true],
                    ],
                    'correct_answer' => null,
                ],
            ],
        ]));

        $result = $this->makeService($fakeClient)->parse('ambiguous text');

        $this->assertSame('essay', $result[0]['type']);
        $this->assertSame([], $result[0]['options']);
        $this->assertSame('essay', $result[1]['type']);
        $this->assertSame([], $result[1]['options']);
    }

    #[Test]
    public function an_unrecognized_true_false_answer_is_left_null_rather_than_guessed(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andReturn(json_encode([
            'questions' => [
                [
                    'type' => 'true_false',
                    'prompt' => 'Statement with no clear answer in the source text.',
                    'options' => [],
                    'correct_answer' => 'maybe',
                ],
            ],
        ]));

        $result = $this->makeService($fakeClient)->parse('unclear text');

        $this->assertSame('true_false', $result[0]['type']);
        $this->assertNull($result[0]['correct_answer']);
    }

    #[Test]
    public function it_throws_instead_of_returning_partial_or_fabricated_data_when_the_ai_call_fails(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andThrow(new \RuntimeException('AI provider timed out'));

        $this->expectException(\RuntimeException::class);
        $this->makeService($fakeClient)->parse('any text');
    }

    #[Test]
    public function it_throws_when_the_ai_response_is_not_valid_json(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andReturn('this is not json at all');

        $this->expectException(\RuntimeException::class);
        $this->makeService($fakeClient)->parse('any text');
    }

    #[Test]
    public function it_throws_when_the_questions_array_is_missing_from_an_otherwise_valid_json_reply(): void
    {
        $fakeClient = Mockery::mock(AIClient::class);
        $fakeClient->shouldReceive('applyOverrides')->once();
        $fakeClient->shouldReceive('complete')->once()->andReturn(json_encode(['unexpected' => 'shape']));

        $this->expectException(\RuntimeException::class);
        $this->makeService($fakeClient)->parse('any text');
    }

    #[Test]
    public function is_available_mirrors_the_underlying_ai_client_configuration_state(): void
    {
        $configuredClient = Mockery::mock(AIClient::class);
        $configuredClient->shouldReceive('applyOverrides')->once();
        $configuredClient->shouldReceive('isConfigured')->once()->andReturn(true);
        $this->assertTrue($this->makeService($configuredClient)->isAvailable());

        $unconfiguredClient = Mockery::mock(AIClient::class);
        $unconfiguredClient->shouldReceive('applyOverrides')->once();
        $unconfiguredClient->shouldReceive('isConfigured')->once()->andReturn(false);
        $this->assertFalse($this->makeService($unconfiguredClient)->isAvailable());
    }
}
