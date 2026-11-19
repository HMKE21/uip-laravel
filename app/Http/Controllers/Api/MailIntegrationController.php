<?php

namespace App\Http\Controllers\Api;

use App\Services\Mail\MailboxService;
use App\Services\Mail\MailIntegrationService;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;

/**
 * /api/v1/mail (المرحلة 6): ربط البريد بالشات والاجتماعات والـ Drive والـ AI + إعدادات إشعارات البريد.
 * بيرث MailApiController عشان نفس اختيار الصندوق (account_id / X-Mail-Account) ونفس فحص الإيقاف.
 */
class MailIntegrationController extends MailApiController
{
    public function __construct(MailboxService $mail, MailSettingsService $settings, private MailIntegrationService $integ)
    {
        parent::__construct($mail, $settings);
    }

    public function links(Request $r, int $id)
    {
        return $this->run($r, fn ($a) => ['links' => $this->integ->links($a, $id)]);
    }

    public function shareChat(Request $r, int $id)
    {
        $in = $r->validate(['conversation_id' => 'nullable|integer', 'email' => 'nullable|email|max:190', 'note' => 'nullable|string|max:500']);
        return $this->run($r, fn ($a) => $this->integ->shareToChat($a, $id, $in), 201);
    }

    public function meeting(Request $r, int $id)
    {
        $in = $r->validate(['title' => 'nullable|string|max:200', 'description' => 'nullable|string|max:5000', 'scheduled_start_at' => 'nullable|date', 'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'invite' => 'nullable|boolean', 'message' => 'nullable|string|max:1000']);
        return $this->run($r, fn ($a) => $this->integ->createMeeting($a, $id, $in), 201);
    }

    public function saveDrive(Request $r, int $id)
    {
        $in = $r->validate(['what' => 'nullable|string|in:message,attachments,all', 'folder_id' => 'nullable|integer']);
        return $this->run($r, fn ($a) => $this->integ->saveToDrive($a, $id, $in), 201);
    }

    public function aiStatus(Request $r)
    {
        return $this->run($r, fn () => ['available' => $this->integ->aiAvailable()]);
    }

    public function ai(Request $r, string $action)
    {
        $in = $r->validate(['item_id' => 'nullable|integer', 'text' => 'nullable|string|max:20000', 'tone' => 'nullable|string|max:20', 'language' => 'nullable|string|max:5', 'instruction' => 'nullable|string|max:500']);
        return $this->run($r, fn ($a) => $this->integ->ai($a, $action, $in), 200);
    }

    public function notificationPrefs(Request $r)
    {
        return $this->run($r, fn ($a) => $this->integ->notificationPrefs($a));
    }

    public function saveNotificationPrefs(Request $r)
    {
        $in = $r->validate(['mode' => 'sometimes|string|in:all,vip,off', 'vip_senders' => 'sometimes|array|max:50', 'vip_senders.*' => 'nullable|string|max:190',
            'mute_until' => 'nullable|date', 'mute_minutes' => 'nullable|integer|min:0|max:43200']);
        return $this->run($r, fn ($a) => $this->integ->saveNotificationPrefs($a, $in));
    }
}
