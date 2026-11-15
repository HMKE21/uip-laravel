<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AIClient;
use App\Services\AiUserSettingsService;
use Illuminate\Http\Request;

/** Per-user AI assistant settings (personalization, personal API, data controls). Everything is scoped to the authenticated user. */
class AiUserSettingsController extends Controller
{
    public function __construct(private AiUserSettingsService $svc, private AIClient $client)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    public function show(Request $request)
    {
        return $this->apiSuccess($this->svc->get($this->uid($request)), 'Settings retrieved successfully.');
    }

    public function update(Request $request)
    {
        try {
            $data = $this->svc->save($this->uid($request), $request->only([
                'nickname', 'about_you', 'response_instructions', 'tone', 'response_length', 'temperature', 'max_tokens',
                'use_own_key', 'own_base_url', 'own_model', 'own_api_key',
            ]));
            return $this->apiSuccess($data, 'Settings saved successfully.');
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    public function testKey(Request $request)
    {
        try {
            return $this->apiSuccess($this->svc->test($this->uid($request), $request->only(['own_base_url', 'own_model', 'own_api_key']), $this->client), 'Connection works.');
        } catch (\Throwable $e) {
            // Provider errors are already readable (e.g. "API key is missing or invalid"); never echo the key.
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    public function deleteKey(Request $request)
    {
        return $this->apiSuccess($this->svc->clearKey($this->uid($request)), 'API key removed.');
    }

    public function deleteAllChats(Request $request)
    {
        $n = $this->svc->deleteAllConversations($this->uid($request));
        return $this->apiSuccess(['deleted' => $n], 'Conversations deleted.');
    }

    public function exportAll(Request $request)
    {
        $json = json_encode($this->svc->exportAll($this->uid($request)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        return response($json, 200, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="uipe-ai-chats.json"',
        ]);
    }
}
