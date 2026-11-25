<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetworkService;
use App\Services\UsernameService;
use Illuminate\Http\Request;

/**
 * /api/v1/username/* — @username (زي تليجرام). كل المسارات تحت uip.auth (أي دور).
 *  GET    /username                 يوزرنيمي + وقت السماح بالتغيير القادم
 *  GET    /username/check?u=        هل متاح؟ { available, code, message }
 *  GET    /username/suggest         اقتراحات متاحة
 *  PUT    /username   {username}    تعيين/تغيير ("" = مسح)
 *  DELETE /username                 مسح
 *  GET    /username/lookup/{handle} كارت الشخص من @handle (للمنشن في الشات وفتح بروفايله/محادثته)
 */
class UsernameApiController extends Controller
{
    public function __construct(private UsernameService $usernames, private NetworkService $network)
    {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    public function show(Request $request)
    {
        return $this->apiSuccess($this->usernames->mine($this->uid($request)), 'Username retrieved successfully.');
    }

    public function check(Request $request)
    {
        return $this->apiSuccess($this->usernames->check((string) $request->input('u', ''), $this->uid($request)), 'Username checked.');
    }

    public function suggest(Request $request)
    {
        return $this->apiSuccess($this->usernames->suggest($this->uid($request)), 'Suggestions retrieved successfully.');
    }

    public function update(Request $request)
    {
        try {
            return $this->apiSuccess($this->usernames->set($this->uid($request), (string) $request->input('username', '')), 'Username saved successfully.');
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    public function destroy(Request $request)
    {
        return $this->apiSuccess($this->usernames->set($this->uid($request), ''), 'Username removed.');
    }

    public function lookup(Request $request, string $handle)
    {
        $id = $this->usernames->idFor($handle);
        if (!$id) {
            return $this->apiError('User not found.', null, 404);
        }
        try {
            return $this->apiSuccess($this->network->person($this->uid($request), $id), 'Person retrieved successfully.');
        } catch (\RuntimeException $e) { // محظور أو غير نشط
            return $this->apiError('User not found.', null, 404);
        }
    }
}
