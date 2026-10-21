<?php

namespace App\Services;

use App\Repositories\AiAssistantRepository;
use App\Repositories\FaqIntentRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Ai\AiPlatformTools;
use App\Services\Ai\AiToolsUnsupportedException;
use App\Services\Concerns\ConfiguresAIClient;
use App\Services\Faq\FaqResolverService;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/AiAssistantService.php القديمة — نفس المحرك
 * الواحد اللي كل بورتال (Admin, University, Student,
 * Security, Data Analysis, أي بورتال مستقبلي)
 * بيستخدمه، بالظبط زي MessagingService (بند 18) هي المحرك الوحيد وراء
 * الرسايل في كل بورتال.
 *
 * RBAC: الكلاس ده أبدًا مايستعلمش بيانات المنصة بنفسه. أي حقيقة عن "اليوزر
 * ده مسموحله يشوف إيه دلوقتي" (role، جامعة/كلية/قسم، مشروع حالي، صلاحيات،
 * بيانات داشبورد) بتوصله كـ $context من الكنترولر، اللي بناه من uip_role
 * attribute + بيانات اليوزر الحالي المُوثّقة بنفسها. مسؤولية الكلاس ده:
 * أبدًا ميسمحش ليوزر يقرا محادثة يوزر تاني (فحوصات الملكية تحت)، وتسجيل
 * كل استدعاء للـ audit.
 *
 * أبدًا مابيختلقش رد: زي كل خدمة AI-* تانية في المشروع، مزوّد AI معطّل أو
 * فاشل بيرمي — الكنترولر بيحوّل ده لرسالة خطأ حقيقية، أبدًا مش رد شكله وهمي.
 */
class AiAssistantService
{
    use ConfiguresAIClient;

    private const MAX_TOOL_ROUNDS = 4;
    private const MAX_CALLS_PER_ROUND = 5;
    private const MAX_CALLS_PER_TURN = 10;

    private AIClient $client;

    public function __construct(
        AIClient $client,
        private SettingRepository $settings,
        private AiAssistantRepository $repo,
        private AiKnowledgeBaseService $knowledge,
        private AuditLogService $auditLog,
        private FaqResolverService $faqResolver,
        private FaqIntentRepository $faqRepo,
        private AiUserContextService $userContext,
        private AiPlatformTools $tools
    ) {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $this->settings);
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /** المساعد يقدر يقرا بيانات المنصة (tools) لدور اليوزر ده؟ */
    public function toolsEnabledFor(?string $role): bool
    {
        return $role !== null && $role !== ''
            && $this->client->isConfigured()
            && $this->adminSettings()['tools_enabled']
            && $this->tools->namesFor($role) !== [];
    }

    // -- Admin AI Controls ---------------------------------------------------

    /** @return array<string,mixed> */
    public function adminSettings(): array
    {
        $get = fn (string $key, $default) => $this->settings->get('ai_assistant_' . $key, 'global', null, $default);

        return [
            'temperature'         => (float) $get('temperature', '0.6'),
            'max_tokens'          => (int) $get('max_tokens', '2000'),
            'system_prompt'       => (string) $get('system_prompt', ''),
            'allowed_extensions'  => array_values(array_filter(explode(',', (string) $get('allowed_extensions', '')))),
            'retention_days'      => (int) $get('retention_days', '0'), // 0 = يفضل للأبد
            'rate_limit_per_min'  => (int) $get('rate_limit_per_min', '15'),
            'logging_enabled'     => $get('logging_enabled', '1') === '1',
            'portal_prompts'      => json_decode((string) $get('portal_prompts', '{}'), true) ?: [],
            'tools_enabled'       => $get('tools_enabled', '1') !== '0',
        ];
    }

    public function updateAdminSettings(array $data): void
    {
        $set = fn (string $key, $value) => $this->settings->set('ai_assistant_' . $key, $value, 'global', null);

        if (isset($data['temperature'])) {
            $set('temperature', (string) max(0.0, min(2.0, (float) $data['temperature'])));
        }
        if (isset($data['max_tokens'])) {
            $set('max_tokens', (string) max(64, min(8000, (int) $data['max_tokens'])));
        }
        if (isset($data['system_prompt'])) {
            $set('system_prompt', (string) $data['system_prompt']);
        }
        if (isset($data['allowed_extensions'])) {
            $ext = is_array($data['allowed_extensions']) ? $data['allowed_extensions'] : explode(',', (string) $data['allowed_extensions']);
            $set('allowed_extensions', implode(',', array_map('trim', array_filter($ext))));
        }
        if (isset($data['retention_days'])) {
            $set('retention_days', (string) max(0, (int) $data['retention_days']));
        }
        if (isset($data['rate_limit_per_min'])) {
            $set('rate_limit_per_min', (string) max(1, (int) $data['rate_limit_per_min']));
        }
        if (isset($data['logging_enabled'])) {
            $set('logging_enabled', !empty($data['logging_enabled']) ? '1' : '0');
        }
        if (isset($data['tools_enabled'])) {
            $set('tools_enabled', !empty($data['tools_enabled']) ? '1' : '0');
        }
        if (isset($data['portal_prompts']) && is_array($data['portal_prompts'])) {
            $set('portal_prompts', json_encode($data['portal_prompts'], JSON_UNESCAPED_UNICODE));
        }
    }

    // -- Rate limiting --------------------------------------------------------

    public function assertNotRateLimited(int $userId): void
    {
        $limit = $this->adminSettings()['rate_limit_per_min'];
        if ($this->repo->callsInLastMinute($userId) >= $limit) {
            throw new \RuntimeException("You've reached the AI Assistant's rate limit ({$limit} requests/minute). Please wait a moment and try again.");
        }
    }

    // -- Conversations --------------------------------------------------------

    public function listConversations(int $userId, array $filters = []): array
    {
        return array_map(fn ($row) => $this->shapeConversation($row), $this->repo->conversationsForUser($userId, $filters));
    }

    public function createConversation(int $userId, string $portal, ?string $title = null): array
    {
        $conversation = $this->repo->createConversation($userId, $portal, $title ?: 'New chat');
        return $this->shapeConversation($conversation->toArray());
    }

    /** @throws \RuntimeException لو مش موجودة أو مش ملك $userId */
    public function getConversation(int $userId, int $conversationId): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $messages = $this->repo->messagesForConversation($conversationId);

        return [
            'conversation' => $this->shapeConversation($conversation->toArray()),
            'messages'     => array_map(fn ($m) => $this->shapeMessage($m), $messages),
        ];
    }

    public function renameConversation(int $userId, int $conversationId, string $title): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $conversation->fill(['title' => mb_substr(trim($title) ?: 'New chat', 0, 200)]);
        $conversation->save();
        return $this->shapeConversation($conversation->toArray());
    }

    public function setPinned(int $userId, int $conversationId, bool $pinned): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $conversation->fill(['is_pinned' => $pinned ? 1 : 0]);
        $conversation->save();
        return $this->shapeConversation($conversation->toArray());
    }

    public function setArchived(int $userId, int $conversationId, bool $archived): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $conversation->fill(['is_archived' => $archived ? 1 : 0]);
        $conversation->save();
        return $this->shapeConversation($conversation->toArray());
    }

    public function deleteConversation(int $userId, int $conversationId): void
    {
        $this->ownedConversation($userId, $conversationId);
        $this->repo->deleteConversation($conversationId);
        $this->auditLog->record($userId, 'ai_assistant.conversation_deleted', 'ai_conversation', $conversationId);
    }

    /** تصدير الشات — نص Markdown. */
    public function exportConversation(int $userId, int $conversationId): array
    {
        $data = $this->getConversation($userId, $conversationId);
        $lines = ['# ' . $data['conversation']['title'], ''];
        foreach ($data['messages'] as $m) {
            if ($m['role'] === 'system') {
                continue;
            }
            $who = $m['role'] === 'user' ? 'You' : 'UIP AI Assistant';
            $lines[] = "**{$who}** ({$m['created_at']}):";
            $lines[] = $m['content'];
            $lines[] = '';
        }
        return ['filename' => 'uip-chat-' . $conversationId . '.md', 'content' => implode("\n", $lines)];
    }

    public function searchMessages(int $userId, string $q): array
    {
        return $this->repo->searchMessages($userId, $q);
    }

    // -- Messages ---------------------------------------------------------------

    public function bookmarkMessage(int $userId, int $messageId, bool $bookmarked): array
    {
        $message = $this->ownedMessage($userId, $messageId);
        $message->fill(['is_bookmarked' => $bookmarked ? 1 : 0]);
        $message->save();
        return $this->shapeMessage($message->toArray());
    }

    public function reactToMessage(int $userId, int $messageId, string $emoji): array
    {
        $message = $this->ownedMessage($userId, $messageId);
        $reactions = json_decode((string) $message->reactions_json, true) ?: [];
        $reactions[$emoji] = array_values(array_unique(array_merge($reactions[$emoji] ?? [], [$userId])));
        $message->fill(['reactions_json' => json_encode($reactions, JSON_UNESCAPED_UNICODE)]);
        $message->save();
        return $this->shapeMessage($message->toArray());
    }

    public function deleteMessage(int $userId, int $messageId): void
    {
        $message = $this->ownedMessage($userId, $messageId);
        $message->fill(['is_deleted' => 1]);
        $message->save();
        $this->repo->touchConversation((int) $message->conversation_id);
    }

    /**
     * تعديل الرسايل: بيعيد كتابة محتوى رسالة اليوزر. ماينادش الـ AI بنفسه —
     * المستدعي (الكنترولر) لو عايز رد جديد بيكمل بـ sendMessage()/
     * streamAssistantReply() بعدها.
     */
    public function editMessage(int $userId, int $messageId, string $newContent): array
    {
        $message = $this->ownedMessage($userId, $messageId);
        if ($message->role !== 'user') {
            throw new \RuntimeException('Only your own messages can be edited.');
        }
        $message->fill(['content' => $newContent, 'edited_at' => now()]);
        $message->save();
        return $this->shapeMessage($message->toArray());
    }

    /**
     * بيخزّن رسالة اليوزر (+ أي مرفقات خزّنها الكنترولر بالفعل) وبيرجّع
     * الـ messages array الكامل عشان يترسل للموديل، مبني من دور المحادثة
     * الفعلي السابق + الـ system prompt لهذا الاستدعاء. فصل "خزّن + ابني
     * الـ prompt" عن "نادي الموديل" (streamAssistantReply() تحت) هو اللي
     * بيسمح للكنترولر يستريم رد SSE مباشرة من الـ route action بدل ما
     * يستنى الرد كامل الأول.
     *
     * @param array{portal:string,page?:string,role?:string,university?:string,
     *   faculty?:string,department?:string,permissions?:array,project?:array,
     *   dashboard?:array} $context سياق آمن-RBAC الكنترولر جهّزه بالفعل.
     * @return array{user_message:array,prompt_messages:array,assistant_message_id:int}
     */
    public function prepareTurn(int $userId, int $conversationId, string $content, array $attachmentIds, array $context): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);

        $attachmentContext = $this->attachmentContextText($attachmentIds);
        $fullUserContent = $attachmentContext !== '' ? trim($content . "\n\n" . $attachmentContext) : $content;

        $userMessage = $this->repo->createMessage([
            'conversation_id'  => $conversationId,
            'role'             => 'user',
            'content'          => $content,
            'status'           => 'complete',
            'context_snapshot' => json_encode($this->snapshotContext($context), JSON_UNESCAPED_UNICODE),
        ]);

        $this->attachAttachmentsToMessage($attachmentIds, (int) $userMessage->id);

        // اعطاء عنوان تلقائي لشات جديد من أول رسالة.
        if ((int) $conversation->message_count === 0 || $conversation->title === 'New chat') {
            $conversation->fill(['title' => $this->titleFromContent($content)]);
            $conversation->save();
        }

        $placeholder = $this->repo->createMessage([
            'conversation_id' => $conversationId,
            'role'            => 'assistant',
            'content'         => '',
            'status'          => 'streaming',
        ]);

        $this->repo->touchConversation($conversationId);

        $history = $this->repo->messagesForConversation($conversationId);
        $promptMessages = $this->buildPromptMessages($history, (int) $placeholder->id, $context, $fullUserContent, (int) $userMessage->id);

        return [
            'user_message'          => $this->shapeMessage($userMessage->toArray()),
            'prompt_messages'       => $promptMessages,
            'assistant_message_id'  => (int) $placeholder->id,
        ];
    }

    /**
     * بيستريم رد الموديل لتيرن جاهز بالفعل (شوف prepareTurn())، بينادي
     * $onToken(string $delta) كل ما نص وصل، وبعدين بيخزن الرسالة النهائية.
     * بيرمي (أبدًا مايختلقش رد) عند فشل المزوّد — الكنترولر بيخزن رسالة
     * status='error' ويظهرها لواجهة الشات.
     */
    public function streamAssistantReply(int $userId, int $conversationId, int $assistantMessageId, array $promptMessages, string $portal, callable $onToken, ?string $role = null, ?callable $onEvent = null, string $locale = 'ar'): string
    {
        $settings = $this->adminSettings();
        $started = microtime(true);
        $usedTools = [];

        try {
            $fullText = $this->runModel($userId, $role, $promptMessages, $settings, $onToken, $onEvent, $locale, $usedTools);

            $message = $this->repo->findMessage($assistantMessageId);
            $message->fill(['content' => $fullText, 'status' => 'complete', 'answer_source' => 'ai']);
            $message->save();

            $this->logCall($userId, $conversationId, $portal, null, null, (int) round((microtime(true) - $started) * 1000), false, null);
            $this->auditToolUse($userId, $conversationId, $role, $usedTools);

            return $fullText;
        } catch (\Throwable $e) {
            $message = $this->repo->findMessage($assistantMessageId);
            if ($message) {
                $message->fill(['status' => 'error', 'error_message' => $e->getMessage(), 'content' => '']);
                $message->save();
            }
            $this->logCall($userId, $conversationId, $portal, null, null, (int) round((microtime(true) - $started) * 1000), true, $e->getMessage());
            throw $e;
        }
    }

    /**
     * بيستريم رد الموديل، ولو طلب أدوات (قراءة بيانات المنصة) بينفّذها على السيرفر
     * بهوية اليوزر الموثّقة ويرجّع النتايج للموديل لحد الرد النهائي. لو الأدوات
     * معطّلة/الدور مالوش أدوات/المزوّد مابيدعمهاش → نفس الشات القديم (streamChat).
     *
     * @param string[] $usedTools بتتملي بأسماء الأدوات اللي اتنفّذت
     */
    private function runModel(int $userId, ?string $role, array $promptMessages, array $settings, callable $onToken, ?callable $onEvent, string $locale, array &$usedTools): string
    {
        $defs = $this->toolsEnabledFor($role) ? $this->tools->definitionsFor($role) : [];
        if (!$defs) {
            return $this->client->streamChat($promptMessages, $settings['temperature'], $settings['max_tokens'], $onToken);
        }

        $messages = $promptMessages;
        $seen = [];
        $totalCalls = 0;

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $lastRound = $round === self::MAX_TOOL_ROUNDS;
            $streamedChars = 0;
            $counting = function (string $delta) use ($onToken, &$streamedChars) {
                $streamedChars += mb_strlen($delta);
                $onToken($delta);
            };

            try {
                $res = $this->client->streamChatWithTools($messages, $defs, $settings['temperature'], $settings['max_tokens'], $counting, $lastRound ? 'none' : 'auto');
            } catch (AiToolsUnsupportedException $e) {
                if ($round > 0) {
                    throw $e;
                }
                Log::notice('AI provider rejected tools; falling back to plain chat', ['error' => $e->getMessage()]);
                return $this->client->streamChat($promptMessages, $settings['temperature'], $settings['max_tokens'], $onToken);
            }

            if (!$res['tool_calls'] || $lastRound) {
                if (trim($res['text']) === '') {
                    throw new \RuntimeException('AI provider returned an empty completion.');
                }
                return $res['text'];
            }

            if ($streamedChars > 0 && $onEvent) {
                $onEvent('reset', []);
            }

            $assistantCalls = [];
            foreach ($res['tool_calls'] as $call) {
                $assistantCalls[] = [
                    'id' => $call['id'], 'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments'] !== '' ? $call['arguments'] : '{}'],
                ];
            }
            $messages[] = ['role' => 'assistant', 'content' => $res['text'], 'tool_calls' => $assistantCalls];

            foreach ($res['tool_calls'] as $i => $call) {
                $args = json_decode($call['arguments'] !== '' ? $call['arguments'] : '{}', true);
                $args = is_array($args) ? $args : [];
                $fingerprint = $call['name'] . ':' . json_encode($args, JSON_UNESCAPED_UNICODE);

                if ($i >= self::MAX_CALLS_PER_ROUND || $totalCalls >= self::MAX_CALLS_PER_TURN) {
                    $result = ['error' => 'too_many_tool_calls', 'message' => 'Too many tool calls in one turn; answer with what you already have.'];
                } elseif (isset($seen[$fingerprint])) {
                    $result = $seen[$fingerprint];
                } else {
                    if ($onEvent) {
                        $onEvent('tool', ['name' => $call['name'], 'label' => $this->tools->label($call['name'], $locale)]);
                    }
                    $result = $this->tools->execute($call['name'], $args, $userId, $role);
                    $seen[$fingerprint] = $result;
                    $usedTools[] = $call['name'];
                    $totalCalls++;
                }

                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $json !== false ? $json : '{"error":"encoding_failed"}'];
            }
        }

        throw new \RuntimeException('AI did not produce a final answer.');
    }

    /** سجل استخدام الأدوات في الـ audit log (أسماء الأدوات فقط). */
    private function auditToolUse(int $userId, int $conversationId, ?string $role, array $usedTools): void
    {
        if (!$usedTools) {
            return;
        }
        try {
            $this->auditLog->record($userId, 'ai_assistant.tools_used', 'ai_conversation', $conversationId, null, [
                'role' => $role, 'tools' => array_values(array_unique($usedTools)), 'calls' => count($usedTools),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI tool audit failed', ['error' => $e->getMessage()]);
        }
    }

    // -- طبقة الرد الجاهز الذكي / حل FAQ ------------------------------------
    //
    // بتقف قبل مسار الـ AI العادي: كل مسار إرسال-رسالة عادي بينادي دي
    // الأول؛ تطابق واثق بيخزن التيرنين ويرجعهم من غير ما يلمس AIClient
    // أو rate limit الـ AI خالص؛ miss (أو تطابق واطي الثقة) بيرجّع null
    // والمستدعي بيكمل على prepareTurn()/streamAssistantReply() زي ما هي
    // بالظبط.

    /**
     * @param array{portal?:string,page?:string,role?:string} $context نفس
     *   سياق RBAC اللي buildContext() في الكنترولر جهّزه بالفعل — بيُستخدم
     *   بس لفلترة الـ intents الخاصة بـ role معيّن.
     * @return array{user_message:array,assistant_message:array}|null null
     *   عند miss/تطابق واطي الثقة (مفيش حاجة اتخزنت).
     * @throws \RuntimeException لو المحادثة مش موجودة/مش ملك اليوزر
     */
    public function tryFaqAnswer(int $userId, int $conversationId, string $content, array $context): ?array
    {
        if (trim($content) === '') {
            return null;
        }

        $result = $this->faqResolver->resolve($content, $context);

        if (!$result['matched']) {
            $this->faqRepo->recordUnmatched(
                $result['normalized'],
                mb_substr($content, 0, 500),
                $result['language'],
                $context['role'] ?? null,
                $context['portal'] ?? null,
                $result['best_candidate_key'],
                $result['best_score']
            );
            return null;
        }

        // فحص الملكية الأول — نفس ضمان prepareTurn(): يوزر أبدًا مايكتبش
        // في محادثة مش بتاعته، سواء مسار FAQ أو لأ.
        $conversation = $this->ownedConversation($userId, $conversationId);
        $intent = $result['intent'];

        $answer = $result['language'] === 'ar' ? ($intent['answer_ar'] ?? '') : ($intent['answer_en'] ?? '');
        if ($answer === '') {
            $answer = ($intent['answer_en'] ?? '') !== '' ? $intent['answer_en'] : ($intent['answer_ar'] ?? '');
        }
        if ($answer === '') {
            // مفيش حاجة نرد بيها — نعتبرها miss عشان الـ AI الحقيقي يتولاها.
            return null;
        }

        $userMessage = $this->repo->createMessage([
            'conversation_id'  => $conversationId,
            'role'             => 'user',
            'content'          => $content,
            'status'           => 'complete',
            'context_snapshot' => json_encode($this->snapshotContext($context), JSON_UNESCAPED_UNICODE),
        ]);

        if ((int) $conversation->message_count === 0 || $conversation->title === 'New chat') {
            $conversation->fill(['title' => $this->titleFromContent($content)]);
            $conversation->save();
        }

        $assistantMessage = $this->repo->createMessage([
            'conversation_id' => $conversationId,
            'role'            => 'assistant',
            'content'         => $answer,
            'status'          => 'complete',
            'model'           => 'faq:' . $intent['intent_key'],
            'answer_source'   => 'predefined',
            'faq_intent_id'   => $intent['id'],
        ]);

        $this->repo->touchConversation($conversationId);
        $this->faqRepo->recordHit((int) $intent['id']);

        return [
            'user_message'      => $this->shapeMessage($userMessage->toArray()),
            'assistant_message' => $this->shapeMessage($assistantMessage->toArray()),
        ];
    }

    /** تيرن غير-streaming (تستخدمه Regenerate وأي عميل ميقدرش يستهلك SSE). */
    public function sendMessageNonStreaming(int $userId, int $conversationId, string $content, array $attachmentIds, array $context): array
    {
        if (!$attachmentIds) {
            $faqAnswer = $this->tryFaqAnswer($userId, $conversationId, $content, $context);
            if ($faqAnswer !== null) {
                return $faqAnswer;
            }
        }

        $prepared = $this->prepareTurn($userId, $conversationId, $content, $attachmentIds, $context);
        $settings = $this->adminSettings();
        $started = microtime(true);

        try {
            $usedTools = [];
            $text = $this->runModel($userId, $context['role'] ?? null, $prepared['prompt_messages'], $settings, static function (string $delta): void {
            }, null, (string) ($context['locale'] ?? 'ar'), $usedTools);
            $message = $this->repo->findMessage($prepared['assistant_message_id']);
            $message->fill(['content' => $text, 'status' => 'complete', 'answer_source' => 'ai']);
            $message->save();

            $this->logCall($userId, $conversationId, $context['portal'] ?? 'general', null, null, (int) round((microtime(true) - $started) * 1000), false, null);
            $this->auditToolUse($userId, $conversationId, $context['role'] ?? null, $usedTools);

            return [
                'user_message'      => $prepared['user_message'],
                'assistant_message' => $this->shapeMessage($message->toArray()),
            ];
        } catch (\Throwable $e) {
            $message = $this->repo->findMessage($prepared['assistant_message_id']);
            if ($message) {
                $message->fill(['status' => 'error', 'error_message' => $e->getMessage()]);
                $message->save();
            }
            $this->logCall($userId, $conversationId, $context['portal'] ?? 'general', null, null, (int) round((microtime(true) - $started) * 1000), true, $e->getMessage());
            throw $e;
        }
    }

    /** إعادة توليد الرد: بيعيد تشغيل الموديل لآخر تيرن يوزر، مع تجاهل محتوى رد الـ assistant القديم. */
    public function regenerate(int $userId, int $conversationId, int $assistantMessageId, array $context): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $message = $this->ownedMessage($userId, $assistantMessageId);
        if ($message->role !== 'assistant') {
            throw new \RuntimeException('Only an assistant reply can be regenerated.');
        }
        $message->fill(['status' => 'streaming', 'content' => '', 'error_message' => null]);
        $message->save();

        $history = $this->repo->messagesForConversation($conversationId);
        $promptMessages = $this->buildPromptMessages($history, $assistantMessageId, $context, null, null);

        return ['prompt_messages' => $promptMessages, 'assistant_message_id' => $assistantMessageId];
    }

    // -- Internals ----------------------------------------------------------------

    private function ownedConversation(int $userId, int $conversationId)
    {
        $conversation = $this->repo->findConversation($conversationId);
        if (!$conversation || (int) $conversation->user_id !== $userId) {
            throw new \RuntimeException('Conversation not found.');
        }
        return $conversation;
    }

    private function ownedMessage(int $userId, int $messageId)
    {
        $message = $this->repo->findMessage($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $this->ownedConversation($userId, (int) $message->conversation_id);
        return $message;
    }

    /** Chat title from the user's first message — without the auto-transcripts of recordings (they travel inside the message). */
    private function titleFromContent(string $content): string
    {
        $clean = preg_replace('/\[Transcript of "[^"\n]*" \(auto speech-to-text\)\]\n[\s\S]*?\n\[End transcript\]/', '', $content);
        $clean = trim(strip_tags($clean ?? $content));
        return mb_substr($clean !== '' ? $clean : 'New chat', 0, 80);
    }

    private function attachAttachmentsToMessage(array $attachmentIds, int $messageId): void
    {
        if (!$attachmentIds) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('ai_message_attachments')
            ->whereIn('id', array_map('intval', $attachmentIds))
            ->whereNull('message_id')
            ->update(['message_id' => $messageId]);
    }

    /** ملخص نصي بأفضل جهد للملفات المرفقة، متضاف لتيرن اليوزر. */
    private function attachmentContextText(array $attachmentIds): string
    {
        if (!$attachmentIds) {
            return '';
        }
        $lines = [];
        foreach ($attachmentIds as $id) {
            $att = $this->repo->findAttachment((int) $id);
            if (!$att) {
                continue;
            }
            $lines[] = "Attached file: {$att['original_name']} ({$att['mime_type']}, " . round($att['size_bytes'] / 1024, 1) . ' KB)';
            if (!empty($att['extracted_text'])) {
                $lines[] = '--- content ---';
                $lines[] = mb_substr($att['extracted_text'], 0, 6000);
                $lines[] = '--- end content ---';
            }
        }
        return $lines ? "[Attachments]\n" . implode("\n", $lines) : '';
    }

    /**
     * بيبني payload الـ messages[] الكامل لـ AIClient: رسالة system واحدة
     * (هوية + قاعدة معرفة + تركيز البورتال + سياق RBAC) متبوعة بتاريخ
     * المحادثة الحقيقي بالترتيب، لحد نافذة محدودة عشان الثريدز الطويلة
     * تفضل جوّه حجم سياق الموديل.
     */
    private function buildPromptMessages(array $history, int $excludeMessageId, array $context, ?string $overrideLastUserContent, ?int $overrideUserMessageId): array
    {
        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt($context)]];

        $maxTurns = 24;
        $trimmed = array_slice($history, -1 * $maxTurns);

        foreach ($trimmed as $row) {
            if ((int) $row['id'] === $excludeMessageId) {
                continue; // نفس بلاسهولدر الـ streaming
            }
            if ($row['role'] === 'system' || (int) $row['is_deleted'] === 1) {
                continue;
            }
            if ($row['status'] === 'error' || ($row['role'] === 'assistant' && $row['content'] === '')) {
                continue;
            }
            $content = $row['content'];
            if ($overrideUserMessageId !== null && (int) $row['id'] === $overrideUserMessageId && $overrideLastUserContent !== null) {
                $content = $overrideLastUserContent;
            }
            $messages[] = ['role' => $row['role'], 'content' => $content];
        }

        return $messages;
    }

    /** هوية + قاعدة معرفة + تركيز البورتال + سياق RBAC، كلها مجمّعة في system prompt واحد. */
    private function buildSystemPrompt(array $context): string
    {
        $settings = $this->adminSettings();
        $portal = $context['portal'] ?? 'general';
        $toolsOn = $this->toolsEnabledFor($context['role'] ?? null);

        $identity = 'You are the UIP AI Assistant, designed specifically to help users across the Ultimate '
            . 'Intelligent Platform (UIP). UIP was founded in 2026 — if asked when UIP or you were created, '
            . 'launched, or founded, say 2026 and never any other year. Never invent a false origin and never claim '
            . 'to be a different product. The facts about who built UIP, how to contact the founder, and what you '
            . 'must never disclose about technology are in the founder section below — follow it exactly.';

        $parts = [
            $identity,
            $this->knowledge->founderProfile(),
            $this->knowledge->platformOverview(),
            $this->knowledge->capabilitiesSummary($toolsOn),
            $this->knowledge->portalFocus($portal),
            $this->knowledge->behaviorRules($toolsOn),
        ];
        if ($toolsOn) {
            $parts[] = $this->knowledge->toolUseRules();
        }

        // بروفايل اليوزر الموثّق (من الداتابيز عبر AiUserContextService في الكنترولر).
        $profile = is_array($context['profile'] ?? null) ? $context['profile'] : [];
        $profileText = $this->userContext->toPromptText($profile);
        if ($profileText !== '') {
            $parts[] = $profileText;
        }

        $safeContext = array_filter([
            'Current portal'      => $context['portal'] ?? null,
            'Current page'        => $context['page'] ?? null,
            'Current route'       => $context['route'] ?? null,
            'Interface language'  => $context['locale'] ?? null,
            'User role'           => $context['role'] ?? null,
            'University'          => $context['university'] ?? null,
            'Faculty'             => $context['faculty'] ?? null,
            'Department'          => $context['department'] ?? null,
            'Current project'     => isset($context['project']) ? json_encode($context['project'], JSON_UNESCAPED_UNICODE) : null,
            'Dashboard data'      => isset($context['dashboard']) ? json_encode($context['dashboard'], JSON_UNESCAPED_UNICODE) : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($safeContext) {
            $lines = ['[Authorized context for this request — use only this and what the user tells you directly]'];
            foreach ($safeContext as $label => $value) {
                $lines[] = "{$label}: {$value}";
            }
            $guide = $this->knowledge->pageGuide($context['route'] ?? null);
            if ($guide !== '') {
                $lines[] = "What this page is: {$guide}";
            }
            $parts[] = implode("\n", $lines);
        }

        $parts[] = 'Respond in the same language the user writes in (Arabic or English) unless asked to switch. '
            . 'Use Markdown formatting (headings, lists, tables, fenced code blocks with a language tag) where it '
            . 'improves clarity. You are READ-ONLY: never claim to have taken a platform action (sending a message, '
            . 'changing a setting, approving or rejecting a project, grading) — you can only look things up and advise; '
            . 'if a real action is needed, tell the user which page to use and offer to draft the text for them.';

        if ($settings['system_prompt'] !== '') {
            $parts[] = "[Platform administrator instructions]\n" . $settings['system_prompt'];
        }
        if (!empty($settings['portal_prompts'][$portal])) {
            $parts[] = "[Administrator instructions for the {$portal} portal]\n" . $settings['portal_prompts'][$portal];
        }

        return implode("\n\n", $parts);
    }

    /** السياق اللي بيتخزن مع رسالة اليوزر: من غير البروفايل (بيتعاد بناؤه من الداتابيز كل تيرن). */
    private function snapshotContext(array $context): array
    {
        unset($context['profile']);
        return $context;
    }

    private function modelNameForDisclosure(): string
    {
        $override = $this->settings->get('ai_model', 'global', null, '');
        if ($override !== '' && $override !== null) {
            return (string) $override;
        }
        return (string) config('ai.model', '');
    }

    private function logCall(int $userId, int $conversationId, string $portal, ?int $promptTokens, ?int $completionTokens, int $latencyMs, bool $wasError, ?string $errorMessage): void
    {
        $settings = $this->adminSettings();
        if (!$settings['logging_enabled']) {
            return;
        }
        try {
            $this->repo->logUsage([
                'user_id'           => $userId,
                'conversation_id'   => $conversationId,
                'portal'            => $portal,
                'model'             => $this->modelNameForDisclosure(),
                'prompt_tokens'     => $promptTokens ?? 0,
                'completion_tokens' => $completionTokens ?? 0,
                'latency_ms'        => $latencyMs,
                'was_error'         => $wasError ? 1 : 0,
                'error_message'     => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI usage log write failed', ['error' => $e->getMessage()]);
        }
    }

    private function shapeConversation(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'portal'           => $row['portal'],
            'title'            => $row['title'],
            'is_pinned'        => (bool) $row['is_pinned'],
            'is_archived'      => (bool) $row['is_archived'],
            'message_count'    => (int) $row['message_count'],
            'last_message_at'  => $row['last_message_at'] instanceof \DateTimeInterface ? $row['last_message_at']->toDateTimeString() : ($row['last_message_at'] ?? null),
            'created_at'       => $row['created_at'] instanceof \DateTimeInterface ? $row['created_at']->toDateTimeString() : ($row['created_at'] ?? null),
        ];
    }

    private function shapeMessage(array $row): array
    {
        return [
            'id'                 => (int) $row['id'],
            'conversation_id'    => (int) $row['conversation_id'],
            'role'               => $row['role'],
            'content'            => $row['content'],
            'status'             => $row['status'],
            'error_message'      => $row['error_message'] ?? null,
            'is_bookmarked'      => (bool) ($row['is_bookmarked'] ?? false),
            'reactions'          => !empty($row['reactions_json']) ? json_decode($row['reactions_json'], true) : [],
            'edited_at'          => $this->toDateString($row['edited_at'] ?? null),
            'created_at'         => $this->toDateString($row['created_at'] ?? null),
            'attachments'        => array_map(fn ($a) => $this->shapeAttachment($a), $this->repo->attachmentsForMessage((int) $row['id'])),
            // إضافي/متوافق رجعيًا: 'predefined' لرسالة assistant اتردت من
            // FAQ، 'ai' لرد عادي، null لأي رسالة اتخزنت قبل الطبقة دي أو
            // لرسايل اليوزر.
            'answer_source'      => $row['answer_source'] ?? null,
        ];
    }

    private function toDateString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    private function shapeAttachment(array $a): array
    {
        return [
            'id'            => (int) $a['id'],
            'kind'          => $a['kind'],
            'url'           => url('/api/v1/ai-assistant/attachments/' . (int) $a['id'] . '/download'),
            'original_name' => $a['original_name'],
            'mime_type'     => $a['mime_type'],
            'size_bytes'    => (int) $a['size_bytes'],
        ];
    }
}
