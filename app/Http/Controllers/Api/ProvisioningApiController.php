<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\Identity\ProvisioningImportService;
use App\Services\Identity\ScimService;
use App\Services\Mail\MailMembershipService;
use App\Services\Mail\MailTenantService;
use App\Support\TabularFileReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5 (لوحة الجامعة) تحت /api/v1/mail-admin:
 *  GET  /provisioning/template?type=student|staff      قالب CSV
 *  POST /provisioning/import  (file, type, dry_run, send_email)   استيراد/معاينة — الجامعة (كل الجامعة) والكلية (كليتها بس)
 *  GET  /provisioning/runs                              سجل العمليات
 *  GET|POST /scim/tokens · DELETE /scim/tokens/{id}     توكنز SCIM (الجامعة فقط)
 *  POST /membership/sweep (dry_run)                     تشغيل تعليق/استئناف الصناديق يدويًا (الجامعة فقط)
 *  PUT  /accounts/{id}/expiry (membership_expires_at)   تاريخ انتهاء عضوية صندوق (الجامعة فقط)
 * أدمن المنصة بيمرّر university_id.
 */
class ProvisioningApiController extends Controller
{
    public function __construct(
        private ProvisioningImportService $import,
        private ScimService $scim,
        private MailMembershipService $membership,
        private MailTenantService $tenants,
        private AuditLogService $audit
    ) {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    /** @return array{role:string,universityId:int,facultyId:?int}|null */
    private function scope(Request $r): ?array
    {
        $role = (string) $r->attributes->get('uip_role');
        if ($role === 'university') {
            $id = DB::table('universities')->where('user_id', $this->uid($r))->value('id');
            return $id ? ['role' => $role, 'universityId' => (int) $id, 'facultyId' => null] : null;
        }
        if ($role === 'faculty') {
            $f = DB::table('faculties')->where('user_id', $this->uid($r))->first(['id', 'university_id']);
            return $f ? ['role' => $role, 'universityId' => (int) $f->university_id, 'facultyId' => (int) $f->id] : null;
        }
        if ($role === 'admin') {
            $id = (int) ($r->input('university_id') ?: $r->query('university_id'));
            return $id && DB::table('universities')->where('id', $id)->exists() ? ['role' => $role, 'universityId' => $id, 'facultyId' => null] : null;
        }
        return null;
    }

    /** $universityOnly = الكلية ممنوعة. */
    private function run(Request $r, callable $fn, bool $universityOnly = false, int $okStatus = 200)
    {
        try {
            $s = $this->scope($r);
            if (!$s) {
                return $this->apiError('Your account is not linked to a university.', null, 403);
            }
            if ($universityOnly && $s['role'] === 'faculty') {
                return $this->apiError('University admins only.', null, 403);
            }
            return $this->apiSuccess($fn($s), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    /* ------------------------------------------------------------------ CSV */

    public function template(Request $r)
    {
        $type = (string) $r->query('type', 'student');
        if (!in_array($type, ProvisioningImportService::TYPES, true)) {
            return $this->apiError('type must be student or staff.', null, 422);
        }
        return response(ProvisioningImportService::template($type), 200, [
            'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"uipe-{$type}-import-template.csv\"",
        ]);
    }

    public function importFile(Request $r)
    {
        $file = $r->file('file') ?: $r->file('csv_file');
        if (!$file || !$file->isValid()) {
            return $this->apiError('Validation failed.', ['file' => 'A .csv or .xlsx file is required.'], 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->apiError('File too large (max 5 MB).', null, 413);
        }
        $type = (string) $r->input('type', 'student');
        $dry = $r->boolean('dry_run', false);
        $send = $r->boolean('send_email', false);
        $locale = $r->input('locale') === 'en' ? 'en' : 'ar';
        try {
            $rows = TabularFileReader::readAssoc($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage() ?: 'Could not read the uploaded file.', null, 422);
        }
        return $this->run($r, function ($s) use ($r, $type, $rows, $locale, $dry, $send) {
            $res = $this->import->run($s['universityId'], $this->uid($r), $type, $rows, $s['facultyId'], $locale, $dry, $send);
            if (!$dry) {
                $this->audit->record($this->uid($r), 'identity.csv_import', 'University', $s['universityId'], null, ['type' => $type, 'total' => $res['total'], 'imported' => $res['imported']], $r->ip());
            }
            return $res;
        });
    }

    public function runs(Request $r)
    {
        return $this->run($r, fn ($s) => $this->import->history($s['universityId'], (int) $r->query('page', 1), (int) $r->query('per_page', 20)));
    }

    /* ------------------------------------------------------------------ SCIM tokens */

    public function scimTokens(Request $r)
    {
        return $this->run($r, fn ($s) => [
            'enabled' => (bool) config('identity.scim.enabled', true),
            'base_url' => url('/api/scim/v2'),
            'items' => $this->scim->tokens((int) $this->tenants->tenantByUniversity($s['universityId'])->id),
        ], true);
    }

    public function createScimToken(Request $r)
    {
        $in = $r->validate(['name' => 'required|string|max:80', 'expires_in_days' => 'sometimes|nullable|integer|min:1|max:3650']);
        return $this->run($r, function ($s) use ($r, $in) {
            $t = $this->tenants->tenantByUniversity($s['universityId']);
            $res = $this->scim->createToken((int) $t->id, $in['name'], $this->uid($r), $in['expires_in_days'] ?? null);
            $this->audit->record($this->uid($r), 'identity.scim_token_created', 'ScimToken', $res['id'], null, ['name' => $res['name']], $r->ip());
            return $res + ['base_url' => url('/api/scim/v2')];
        }, true, 201);
    }

    public function revokeScimToken(Request $r, int $id)
    {
        return $this->run($r, function ($s) use ($r, $id) {
            $t = $this->tenants->tenantByUniversity($s['universityId']);
            $this->scim->revokeToken((int) $t->id, $id);
            $this->audit->record($this->uid($r), 'identity.scim_token_revoked', 'ScimToken', $id, null, null, $r->ip());
            return null;
        }, true);
    }

    /* ------------------------------------------------------------------ العضوية */

    public function sweep(Request $r)
    {
        return $this->run($r, function ($s) use ($r) {
            $this->tenants->tenantByUniversity($s['universityId']);
            $dry = $r->boolean('dry_run', true);
            $res = $this->membership->sweep($s['universityId'], $dry);
            if (!$dry && ($res['suspended'] || $res['resumed'])) {
                $this->audit->record($this->uid($r), 'identity.membership_sweep', 'University', $s['universityId'], null, ['suspended' => $res['suspended'], 'resumed' => $res['resumed']], $r->ip());
            }
            return $res + ['dry_run' => $dry];
        }, true);
    }

    public function setExpiry(Request $r, int $id)
    {
        $r->validate(['membership_expires_at' => 'present|nullable|string|max:40']);
        return $this->run($r, fn ($s) => $this->membership->setExpiry($s['universityId'], $id, $r->input('membership_expires_at')), true);
    }
}
