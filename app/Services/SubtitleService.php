<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ترجمة الفيديو التلقائية.
 *
 *  1) الفرونت بيفك صوت الفيديو في المتصفح (16kHz mono WAV) ويبعته على قطع قصيرة — فمفيش اعتماد على ffmpeg ولا على مسار الملف.
 *  2) هنا: كل قطعة بتتفرّغ بـ Whisper (/audio/transcriptions، verbose_json → segments بتوقيتات).
 *  3) لو لغة الكلام غير اللغة المطلوبة، النص بيترجم دفعات عبر نفس عميل الـ AI (AIClient::complete).
 *
 * الفشل بيترمي كـ RuntimeException والكنترولر هو اللي بيحوّله لرد خطأ (مفيش ترجمة مختلقة).
 */
class SubtitleService
{
    use ConfiguresAIClient;

    /** كود اللغة => اسمها (للـ prompt) + مقارنة بالـ language اللي Whisper بيرجّعه (اسم إنجليزي). */
    public const LANGUAGES = [
        'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
        'it' => 'Italian', 'tr' => 'Turkish', 'ru' => 'Russian', 'pt' => 'Portuguese', 'zh' => 'Chinese',
        'ja' => 'Japanese', 'ko' => 'Korean', 'hi' => 'Hindi', 'ur' => 'Urdu', 'fa' => 'Persian',
    ];

    private const BATCH = 40; // سطور الترجمة في الطلب الواحد

    private AIClient $client;

    /** مزوّدين مستضافين معروف إنهم بيدعموا /audio/transcriptions — بنستخدم مزوّد الشات الأساسي للتفريغ بس لو هو واحد منهم. */
    private const STT_CAPABLE_HOSTS = ['api.openai.com' => 'whisper-1', 'api.groq.com' => 'whisper-large-v3'];

    public function __construct(private SettingRepository $settings)
    {
        // نسخة خاصة بالخدمة دي (مش المحقونة من الـ container) عشان نرفع المهلة من غير ما نأثر على باقي الخدمات.
        $this->client = new AIClient();
        $this->applySettingsOverrides($this->client, $this->settings);
        $this->client->setTimeout(max(90, (int) config('ai.timeout', 30)));
    }

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get($key, 'global', null, ''));
    }

    /**
     * مزوّد التفريغ: (1) إعدادات الأدمن (Settings → AI → Speech-to-text)، (2) متغيرات .env، (3) مزوّد الشات الأساسي
     * لو مستضاف ومعروف (OpenAI/Groq). Ollama مفيهوش تفريغ صوت — لازم سيرفر Whisper متوافق مع OpenAI (Speaches / faster-whisper-server …).
     * المفتاح اختياري (سيرفرات محلية كتير من غير مفتاح).
     *
     * @return array{base:string,key:string,model:string}|null
     */
    private function sttConfig(): ?array
    {
        $cfg   = (array) config('ai.transcribe', []);
        $base  = rtrim($this->setting('ai_transcribe_base_url') ?: trim((string) ($cfg['base_url'] ?? '')), '/');
        $key   = $this->setting('ai_transcribe_api_key') ?: trim((string) ($cfg['api_key'] ?? ''));
        $model = $this->setting('ai_transcribe_model') ?: trim((string) ($cfg['model'] ?? ''));

        if ($base !== '') {
            return ['base' => $base, 'key' => $key, 'model' => $model !== '' ? $model : 'whisper-1'];
        }

        if ($this->client->isConfigured()) {
            $host = strtolower((string) parse_url($this->client->baseUrl(), PHP_URL_HOST));
            if (isset(self::STT_CAPABLE_HOSTS[$host])) {
                return ['base' => $this->client->baseUrl(), 'key' => $this->client->apiKey(), 'model' => $model !== '' ? $model : self::STT_CAPABLE_HOSTS[$host]];
            }
        }
        return null;
    }

    public function available(): bool
    {
        return $this->sttConfig() !== null;
    }

    /** أقصى طول قطعة (ثواني) يقدر السيرفر يستقبله: min(إعداد المشروع، حد Whisper، حدود PHP) — WAV 16k mono = 32000 بايت/ث. */
    public function maxChunkSeconds(): int
    {
        $cfg   = (array) config('ai.transcribe', []);
        $bytes = min(
            (int) ($cfg['max_upload_kb'] ?? 24000) * 1024,
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size')
        );
        $byBytes = (int) floor((($bytes * 0.9) - 44) / 32000);
        $wanted  = (int) ($cfg['max_chunk_seconds'] ?? 240);
        return max(15, min($wanted, $byBytes, 600));
    }

    /**
     * @return array{language:?string, translated:bool, cues:array<int,array{s:float,e:float,text:string}>}
     * @throws \RuntimeException
     */
    public function generate(UploadedFile $audio, ?string $target, ?string $sourceHint = null): array
    {
        $raw      = $this->transcribe($audio, $sourceHint);
        $cues     = $raw['cues'];
        $detected = $raw['language'];

        $translated = false;
        $wantsTranslation = $target !== null && $target !== '' && $target !== 'orig' && isset(self::LANGUAGES[$target]);
        if ($cues && $wantsTranslation) {
            // Whisper بيحدد لغة واحدة للقطعة كلها (ممكن تكون غلط لو الفيديو مخلوط عربي/إنجليزي) — فالقرار بيتاخد على كل سطر
            // لوحده: السطر اللي مكتوب أصلاً باللغة المطلوبة بيفضل زي ما هو، وأي سطر غيرها بيتترجم (عربي→إنجليزي والعكس).
            $differs = !$this->sameLanguage($detected, $target);
            $todo = [];
            foreach ($cues as $i => $cue) {
                if ($this->cueNeedsTranslation((string) $cue['text'], $target, $differs)) {
                    $todo[] = $i;
                }
            }
            if (!$todo) {
                $translated = true; // كل السطور أصلاً باللغة المطلوبة
            } elseif ($this->client->isConfigured()) {
                [$cues, $translated] = $this->translate($cues, $target, $todo);
            }
        } elseif ($wantsTranslation) {
            $translated = true;
        }

        return ['language' => $detected, 'translated' => $translated, 'cues' => $cues];
    }

    /**
     * @return array{language:?string, cues:array<int,array{s:float,e:float,text:string}>}
     * @throws \RuntimeException
     */
    protected function transcribe(UploadedFile $audio, ?string $sourceHint): array
    {
        $stt = $this->sttConfig();
        if ($stt === null) {
            throw new \RuntimeException('Transcription provider is not configured.');
        }
        $cfg   = (array) config('ai.transcribe', []);
        $path  = $audio->getRealPath();
        $handle = $path ? @fopen($path, 'r') : false;
        if (!$handle) {
            throw new \RuntimeException('Uploaded audio could not be read.');
        }

        $fields = [
            'model'           => $stt['model'],
            'response_format' => 'verbose_json',
            'temperature'     => '0',
        ];
        if ($sourceHint !== null && $sourceHint !== '') {
            $fields['language'] = $sourceHint;
        }

        try {
            $req = Http::acceptJson();
            if ($stt['key'] !== '') {
                $req = $req->withToken($stt['key']);
            }
            $res = $req
                ->timeout(max(20, (int) ($cfg['timeout'] ?? 120)))
                ->attach('file', $handle, 'audio.wav')
                ->post($stt['base'] . '/audio/transcriptions', $fields);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Transcription request failed: ' . $e->getMessage());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($res->failed()) {
            throw new \RuntimeException('Transcription provider returned HTTP ' . $res->status() . ': ' . mb_substr((string) $res->body(), 0, 300));
        }

        $json = (array) $res->json();
        $cues = [];
        foreach ((array) ($json['segments'] ?? []) as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            // Whisper بيهلوس على الصمت/الموسيقى: نستبعد القطع اللي احتمال إنها \"مفيش كلام\" عالي.
            if ($text === '' || (float) ($seg['no_speech_prob'] ?? 0) > 0.6) {
                continue;
            }
            $start = max(0.0, (float) ($seg['start'] ?? 0));
            $end   = max($start + 0.4, (float) ($seg['end'] ?? $start));
            $cues[] = ['s' => round($start, 2), 'e' => round($end, 2), 'text' => $text];
        }
        if (!$cues && trim((string) ($json['text'] ?? '')) !== '' && empty($json['segments'])) {
            // مزوّد بيرجّع نص بس من غير segments.
            $cues[] = ['s' => 0.0, 'e' => max(1.0, (float) ($json['duration'] ?? 5)), 'text' => trim((string) $json['text'])];
        }

        $lang = isset($json['language']) ? strtolower((string) $json['language']) : null;
        return ['language' => $lang, 'cues' => $cues];
    }

    /**
     * @param array<int,array{s:float,e:float,text:string}> $cues
     * @param int[]|null $only أرقام السطور اللي محتاجة ترجمة (null = كلها)
     * @return array{0:array<int,array{s:float,e:float,text:string}>, 1:bool} [cues, كل السطور المطلوبة اتترجمت؟]
     */
    private function translate(array $cues, string $target, ?array $only = null): array
    {
        $name = self::LANGUAGES[$target];
        $all  = true;
        $out  = $cues;
        foreach (array_chunk($only ?? array_keys($cues), self::BATCH) as $idxs) {
            $texts = array_map(fn ($i) => $cues[$i]['text'], $idxs);
            $translated = $this->translateResilient($texts, $name);
            foreach ($idxs as $k => $i) {
                if (isset($translated[$k]) && $translated[$k] !== '') {
                    $out[$i]['text'] = $translated[$k];
                } else {
                    $all = false; // السطر ده بيفضل بلغته الأصلية بدل ما يضيع.
                }
            }
        }
        return [$out, $all];
    }

    /**
     * لو الموديل رجّع عدد سطور غلط (بيحصل مع الدفعات الكبيرة) بنقسم الدفعة نصين ونعيد، بدل ما الدفعة كلها تفضل من غير ترجمة.
     *
     * @param string[] $texts
     * @return array<int,?string> نفس عدد السطور (null = السطر ده معرفناش نترجمه)
     */
    private function translateResilient(array $texts, string $languageName, int $depth = 0): array
    {
        $n = count($texts);
        $res = $this->translateBatch($texts, $languageName);
        if (is_array($res)) {
            return $res;
        }
        // false = المزوّد نفسه واقع/رافض (تقسيم الدفعة مش هيفيد)، null = رد بصيغة غلط
        if ($res === false || $n <= 1 || $depth >= 4) {
            return array_fill(0, $n, null);
        }
        $mid = intdiv($n, 2);
        return array_merge(
            $this->translateResilient(array_slice($texts, 0, $mid), $languageName, $depth + 1),
            $this->translateResilient(array_slice($texts, $mid), $languageName, $depth + 1)
        );
    }

    /** @param string[] $texts @return string[]|null|false  false = فشل الاتصال بالمزوّد في المحاولتين */
    private function translateBatch(array $texts, string $languageName): array|null|false
    {
        $n = count($texts);
        $system = "You are a professional subtitle translator. The user sends a JSON array of {$n} subtitle lines "
            . "that were transcribed automatically from speech (the speaker may use colloquial speech or a dialect, e.g. Egyptian Arabic, "
            . "and the transcript may contain small recognition errors — translate the intended meaning). "
            . "Translate every line into {$languageName}. Keep the meaning, tone and brevity suited to on-screen subtitles; "
            . "keep names, numbers, code and technical terms accurate. A line that is already written in {$languageName} must be returned unchanged. "
            . "Do not merge, split, reorder, add or drop lines. "
            . "Reply with ONLY a JSON array of exactly {$n} strings. No markdown fences, no commentary.";

        $failedCalls = 0;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $raw = $this->client->complete($system, json_encode($texts, JSON_UNESCAPED_UNICODE), 0.2);
            } catch (\RuntimeException $e) {
                $failedCalls++;
                Log::warning('Subtitle translation request failed', ['attempt' => $attempt, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                continue;
            }
            $arr = $this->parseJsonArray($raw);
            if ($arr !== null && count($arr) === $n) {
                return array_map(fn ($v) => trim((string) $v), array_values($arr));
            }
        }
        return $failedCalls >= 2 ? false : null;
    }

    /**
     * السطر ده محتاج ترجمة للغة $target؟ بنعتمد على الحروف نفسها: عربي↔إنجليزي بيتميّزوا بالأبجدية مهما كانت لغة Whisper المكتشفة.
     * اللغات اللي بتشترك في نفس الأبجدية (إنجليزي/فرنسي/ألماني… أو عربي/فارسي/أردو) مفيش طريقة نفرّق بيها من الحروف،
     * فبنرجع للغة اللي Whisper اكتشفها ($detectedDiffers).
     */
    private function cueNeedsTranslation(string $text, string $target, bool $detectedDiffers): bool
    {
        $count = static fn (string $re): int => (int) preg_match_all($re, $text);
        $letters = $count('/\p{L}/u');
        if ($letters === 0) {
            return false; // أرقام / رموز / علامات موسيقى
        }
        $own = match ($target) {
            'ar', 'fa', 'ur' => $count('/\p{Arabic}/u'),
            'ru'             => $count('/\p{Cyrillic}/u'),
            'ko'             => $count('/\p{Hangul}/u'),
            'hi'             => $count('/\p{Devanagari}/u'),
            'zh', 'ja'       => $count('/[\p{Han}\p{Hiragana}\p{Katakana}]/u'),
            default          => $count('/\p{Latin}/u'),
        };
        if ($own / $letters < 0.5) {
            return true; // السطر أغلبه بأبجدية تانية غير بتاعة اللغة المطلوبة
        }
        // أبجدية مميزة للغة (روسي / كوري / هندي): السطر بيها فعلاً → مفيش ترجمة
        if (in_array($target, ['ru', 'ko', 'hi'], true)) {
            return false;
        }
        // نفس الأبجدية: نفرّق بس بلغة Whisper
        return $detectedDiffers;
    }

    private function parseJsonArray(string $raw): ?array
    {
        $text = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $raw));
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function sameLanguage(?string $detected, string $target): bool
    {
        if ($detected === null || $detected === '') {
            return false;
        }
        $detected = strtolower($detected);
        return $detected === $target || $detected === strtolower(self::LANGUAGES[$target] ?? '');
    }

    private function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || (int) $v <= 0) {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
