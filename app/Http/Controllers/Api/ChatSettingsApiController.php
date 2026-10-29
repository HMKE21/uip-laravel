<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatSettingsService;
use Illuminate\Http\Request;

/**
 * /api/v1/messaging/settings — إعدادات الشات الاحترافية (كل الأدوار).
 * المستخدم الفاعل دايمًا من uip_user_id (uip.auth)، والصلاحية messaging.use متطبقة على المجموعة في routes/api.php.
 */
class ChatSettingsApiController extends Controller
{
    public function __construct(private ChatSettingsService $settings)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** GET /settings — كل الإعدادات + البايو + أسماء الناس في القوايم. */
    public function show(Request $request)
    {
        return $this->apiSuccess($this->settings->forScreen($this->uid($request)), 'Chat settings retrieved successfully.');
    }

    /** PATCH /settings — تحديث جزئي (privacy.{field}.{audience,ids}, bio, wallpaper.{default,per_chat,anim}, ...). */
    public function update(Request $request)
    {
        try {
            $in = $request->only([
                'privacy', 'bio', 'read_receipts_enabled', 'typing_indicator', 'discoverable', 'notify_messages', 'notify_preview',
                'stories_allow_replies', 'stories_expiry_hours', 'enter_to_send', 'font_size', 'sounds', 'wallpaper',
            ]);
            return $this->apiSuccess($this->settings->update($this->uid($request), $in), 'Chat settings saved successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }
}
