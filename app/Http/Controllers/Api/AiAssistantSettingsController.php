<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AiAssistantRepository;
use App\Services\AiAssistantService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Admin/AiAssistantSettingsController.php
 * القديمة — /api/v1/admin/ai-assistant-settings/*. بتظبط إعدادات
 * `ai_assistant_*` (temperature/max tokens/system prompt/allowed
 * extensions/retention/rate limit/logging/portal prompts) — مش نفس
 * مفاتيح الاتصال الأساسية `ai_enabled`/`ai_base_url`/`ai_api_key`/
 * `ai_model` اللي AdminSettingsController العام بيدير. برضه بيعرض
 * ملخص AI Analytics (usageSummary()).
 */
class AiAssistantSettingsController extends Controller
{
    public function __construct(
        private AiAssistantService $assistant,
        private AiAssistantRepository $repo,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/admin/ai-assistant-settings */
    public function show(Request $request)
    {
        return $this->apiSuccess($this->assistant->adminSettings(), 'Settings retrieved successfully.');
    }

    /** PUT /api/v1/admin/ai-assistant-settings */
    public function update(Request $request)
    {
        $before = $this->assistant->adminSettings();
        $this->assistant->updateAdminSettings($request->all());
        $after = $this->assistant->adminSettings();

        $this->auditLog->record(
            (int) $request->attributes->get('uip_user_id'),
            'ai_assistant.settings_updated',
            'ai_assistant_settings',
            null,
            $before,
            $after,
            $request->ip()
        );

        return $this->apiSuccess($after, 'Settings updated successfully.');
    }

    /** GET /api/v1/admin/ai-assistant-settings/analytics — query: days */
    public function analytics(Request $request)
    {
        $days = max(1, min(365, (int) $request->query('days', 30)));
        return $this->apiSuccess($this->repo->usageSummary($days), 'Analytics retrieved successfully.');
    }
}
