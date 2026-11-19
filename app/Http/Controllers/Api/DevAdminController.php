<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Developer\DevLogService;
use App\Services\Developer\DevScopes;
use App\Services\Developer\DeveloperAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/dev-admin — إدارة Developer Center:
 *  - university: سياسة جامعته (تفعيل/تعطيل، scopes، حد التطبيقات) + تطبيقات وسجل أعضاء جامعته + تعليق/تفعيل تطبيق.
 *  - faculty: قراءة فقط لجامعته.
 *  - admin (المنصة): كل الجامعات (university_id اختياري للتصفية).
 */
class DevAdminController extends Controller
{
    use RunsDomain;

    public function __construct(private DeveloperAppService $apps, private DevLogService $logs)
    {
    }

    /** @return array{role:string,universityId:?int}|null */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        $uid = $this->uid($r);
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $uid)->value('id');
            return $id ? ['role' => $role, 'universityId' => (int) $id] : null;
        }
        if ($role === 'faculty') {
            $uni = DB::table('faculties')->where('user_id', $uid)->value('university_id');
            return $uni ? ['role' => $role, 'universityId' => (int) $uni] : null;
        }
        if ($role === 'admin') {
            $id = (int) ($r->input('university_id') ?: $r->query('university_id'));
            return ['role' => $role, 'universityId' => $id ?: null];
        }
        return null;
    }

    private function run(Request $r, callable $fn, bool $write = false, int $ok = 200)
    {
        $s = $this->scope($r);
        if (!$s) {
            return $this->apiError('Your account is not linked to a university.', null, 403);
        }
        if ($write && $s['role'] === 'faculty') {
            return $this->apiError('University admins only.', null, 403);
        }
        return $this->dom(fn () => $fn($s), $ok);
    }

    public function policy(Request $r)
    {
        return $this->run($r, function ($s) {
            if (!$s['universityId']) {
                throw new \DomainException('Choose a university.', 422);
            }
            return ['policy' => $this->apps->policyForUniversity($s['universityId']), 'scopes' => DevScopes::catalog()];
        });
    }

    public function savePolicy(Request $r)
    {
        $in = $r->validate(['apps_enabled' => 'sometimes|boolean', 'webhooks_enabled' => 'sometimes|boolean', 'max_apps_per_user' => 'nullable|integer|min:0|max:100',
            'allowed_scopes' => 'nullable|array', 'allowed_scopes.*' => 'string|max:40']);
        return $this->run($r, function ($s) use ($in) {
            if (!$s['universityId']) {
                throw new \DomainException('Choose a university.', 422);
            }
            return ['policy' => $this->apps->savePolicy($s['universityId'], $in)];
        }, true);
    }

    public function apps(Request $r)
    {
        return $this->run($r, fn ($s) => $this->apps->adminApps(['university_id' => $s['universityId'], 'status' => $r->query('status'), 'q' => $r->query('q')], (int) $r->query('page', 1), (int) $r->query('per_page', 30)));
    }

    public function suspend(Request $r, int $id)
    {
        $in = $r->validate(['reason' => 'nullable|string|max:200']);
        return $this->run($r, fn ($s) => ['app' => $this->apps->adminSetStatus($id, $s['universityId'], true, $s['role'], $in['reason'] ?? null)], true);
    }

    public function resume(Request $r, int $id)
    {
        return $this->run($r, fn ($s) => ['app' => $this->apps->adminSetStatus($id, $s['universityId'], false, $s['role'])], true);
    }

    public function logs(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            $f = $r->only(['app_id', 'kind', 'status', 'from', 'to', 'q', 'user_id']);
            if ($s['universityId']) {
                $f['owner_ids'] = $this->apps->ownerIdsOfUniversity($s['universityId']) ?: [0];
            }
            return $this->logs->search($f, (int) $r->query('page', 1), (int) $r->query('per_page', 30));
        });
    }
}
