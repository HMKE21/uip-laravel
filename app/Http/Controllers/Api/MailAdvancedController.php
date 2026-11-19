<?php

namespace App\Http\Controllers\Api;

use App\Services\Mail\MailboxService;
use App\Services\Mail\MailPortabilityService;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;

/**
 * /api/v1/mail — UIPE Mail (المرحلة 3): الإعدادات، التوقيعات، القوالب، الفلاتر، الرد التلقائي، الاستيراد والتصدير.
 * (Snooze / Scheduled / Undo Send / Confidential بتتعامل معاها MailApiController: bulk, send, release, cancel-send, unlock, revoke.)
 */
class MailAdvancedController extends MailApiController
{
    public function __construct(MailboxService $mail, MailSettingsService $settings, protected MailPortabilityService $portability)
    {
        parent::__construct($mail, $settings);
    }

    /* settings */
    public function settings(Request $r)
    {
        return $this->run($r, fn ($a) => $this->settings->settingsPayload($a));
    }

    public function saveSettings(Request $r)
    {
        $in = $r->validate(['undo_seconds' => 'sometimes|integer|min:0|max:30', 'sig_new_id' => 'sometimes|nullable|integer', 'sig_reply_id' => 'sometimes|nullable|integer']);
        return $this->run($r, fn ($a) => $this->settings->saveSettings($a, $in));
    }

    /* signatures */
    public function signatures(Request $r)
    {
        return $this->run($r, fn ($a) => ['signatures' => $this->settings->signatures($a)]);
    }

    public function storeSignature(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:100', 'body_html' => 'nullable|string']);
        return $this->run($r, fn ($a) => $this->settings->saveSignature($a, $in), 201);
    }

    public function updateSignature(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|max:100', 'body_html' => 'nullable|string']);
        return $this->run($r, fn ($a) => $this->settings->saveSignature($a, $in, $id));
    }

    public function destroySignature(Request $r, int $id)
    {
        return $this->run($r, function ($a) use ($id) { $this->settings->deleteSignature($a, $id); return null; });
    }

    /* templates */
    public function templates(Request $r)
    {
        return $this->run($r, fn ($a) => ['templates' => $this->settings->templates($a)]);
    }

    public function storeTemplate(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:100', 'subject' => 'nullable|string|max:250', 'body_html' => 'nullable|string']);
        return $this->run($r, fn ($a) => $this->settings->saveTemplate($a, $in), 201);
    }

    public function updateTemplate(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|max:100', 'subject' => 'nullable|string|max:250', 'body_html' => 'nullable|string']);
        return $this->run($r, fn ($a) => $this->settings->saveTemplate($a, $in, $id));
    }

    public function destroyTemplate(Request $r, int $id)
    {
        return $this->run($r, function ($a) use ($id) { $this->settings->deleteTemplate($a, $id); return null; });
    }

    /* filters */
    private function filterRules(): array
    {
        return [
            'name' => 'nullable|string|max:100',
            'conditions' => 'required|array',
            'conditions.from' => 'nullable|string|max:190', 'conditions.to' => 'nullable|string|max:190', 'conditions.subject' => 'nullable|string|max:190',
            'conditions.has_words' => 'nullable|string|max:190', 'conditions.not_words' => 'nullable|string|max:190', 'conditions.has_attachment' => 'nullable|boolean',
            'actions' => 'required|array',
            'actions.label_id' => 'nullable|integer', 'actions.archive' => 'nullable|boolean', 'actions.mark_read' => 'nullable|boolean',
            'actions.star' => 'nullable|boolean', 'actions.spam' => 'nullable|boolean', 'actions.trash' => 'nullable|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function filters(Request $r)
    {
        return $this->run($r, fn ($a) => ['filters' => $this->settings->filters($a)]);
    }

    public function storeFilter(Request $r)
    {
        $in = $r->validate($this->filterRules());
        return $this->run($r, fn ($a) => $this->settings->saveFilter($a, $in), 201);
    }

    public function updateFilter(Request $r, int $id)
    {
        $in = $r->validate($this->filterRules());
        return $this->run($r, fn ($a) => $this->settings->saveFilter($a, $in, $id));
    }

    /** PATCH /filters/{id} { is_active } — تفعيل/إيقاف بس. */
    public function toggleFilter(Request $r, int $id)
    {
        $in = $r->validate(['is_active' => 'required|boolean']);
        return $this->run($r, fn ($a) => $this->settings->setFilterActive($a, $id, (bool) $in['is_active']));
    }

    public function destroyFilter(Request $r, int $id)
    {
        return $this->run($r, function ($a) use ($id) { $this->settings->deleteFilter($a, $id); return null; });
    }

    /** POST /filters/{id}/apply — طبّق الفلتر على الوارد الحالي (آخر 500 رسالة). */
    public function applyFilter(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => ['affected' => $this->settings->applyFilterToInbox($a, $id), 'folders' => $this->mail->folderCounts($a)]);
    }

    /* vacation */
    public function vacation(Request $r)
    {
        return $this->run($r, fn ($a) => $this->settings->vacation($a));
    }

    public function saveVacation(Request $r)
    {
        $in = $r->validate(['enabled' => 'required|boolean', 'subject' => 'nullable|string|max:250', 'body_html' => 'nullable|string',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date']);
        return $this->run($r, fn ($a) => $this->settings->saveVacation($a, $in));
    }

    /* import / export */

    /** POST /import (multipart: file = .mbox | .eml ، folder = inbox | archive) */
    public function import(Request $r)
    {
        $in = $r->validate(['file' => 'required|file|max:102400', 'folder' => 'nullable|string|in:inbox,archive']);
        return $this->run($r, fn ($a) => $this->portability->import($a, $r->file('file'), $in['folder'] ?? 'inbox'));
    }

    /** GET /export?format=mbox|json&folder=all|inbox|sent|archive|spam|trash */
    public function export(Request $r)
    {
        $in = $r->validate(['format' => 'nullable|string|in:mbox,json', 'folder' => 'nullable|string|in:all,inbox,sent,archive,spam,trash']);
        try {
            $a = $this->accountOf($r);
            $format = $in['format'] ?? 'mbox';
            $folder = $in['folder'] ?? 'all';
            $base = 'uipe-mail-' . $folder . '-' . now()->format('Ymd-His');
            if ($format === 'json') {
                $data = $this->portability->exportJson($a, $folder); // بيرمي DomainException قبل أي output لو المجلد غلط
                return response()->streamDownload(function () use ($data) {
                    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                }, $base . '.json', ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
            }
            return response()->streamDownload(function () use ($a, $folder) {
                $this->portability->streamMbox($a, $folder);
            }, $base . '.mbox', ['Content-Type' => 'application/mbox', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }
}
