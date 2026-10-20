<?php

namespace App\Services\Concerns;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;

/**
 * منقولة من app/Services/Concerns/ConfiguresAIClient.php القديمة.
 * تستخدمها كل خدمة App\Services\Ai* — بتلبس فوق إعدادات الأدمن (Settings
 * -> AI في بورتال الأدمن) فوق الأساس اللي AIClient حمّله من .env بالفعل.
 */
trait ConfiguresAIClient
{
    private function applySettingsOverrides(AIClient $client, SettingRepository $settings): void
    {
        $client->applyOverrides([
            'enabled'  => $settings->get('ai_enabled', 'global', null, ''),
            'base_url' => $settings->get('ai_base_url', 'global', null, ''),
            'api_key'  => $settings->get('ai_api_key', 'global', null, ''),
            'model'    => $settings->get('ai_model', 'global', null, ''),
        ]);
    }

    /**
     * مساعد completion بصيغة JSON صارمة: بيطلب من الموديل JSON object بس
     * مطابق للـ schema اللي المستدعي محدده، وبيفكّه. بيرمي (أبدًا مايرجعش
     * بيانات مختلقة) لو المزوّد مش متاح أو غير مُعدّ أو رد بحاجة مش JSON صالح.
     */
    private function completeJson(AIClient $client, string $system, string $user): array
    {
        $strictSystem = $system . "\n\nReply with ONLY a single valid JSON object. No markdown fences, no prose, no explanation before or after it.";
        $raw = $client->complete($strictSystem, $user, 0.4);

        $decoded = $this->extractJsonObject($raw);
        if ($decoded === null) {
            throw new \RuntimeException('AI provider returned a non-JSON or malformed response.');
        }
        return $decoded;
    }

    /**
     * بيسحب JSON object من رد موديل خام حتى لو الموديل ملتزمش بـ "JSON
     * only" بالظبط. جرّب بالترتيب: (1) فك مباشر، (2) شيل بلوك <think>
     * وfence ماركداون وفك، (3) fallback للنص بين أول '{' وآخر '}'.
     */
    private function extractJsonObject(string $raw): ?array
    {
        $text = trim($raw);

        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return $direct;
        }

        // شيل مقدمة تفكير <think>...</think> لو موجودة.
        $text = preg_replace('/<think>.*?<\/think>/is', '', $text);
        $text = trim((string) $text);

        // شيل fence ```json ... ``` في أي مكان في النص، مش بس في البداية.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        } elseif (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = preg_replace('/```\s*$/', '', (string) $text);
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // آخر حل: النص من أول '{' لآخر '}'، في حالة الموديل ضاف جملة تعليق حواليه.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** لو لغة المنصة عربي: كل النصوص البشرية في رد الـ AI بالعربي (مفاتيح JSON والـ enums والمسارات والكود تفضل إنجليزي). */
    private function languageDirective(): string
    {
        if (app()->getLocale() !== 'ar') {
            return ' Write every human-readable text value in clear, natural English.';
        }
        return ' IMPORTANT — LANGUAGE: write every human-readable text value (summaries, explanations, suggestions, strengths, risks, audience/scope phrases) in clear Modern Standard Arabic. '
            . 'Keep the JSON keys, enum values (e.g. categories, priorities, severities), file paths, identifiers, API/class/method names and code snippets exactly in their original English form — do not translate them.';
    }

    /** سياق مشروع مختصر يتغذى في كل prompt تحليل-AI. */
    private function projectContext(\App\Models\Project $project): string
    {
        $lines = [
            'Title: ' . ($project->title_en ?: $project->title_ar),
            'Category: ' . ($project->category ?: 'Unspecified'),
            'Summary: ' . ($project->summary ?: '(none provided)'),
        ];
        if ($project->description) {
            $lines[] = 'Description: ' . mb_substr((string) $project->description, 0, 4000);
        }
        $tags = $project->tagList();
        if ($tags) {
            $lines[] = 'Tags: ' . implode(', ', $tags);
        }
        $links = \Illuminate\Support\Facades\DB::table('project_links')
            ->where('project_id', $project->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();
        foreach ($links as $link) {
            $label = \App\Models\ProjectLink::defaultLabelFor((string) $link->type);
            $lines[] = $label . ' URL: ' . $link->url;
        }
        $lines[] = "\n--- REAL REPOSITORY EVIDENCE (fetched from GitHub; base your judgement on this, not just the description) ---";
        $lines[] = app(\App\Services\ProjectRepoContextService::class)->snapshot($project);
        $review = app(\App\Services\ProjectRepoContextService::class)->codeReviewEvidence($project);
        if ($review !== '') {
            $lines[] = $review;
        }
        return implode("\n", $lines);
    }
}
