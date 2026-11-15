<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Per-user AI assistant settings: personalization (custom instructions, tone, length), generation limits,
 * and an optional personal API (base URL + key + model) that replaces the platform provider for that user only.
 * The key is stored encrypted (Crypt) and is never sent back to the browser (only has_key + last 4 chars).
 */
class AiUserSettingsService
{
    private const TONES = ['default', 'friendly', 'professional', 'concise', 'casual', 'encouraging'];
    private const LENGTHS = ['default', 'short', 'detailed'];

    /** @var array<int,array<string,mixed>|null> */
    private array $cache = [];

    public function __construct(private SettingRepository $settings)
    {
    }

    /** Admin switch (Admin → AI settings key ai_assistant_allow_user_keys). Default: allowed. */
    public function userKeysAllowed(): bool
    {
        return (string) $this->settings->get('ai_assistant_allow_user_keys', 'global', null, '1') !== '0';
    }

    /** @return array<string,mixed>|null raw row */
    private function row(int $userId): ?array
    {
        if (!array_key_exists($userId, $this->cache)) {
            $row = DB::table('ai_user_settings')->where('user_id', $userId)->first();
            $this->cache[$userId] = $row ? (array) $row : null;
        }
        return $this->cache[$userId];
    }

    /** Safe shape for the browser. */
    public function get(int $userId): array
    {
        $r = $this->row($userId) ?? [];
        $key = $this->decryptKey($r['own_api_key'] ?? null);

        return [
            'nickname'              => (string) ($r['nickname'] ?? ''),
            'about_you'             => (string) ($r['about_you'] ?? ''),
            'response_instructions' => (string) ($r['response_instructions'] ?? ''),
            'tone'                  => (string) ($r['tone'] ?? 'default'),
            'response_length'       => (string) ($r['response_length'] ?? 'default'),
            'temperature'           => isset($r['temperature']) ? (float) $r['temperature'] : null,
            'max_tokens'            => isset($r['max_tokens']) ? (int) $r['max_tokens'] : null,
            'use_own_key'           => !empty($r['use_own_key']),
            'own_base_url'          => (string) ($r['own_base_url'] ?? ''),
            'own_model'             => (string) ($r['own_model'] ?? ''),
            'has_key'               => $key !== '',
            'key_hint'              => $key !== '' ? '…' . substr($key, -4) : '',
            'user_keys_allowed'     => $this->userKeysAllowed(),
        ];
    }

    /** @throws \RuntimeException on invalid input (message is shown to the user) */
    public function save(int $userId, array $in): array
    {
        $cur = $this->row($userId) ?? [];
        $data = [];

        if (array_key_exists('nickname', $in)) {
            $data['nickname'] = mb_substr(trim((string) $in['nickname']), 0, 60) ?: null;
        }
        foreach (['about_you' => 1500, 'response_instructions' => 1500] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $data[$f] = mb_substr(trim((string) $in[$f]), 0, $max) ?: null;
            }
        }
        if (array_key_exists('tone', $in)) {
            $data['tone'] = in_array($in['tone'], self::TONES, true) ? $in['tone'] : 'default';
        }
        if (array_key_exists('response_length', $in)) {
            $data['response_length'] = in_array($in['response_length'], self::LENGTHS, true) ? $in['response_length'] : 'default';
        }
        if (array_key_exists('temperature', $in)) {
            $data['temperature'] = ($in['temperature'] === null || $in['temperature'] === '') ? null : max(0.0, min(2.0, (float) $in['temperature']));
        }
        if (array_key_exists('max_tokens', $in)) {
            $data['max_tokens'] = ($in['max_tokens'] === null || $in['max_tokens'] === '') ? null : max(64, min(8000, (int) $in['max_tokens']));
        }

        // Personal API
        $wantsOwn = array_key_exists('use_own_key', $in) ? !empty($in['use_own_key']) : !empty($cur['use_own_key']);
        if (array_key_exists('own_base_url', $in)) {
            $url = rtrim(trim((string) $in['own_base_url']), '/');
            $data['own_base_url'] = $url !== '' ? $url : null;
        }
        if (array_key_exists('own_model', $in)) {
            $data['own_model'] = mb_substr(trim((string) $in['own_model']), 0, 120) ?: null;
        }
        if (array_key_exists('own_api_key', $in) && trim((string) $in['own_api_key']) !== '') {
            $data['own_api_key'] = Crypt::encryptString(trim((string) $in['own_api_key']));
        }

        if ($wantsOwn) {
            if (!$this->userKeysAllowed()) {
                throw new \RuntimeException('Using a personal API is disabled by the platform administrator.');
            }
            $url = (string) ($data['own_base_url'] ?? ($cur['own_base_url'] ?? ''));
            $model = (string) ($data['own_model'] ?? ($cur['own_model'] ?? ''));
            $hasKey = isset($data['own_api_key']) || $this->decryptKey($cur['own_api_key'] ?? null) !== '';
            if ($url === '' || $model === '' || !$hasKey) {
                throw new \RuntimeException('Enter the Base URL, Model and API key to use your own API.');
            }
            $this->assertSafeBaseUrl($url);
        }
        $data['use_own_key'] = $wantsOwn ? 1 : 0;

        if ($cur) {
            DB::table('ai_user_settings')->where('user_id', $userId)->update($data + ['updated_at' => now()]);
        } else {
            DB::table('ai_user_settings')->insert($data + ['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
        unset($this->cache[$userId]);

        return $this->get($userId);
    }

    public function clearKey(int $userId): array
    {
        DB::table('ai_user_settings')->where('user_id', $userId)->update(['own_api_key' => null, 'use_own_key' => 0, 'updated_at' => now()]);
        unset($this->cache[$userId]);
        return $this->get($userId);
    }

    /** Extra system-prompt block from the user's personalization ('' when nothing is set). */
    public function promptText(int $userId): string
    {
        $r = $this->row($userId);
        if (!$r) {
            return '';
        }
        $lines = [];
        if (!empty($r['nickname'])) {
            $lines[] = 'The user prefers to be called: ' . $r['nickname'];
        }
        if (!empty($r['about_you'])) {
            $lines[] = 'About the user (their own words): ' . $r['about_you'];
        }
        if (!empty($r['response_instructions'])) {
            $lines[] = 'How the user wants you to respond: ' . $r['response_instructions'];
        }
        $tone = [
            'friendly' => 'warm and friendly', 'professional' => 'professional and formal', 'concise' => 'direct, no filler',
            'casual' => 'casual and relaxed', 'encouraging' => 'encouraging and supportive',
        ][$r['tone'] ?? 'default'] ?? null;
        if ($tone) {
            $lines[] = "Preferred tone: {$tone}.";
        }
        $len = ['short' => 'Keep answers short unless the user asks for more.', 'detailed' => 'Give thorough, detailed answers with examples.'][$r['response_length'] ?? 'default'] ?? null;
        if ($len) {
            $lines[] = $len;
        }
        if (!$lines) {
            return '';
        }
        return "[User personalization — follow when it does not conflict with the platform rules above]\n" . implode("\n", $lines);
    }

    /** Effective generation overrides for this user. @return array{temperature:?float,max_tokens:?int} */
    public function generationOverrides(int $userId): array
    {
        $r = $this->row($userId) ?? [];
        return [
            'temperature' => isset($r['temperature']) ? (float) $r['temperature'] : null,
            'max_tokens'  => isset($r['max_tokens']) ? (int) $r['max_tokens'] : null,
        ];
    }

    public function ownKeyActive(int $userId): bool
    {
        $r = $this->row($userId);
        return $r !== null && !empty($r['use_own_key']) && $this->userKeysAllowed()
            && !empty($r['own_base_url']) && !empty($r['own_model']) && $this->decryptKey($r['own_api_key'] ?? null) !== '';
    }

    /** A copy of the platform client pointed at the user's own provider, or null when not active. */
    public function clientFor(int $userId, AIClient $base): ?AIClient
    {
        if (!$this->ownKeyActive($userId)) {
            return null;
        }
        $r = $this->row($userId);
        try {
            $this->assertSafeBaseUrl((string) $r['own_base_url']);
        } catch (\RuntimeException $e) {
            return null; // DNS changed to a private address after saving → ignore the personal API
        }
        return $base->withOwn((string) $r['own_base_url'], $this->decryptKey($r['own_api_key']), (string) $r['own_model']);
    }

    /** Tests the connection with a 1-token-ish call using the SAVED (or just-typed) values. */
    public function test(int $userId, array $in, AIClient $base): array
    {
        $cur = $this->row($userId) ?? [];
        $url = rtrim(trim((string) ($in['own_base_url'] ?? ($cur['own_base_url'] ?? ''))), '/');
        $model = trim((string) ($in['own_model'] ?? ($cur['own_model'] ?? '')));
        $key = trim((string) ($in['own_api_key'] ?? ''));
        if ($key === '') {
            $key = $this->decryptKey($cur['own_api_key'] ?? null);
        }
        if ($url === '' || $model === '' || $key === '') {
            throw new \RuntimeException('Enter the Base URL, Model and API key first.');
        }
        if (!$this->userKeysAllowed()) {
            throw new \RuntimeException('Using a personal API is disabled by the platform administrator.');
        }
        $this->assertSafeBaseUrl($url);

        $client = $base->withOwn($url, $key, $model);
        $client->setTimeout(20);
        $started = microtime(true);
        $client->complete('Reply with the single word: ok', 'ping', 0.0);

        return ['ok' => true, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    // -- Data controls ----------------------------------------------------------

    public function deleteAllConversations(int $userId): int
    {
        return (int) DB::table('ai_conversations')->where('user_id', $userId)->delete();
    }

    /** Full export of the user's AI chats as a plain array (the controller turns it into a JSON download). */
    public function exportAll(int $userId): array
    {
        $out = [];
        $convs = DB::table('ai_conversations')->where('user_id', $userId)->orderBy('id')->get();
        foreach ($convs as $c) {
            $msgs = DB::table('ai_messages')->where('conversation_id', $c->id)->where('is_deleted', 0)
                ->orderBy('created_at')->orderBy('id')->get(['role', 'content', 'created_at']);
            $out[] = [
                'title'      => $c->title,
                'created_at' => $c->created_at,
                'messages'   => $msgs->map(fn ($m) => ['role' => $m->role, 'content' => $m->content, 'created_at' => $m->created_at])->all(),
            ];
        }
        return ['exported_at' => now()->toIso8601String(), 'conversations' => $out];
    }

    // -- Security ---------------------------------------------------------------

    /**
     * SSRF guard: the server will call this URL, so only public https hosts are allowed
     * (no credentials in the URL, no localhost / private / link-local / metadata addresses).
     * @throws \RuntimeException
     */
    public function assertSafeBaseUrl(string $url): void
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('The Base URL must be a public https:// address (for example https://api.openai.com/v1).');
        }
        $host = strtolower((string) $p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new \RuntimeException('The Base URL must point to a public server.');
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $rec) {
                if (!empty($rec['ip'])) {
                    $ips[] = $rec['ip'];
                } elseif (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new \RuntimeException('Could not resolve the Base URL host.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('The Base URL must point to a public server.');
            }
        }
    }

    private function decryptKey(?string $enc): string
    {
        if ($enc === null || $enc === '') {
            return '';
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
