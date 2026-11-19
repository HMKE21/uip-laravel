<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailTenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/mail-admin — UIPE Mail المرحلة 4: لوحة أدمن الجامعة (tenant، نطاقات، سياسات، صناديق الأعضاء، Provisioning).
 *  - university: كل الصلاحيات على جامعته.   - faculty: عرض صناديق كليته بس.
 *  - admin (المنصة): كل الجامعات عن طريق university_id + تفعيل النطاقات + إيقاف الجامعة كلها.
 */
class MailTenantApiController extends Controller
{
    public function __construct(private MailTenantService $tenants)
    {
    }

    /** @return array{role:string,universityId:int,facultyId:?int}|null */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        $uid = (int) $r->attributes->get('uip_user_id');
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $uid)->value('id');
            return $id ? ['role' => $role, 'universityId' => (int) $id, 'facultyId' => null] : null;
        }
        if ($role === 'faculty') {
            $f = DB::table('faculties')->where('user_id', $uid)->first(['id', 'university_id']);
            return $f ? ['role' => $role, 'universityId' => (int) $f->university_id, 'facultyId' => (int) $f->id] : null;
        }
        if ($role === 'admin') {
            $id = (int) ($r->input('university_id') ?: $r->query('university_id'));
            return $id && DB::table('universities')->where('id', $id)->exists() ? ['role' => $role, 'universityId' => $id, 'facultyId' => null] : null;
        }
        return null;
    }

    /** $write = العملية بتغيّر حاجة (الكلية ممنوعة). */
    private function run(Request $r, callable $fn, bool $write = false, int $okStatus = 200)
    {
        try {
            $s = $this->scope($r);
            if (!$s) {
                return $this->apiError('Your account is not linked to a university mail tenant.', null, 403);
            }
            if ($write && $s['role'] === 'faculty') {
                return $this->apiError('University admins only.', null, 403);
            }
            return $this->apiSuccess($fn($s), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    private function platformOnly(Request $r, callable $fn, int $okStatus = 200)
    {
        if ($r->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Admins only.', null, 403);
        }
        try {
            return $this->apiSuccess($fn(), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    /* ---- جامعة / كلية / أدمن المنصة (university_id) ---- */

    public function overview(Request $r)
    {
        return $this->run($r, fn ($s) => $this->tenants->overview($s['universityId']));
    }

    public function updatePolicies(Request $r)
    {
        $in = $r->validate([
            'quota_student_mb' => 'sometimes|integer|min:1|max:102400', 'quota_staff_mb' => 'sometimes|integer|min:1|max:102400', 'quota_admin_mb' => 'sometimes|integer|min:1|max:102400',
            'max_attachment_mb' => 'sometimes|integer|min:1|max:50', 'max_recipients' => 'sometimes|integer|min:1|max:200',
            'student_send_scope' => 'sometimes|in:platform,university', 'allow_external' => 'sometimes|boolean',
            'auto_provision' => 'sometimes|boolean', 'suspend_on_expiry' => 'sometimes|boolean', 'graduate_grace_days' => 'sometimes|integer|min:0|max:3650',
        ]);
        return $this->run($r, fn ($s) => $this->tenants->updatePolicies($s['universityId'], $in), true);
    }

    public function domains(Request $r)
    {
        return $this->run($r, fn ($s) => ['domains' => $this->tenants->domains($this->tenants->tenantByUniversity($s['universityId']))]);
    }

    public function addDomain(Request $r)
    {
        $in = $r->validate(['domain' => 'required|string|max:120']);
        return $this->run($r, fn ($s) => $this->tenants->addDomain($s['universityId'], $in['domain']), true, 201);
    }

    public function setPrimary(Request $r, int $id)
    {
        return $this->run($r, fn ($s) => ['domains' => $this->tenants->setPrimary($s['universityId'], $id)], true);
    }

    public function removeDomain(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($id) {
            $this->tenants->removeDomain($s['universityId'], $id);
            return null;
        }, true);
    }

    public function members(Request $r)
    {
        return $this->run($r, fn ($s) => $this->tenants->members($s['universityId'], $r->only(['type', 'status', 'q', 'page', 'per_page']), $s['facultyId']));
    }

    public function updateAccount(Request $r, int $id)
    {
        $in = $r->validate([
            'status' => 'sometimes|in:active,suspended', 'suspended_reason' => 'sometimes|nullable|string|max:190', 'quota_mb' => 'sometimes|nullable|integer|min:1|max:102400',
            'display_name' => 'sometimes|nullable|string|max:150', 'local' => 'sometimes|nullable|string|max:64',
        ]);
        return $this->run($r, fn ($s) => $this->tenants->updateAccount($s['universityId'], $id, $in), true);
    }

    public function provision(Request $r)
    {
        $in = $r->validate(['types' => 'required|array|min:1', 'types.*' => 'in:student,staff,faculty,university', 'faculty_id' => 'sometimes|nullable|integer']);
        return $this->run($r, fn ($s) => $this->tenants->provisionMembers($s['universityId'], $in['types'], isset($in['faculty_id']) ? (int) $in['faculty_id'] : null), true);
    }

    /* ---- أدمن المنصة فقط ---- */

    public function allTenants(Request $r)
    {
        return $this->platformOnly($r, fn () => $this->tenants->allTenants((string) $r->query('q', ''), (int) $r->query('page', 1), min(100, max(1, (int) $r->query('per_page', 25)))));
    }

    public function suspendTenant(Request $r, int $universityId)
    {
        $in = $r->validate(['reason' => 'nullable|string|max:190']);
        return $this->platformOnly($r, fn () => $this->tenants->setTenantStatus($universityId, true, $in['reason'] ?? null));
    }

    public function resumeTenant(Request $r, int $universityId)
    {
        return $this->platformOnly($r, fn () => $this->tenants->setTenantStatus($universityId, false));
    }

    public function activateDomain(Request $r, int $id)
    {
        return $this->platformOnly($r, fn () => $this->tenants->setDomainStatus($id, 'active'));
    }

    public function disableDomain(Request $r, int $id)
    {
        return $this->platformOnly($r, fn () => $this->tenants->setDomainStatus($id, 'disabled'));
    }
}
