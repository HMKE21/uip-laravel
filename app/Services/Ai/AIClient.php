<?php

namespace App\Services\Ai;

/**
 * منقولة حرف بحرف من core/Ai/AIClient.php القديمة (نفس الـ cURL الخام،
 * نفس عقد الميثودز) — بس config('ai', []) هنا هو config() بتاع لارافيل
 * بدل الدالة الحرة القديمة. عميل بسيط بلا أي تبعية غير cURL لأي endpoint
 * متوافق مع OpenAI /chat/completions، مدفوع بالكامل من config/ai.php.
 *
 * ده transport class بس: بيبعت رسايل وبيرجّع نص، وبيرمي Exception عند
 * أي فشل. المستهلكين (App\Services\AiAssistantService) هما اللي بيقرروا
 * لو هيرجعوا لرد ثابت وقت الفشل — الكلاس ده نفسه أبدًا ميلطّفش فشل لنجاح وهمي.
 */
class AIClient
{
    private bool $enabled;
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $config = (array) config('ai', []);
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->baseUrl = (string) ($config['base_url'] ?? '');
        $this->apiKey  = (string) ($config['api_key'] ?? '');
        $this->model   = (string) ($config['model'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 12);
    }

    /** الـ base URL / المفتاح الفعّالين (بعد overrides الأدمن) — بتستخدمهم SubtitleService لنداء /audio/transcriptions. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** موديلات محلية (Ollama) بطيئة: SubtitleService بيرفع المهلة لنسخته هو بس (مش singleton فمفيش تأثير على باقي الخدمات). */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->baseUrl !== '' && $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * بتلبس فوق الإعدادات المخزّنة (App\Repositories\SettingRepository،
     * مفاتيح "ai_*") فوق الأساس اللي الـ constructor حمّله من .env. بيتغيّر
     * بس المفاتيح الموجودة فعلًا في $overrides.
     * @param array<string,string|bool> $overrides
     */
    public function applyOverrides(array $overrides): void
    {
        if (array_key_exists('enabled', $overrides) && $overrides['enabled'] !== '' && $overrides['enabled'] !== null) {
            $this->enabled = (bool) $overrides['enabled'];
        }
        if (isset($overrides['base_url']) && $overrides['base_url'] !== '') {
            $this->baseUrl = rtrim((string) $overrides['base_url'], '/');
        }
        if (isset($overrides['api_key']) && $overrides['api_key'] !== '') {
            $this->apiKey = (string) $overrides['api_key'];
        }
        if (isset($overrides['model']) && $overrides['model'] !== '') {
            $this->model = (string) $overrides['model'];
        }
    }

    /**
     * @throws \RuntimeException عند نقص الإعداد، فشل النقل، أو ريسبونس غير 2xx
     */
    public function complete(string $systemPrompt, string $userPrompt, float $temperature = 0.6): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI client is not configured (feature flag, base URL, API key, or model missing).');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $temperature,
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('AI request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("AI provider returned HTTP {$status}: " . substr((string) $raw, 0, 300));
        }

        $decoded = json_decode((string) $raw, true);
        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('AI provider returned an empty completion.');
        }

        return trim($text);
    }

    /**
     * نسخة multi-turn من complete() لـ AiAssistantService — بتاخد messages[]
     * كامل بدل زوج system+user واحد، عشان تاريخ المحادثة الحقيقي يترسل
     * كسياق فعلي. بترجّع الريسبونس المفكوك كامل (نص + usage) عشان
     * المستهلك يسجّل عدد التوكنز لـ Admin AI Analytics / rate limiting.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{text:string, prompt_tokens:?int, completion_tokens:?int}
     * @throws \RuntimeException عند نقص الإعداد، فشل النقل، أو ريسبونس غير 2xx
     */
    public function chat(array $messages, float $temperature = 0.6, ?int $maxTokens = null): array
    {
        $raw = $this->rawRequest($messages, $temperature, $maxTokens);
        $decoded = json_decode($raw, true);
        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('AI provider returned an empty completion.');
        }

        return [
            'text'              => trim($text),
            'prompt_tokens'     => isset($decoded['usage']['prompt_tokens']) ? (int) $decoded['usage']['prompt_tokens'] : null,
            'completion_tokens' => isset($decoded['usage']['completion_tokens']) ? (int) $decoded['usage']['completion_tokens'] : null,
        ];
    }

    /**
     * نسخة streaming من chat(): بتنادي $onToken(string $delta) مع كل
     * chunk نص وصل من stream من نوع OpenAI-compatible SSE ("data: {...}\n\n"،
     * منتهية بـ "data: [DONE]") — عشان واجهة الشات تعرض التوكنز أول
     * بأول بدل ما تستنى الرد كامل. لو المزوّد متجاوبش بـ streaming فعلي
     * (بعض الـ gateways بتتجاهل "stream": true وترجّع JSON عادي)، بيتكشف
     * ده من أول بايتات وصلت وبيترجع كـ onToken() واحدة بالنص الكامل.
     * بترجّع النص الكامل المجمّع، نفس عقد chat()['text'].
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @throws \RuntimeException عند نقص الإعداد، فشل النقل، أو ريسبونس غير 2xx
     */
    public function streamChat(array $messages, float $temperature, ?int $maxTokens, callable $onToken): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI client is not configured (feature flag, base URL, API key, or model missing).');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'stream'      => true,
        ];
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        $fullText = '';
        $buffer = '';
        $sawSseLine = false;

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => max($this->timeout, 60),
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_WRITEFUNCTION  => function ($curlHandle, string $chunk) use (&$fullText, &$buffer, &$sawSseLine, $onToken) {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = rtrim($line, "\r");
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $sawSseLine = true;
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        continue;
                    }
                    $decoded = json_decode($data, true);
                    $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                    if (is_string($delta) && $delta !== '') {
                        $fullText .= $delta;
                        $onToken($delta);
                    }
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('AI request failed: ' . $error);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new \RuntimeException("AI provider returned HTTP {$httpStatus} during streaming.");
        }

        // المزوّد تجاهل "stream": true ورجّع JSON عادي بدل SSE lines — الـ
        // $buffer فيه الريسبونس كامل لأن ولا سطر طابق "data:" فوق. نفكّه
        // كـ completion عادي ونبعته كـ "token" واحدة عشان المستهلك يفضل ياخد رد.
        if (!$sawSseLine && trim($buffer) !== '') {
            $decoded = json_decode($buffer, true);
            $text = $decoded['choices'][0]['message']['content'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $fullText = trim($text);
                $onToken($fullText);
            }
        }

        if (trim($fullText) === '') {
            throw new \RuntimeException('AI provider returned an empty completion.');
        }

        return $fullText;
    }

    /**
     * streaming + function calling (OpenAI-compatible). بيستريم النص عبر $onToken
     * وبيجمّع tool_calls المقطّعة ويرجّعها في الآخر. $toolChoice: 'auto' | 'none'.
     *
     * @return array{text:string, tool_calls:array<int,array{id:string,name:string,arguments:string}>}
     * @throws AiToolsUnsupportedException لو المزوّد رفض الـ tools (HTTP 400/404/422)
     * @throws \RuntimeException عند نقص الإعداد/فشل النقل/ريسبونس غير 2xx
     */
    public function streamChatWithTools(array $messages, ?array $tools, float $temperature, ?int $maxTokens, callable $onToken, string $toolChoice = 'auto'): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI client is not configured (feature flag, base URL, API key, or model missing).');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $payload = ['model' => $this->model, 'messages' => $messages, 'temperature' => $temperature, 'stream' => true];
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        $fullText = '';
        $buffer = '';
        $rawHead = '';
        $sawSseLine = false;
        $calls = [];

        $consumeDelta = function (array $delta) use (&$fullText, &$calls, $onToken): void {
            $text = $delta['content'] ?? '';
            if (is_string($text) && $text !== '') {
                $fullText .= $text;
                $onToken($text);
            }
            foreach ((array) ($delta['tool_calls'] ?? []) as $tc) {
                if (!is_array($tc)) {
                    continue;
                }
                if (isset($tc['index'])) {
                    $idx = (int) $tc['index'];
                } elseif (!empty($tc['id'])) {
                    $idx = count($calls);
                } else {
                    $idx = $calls ? (int) array_key_last($calls) : 0;
                }
                $calls[$idx] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                if (!empty($tc['id'])) {
                    $calls[$idx]['id'] = (string) $tc['id'];
                }
                if (!empty($tc['function']['name'])) {
                    $calls[$idx]['name'] .= (string) $tc['function']['name'];
                }
                if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                    $calls[$idx]['arguments'] .= $tc['function']['arguments'];
                }
            }
        };

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_TIMEOUT        => max($this->timeout, 90),
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_WRITEFUNCTION  => function ($curlHandle, string $chunk) use (&$buffer, &$rawHead, &$sawSseLine, $consumeDelta) {
                if (strlen($rawHead) < 2000) {
                    $rawHead .= substr($chunk, 0, 2000 - strlen($rawHead));
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $sawSseLine = true;
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        continue;
                    }
                    $decoded = json_decode($data, true);
                    if (is_array($decoded) && isset($decoded['choices'][0]['delta']) && is_array($decoded['choices'][0]['delta'])) {
                        $consumeDelta($decoded['choices'][0]['delta']);
                    }
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('AI request failed: ' . $error);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            if ($tools && in_array($httpStatus, [400, 404, 422], true)) {
                throw new AiToolsUnsupportedException("AI provider returned HTTP {$httpStatus} for a request with tools: " . substr($rawHead, 0, 300));
            }
            throw new \RuntimeException("AI provider returned HTTP {$httpStatus} during streaming.");
        }

        // المزوّد تجاهل stream:true ورجّع JSON واحد.
        if (!$sawSseLine && trim($buffer) !== '') {
            $decoded = json_decode($buffer, true);
            $message = is_array($decoded) ? ($decoded['choices'][0]['message'] ?? null) : null;
            if (is_array($message)) {
                $tcs = [];
                foreach ((array) ($message['tool_calls'] ?? []) as $i => $tc) {
                    if (is_array($tc)) {
                        $tcs[] = ['index' => $i] + $tc;
                    }
                }
                $consumeDelta(['content' => is_string($message['content'] ?? null) ? trim($message['content']) : '', 'tool_calls' => $tcs]);
            }
        }

        $toolCalls = [];
        foreach ($calls as $c) {
            if ($c['name'] === '') {
                continue;
            }
            $toolCalls[] = [
                'id'        => $c['id'] !== '' ? $c['id'] : 'call_' . bin2hex(random_bytes(6)),
                'name'      => $c['name'],
                'arguments' => $c['arguments'],
            ];
        }

        if (trim($fullText) === '' && !$toolCalls) {
            throw new \RuntimeException('AI provider returned an empty completion.');
        }

        return ['text' => $fullText, 'tool_calls' => $toolCalls];
    }

    /** نقل مشترك غير-streaming لـ chat() — complete() فاضلة بنسختها الخاصة عشان تفضل أبسط استدعاء منفرد. */
    private function rawRequest(array $messages, float $temperature, ?int $maxTokens): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI client is not configured (feature flag, base URL, API key, or model missing).');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];
        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('AI request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("AI provider returned HTTP {$status}: " . substr((string) $raw, 0, 300));
        }

        return (string) $raw;
    }
}
