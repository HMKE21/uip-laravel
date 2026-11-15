<?php

namespace App\Services\Ai;

/**
 * توليد صور عبر مزوّد متوافق مع OpenAI:
 *  - api=auto   : (الافتراضي) OpenRouter => POST /images (b64_json)، أي مزوّد تاني => POST /images/generations.
 *  - api=chat   : POST /chat/completions مع modalities ["image","text"] (OpenRouter / Gemini image models).
 *                 الصورة بترجع في choices[0].message.images[].image_url.url كـ data URI.
 *  - api=images : POST /images/generations (OpenAI-style) — data[0].b64_json أو data[0].url.
 * بيرمي AiProviderException/RuntimeException عند أي فشل — أبدًا مابيرجّعش صورة مختلقة.
 */
class ImageClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private string $api;
    private int $timeout;
    private string $imagesPath = '/images/generations';

    /** @param array{model?:string,base_url?:string,api_key?:string} $overrides إعدادات الأدمن (ai_image_*) — الفاضي بيرجع للـ .env ثم لمزوّد الشات. */
    public function __construct(AIClient $chat, array $overrides = [])
    {
        $cfg = (array) config('ai.image', []);
        $pick = fn (string $k) => trim((string) (($overrides[$k] ?? '') !== '' ? $overrides[$k] : ($cfg[$k] ?? '')));
        $base = $pick('base_url');
        $key = $pick('api_key');
        // لو الأدمن حدّد مزوّد صور تاني (base_url) بنستخدم مفتاحه هو؛ مفيش تسريب لمفتاح الشات لمزوّد غريب.
        $this->baseUrl = rtrim($base !== '' ? $base : $chat->baseUrl(), '/');
        $this->apiKey  = $base !== '' ? $key : ($key !== '' ? $key : $chat->apiKey());
        $this->model   = $pick('model');
        $api = (string) ($cfg['api'] ?? 'auto');
        if (str_contains($this->baseUrl, 'api.cloudflare.com')) {
            $api = 'cloudflare'; // Workers AI: POST {base}/{model} => result.image (base64) — مجاني ~230 صورة/يوم
        } elseif ($api === 'auto' || !in_array($api, ['chat', 'images'], true)) {
            $api = 'images';
        }
        $this->api = $api;
        // OpenRouter عنده endpoint مخصوص للصور: POST /images (مش /images/generations).
        $this->imagesPath = str_contains($this->baseUrl, 'openrouter.ai') ? '/images' : '/images/generations';
        $this->timeout = max(30, (int) ($cfg['timeout'] ?? 120));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->model !== '';
    }

    /** @return array{bytes:string, mime:string, caption:string} */
    public function generate(string $prompt): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Image generation is not configured (set an Image model in Admin → Settings → AI).');
        }

        if ($this->api === 'cloudflare') {
            $path = '/' . ltrim($this->model, '/');
            $payload = ['prompt' => mb_substr($prompt, 0, 2000), 'steps' => max(1, min(8, (int) config('ai.image.steps', 6)))];
        } elseif ($this->api === 'images') {
            $path = $this->imagesPath;
            $payload = ['model' => $this->model, 'prompt' => $prompt, 'n' => 1];
        } else {
            $path = '/chat/completions';
            $payload = ['model' => $this->model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'modalities' => ['image', 'text']];
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('Image request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw AIClient::providerError($status, (string) $raw, 'for image generation');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Image provider returned an invalid response.');
        }

        $caption = '';
        if ($this->api === 'cloudflare') {
            $b64 = (string) ($decoded['result']['image'] ?? '');
            $src = $b64 !== '' ? 'data:image/jpeg;base64,' . $b64 : '';
        } elseif ($this->api === 'images') {
            $item = $decoded['data'][0] ?? [];
            $src = isset($item['b64_json']) ? 'data:image/png;base64,' . $item['b64_json'] : (string) ($item['url'] ?? '');
        } else {
            $message = $decoded['choices'][0]['message'] ?? [];
            $src = (string) ($message['images'][0]['image_url']['url'] ?? $message['images'][0]['url'] ?? '');
            $caption = is_string($message['content'] ?? null) ? trim($message['content']) : '';
        }

        if ($src === '') {
            throw new \RuntimeException('The model did not return an image. Make sure the Image model can output images (OpenRouter: Output modalities = image).');
        }

        [$bytes, $mime] = $this->decodeSource($src);
        return ['bytes' => $bytes, 'mime' => $mime, 'caption' => mb_substr($caption, 0, 500)];
    }

    /** @return array{0:string,1:string} */
    private function decodeSource(string $src): array
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $src, $m)) {
            $bytes = base64_decode($m[2], true);
            $mime = strtolower($m[1]);
        } elseif (preg_match('#^https://#i', $src)) {
            // رابط مباشر (images API): ننزّله بحد حجم ومهلة.
            $ch = curl_init($src);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 12_000_000]);
            $bytes = curl_exec($ch);
            $mime = strtolower((string) strtok((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), ';'));
            curl_close($ch);
        } else {
            throw new \RuntimeException('Unsupported image format returned by the provider.');
        }

        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 12_000_000) {
            throw new \RuntimeException('The provider returned an empty or oversized image.');
        }
        $info = @getimagesizefromstring($bytes);
        $realMime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($realMime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            throw new \RuntimeException('The provider returned data that is not a valid image.');
        }
        return [$bytes, $realMime];
    }
}
