<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AvatarPrivacyService;
use Illuminate\Http\Request;

/**
 * /api/v1/account/photo-privacy — متاح لأي مستخدم مسجّل دخول (كل البوابات).
 * GET   → { public: bool }   هل صورتي ظاهرة للناس؟
 * PATCH → body: public (bool)
 */
class AccountPrivacyApiController extends Controller
{
    public function __construct(private AvatarPrivacyService $avatars)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    public function show(Request $request)
    {
        return $this->apiSuccess(['public' => $this->avatars->isPublic($this->uid($request))], 'Photo privacy retrieved successfully.');
    }

    public function update(Request $request)
    {
        $request->validate(['public' => 'required']);
        $public = filter_var($request->input('public'), FILTER_VALIDATE_BOOLEAN);
        $this->avatars->setPublic($this->uid($request), $public);

        return $this->apiSuccess(['public' => $public], 'Photo privacy saved successfully.');
    }
}
