<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\AuditLogService;
use App\Services\Identity\LoginHistoryService;
use App\Services\Identity\PasskeyService;
use App\Services\RoleService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/**
 * UIPE Mail — المرحلة 5: Passkeys.
 *  عام:    GET /auth/passkeys/config · POST /auth/passkeys/login/options · POST /auth/passkeys/login
 *  بتوكن:  GET /auth/passkeys · POST /auth/passkeys/register/options · POST /auth/passkeys/register · PATCH|DELETE /auth/passkeys/{id}
 * الدخول بيعدّي على LoginController::finishLogin (نفس حراسات الباسورد) والـ passkey بتغني عن كود 2FA (UV إجباري).
 * زي جوجل: أدوار الستاف مبتدخلش بيها.
 */
class PasskeyController extends Controller
{
    private const STAFF_ROLES = ['admin', 'data_analyst', 'security_admin', 'security_officer'];

    public function __construct(
        private PasskeyService $passkeys,
        private LoginController $login,
        private RoleService $roles,
        private AccountLockoutService $lockout,
        private LoginHistoryService $history,
        private AuditLogService $audit
    ) {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function run(callable $fn, int $okStatus = 200)
    {
        try {
            return $this->apiSuccess($fn(), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }

    public function config()
    {
        return $this->apiSuccess(['enabled' => $this->passkeys->enabled()]);
    }

    public function loginOptions()
    {
        return $this->run(fn () => $this->passkeys->loginOptions());
    }

    public function loginSubmit(Request $request)
    {
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
        $generic = $locale === 'ar' ? 'فشل الدخول بمفتاح المرور. جرّب تاني أو ادخل بكلمة السر.' : 'Passkey sign-in failed. Try again or use your password.';
        $cred = $request->input('credential');
        if (!is_array($cred)) {
            return $this->apiError($generic, null, 422);
        }
        try {
            $userId = $this->passkeys->finishLogin((string) $request->input('ceremony_id'), $cred);
        } catch (\DomainException $e) {
            if (in_array((int) $e->getCode(), [503, 410], true)) {
                return $this->apiError($e->getMessage(), null, (int) $e->getCode());
            }
            $this->history->record($request, null, false, 'passkey', 'passkey_invalid');
            SecurityLog::write('Passkey sign-in failed', ['ip' => $request->ip()]);
            return $this->apiError($generic, null, 422);
        }

        $user = User::find($userId);
        if (!$user) {
            $this->history->record($request, $userId, false, 'passkey', 'account_missing');
            return $this->apiError($generic, null, 422);
        }
        $role = $this->roles->primaryRoleFor((int) $user->id);
        if (!\App\Services\Tenancy\TenantContext::active() && in_array($role, self::STAFF_ROLES, true)) {
            SecurityLog::write('Passkey sign-in refused - staff account', ['user_id' => $user->id, 'ip' => $request->ip()]);
            $this->history->record($request, (int) $user->id, false, 'passkey', 'staff_refused');
            return $this->apiError($generic, null, 422);
        }
        $lock = $this->lockout->checkStatus($user, $locale);
        if ($lock['locked']) {
            $this->history->record($request, (int) $user->id, false, 'passkey', 'locked');
            return $this->apiError($lock['message'], null, 422);
        }
        if (in_array($user->status, ['suspended', 'banned'], true)) {
            $this->history->record($request, (int) $user->id, false, 'passkey', 'suspended');
            return $this->apiError('This account has been ' . $user->status . '. Contact support.', null, 422);
        }
        return $this->login->finishLogin($request, $user, $role, $locale, true);
    }

    public function index(Request $r)
    {
        return $this->run(fn () => ['enabled' => $this->passkeys->enabled(), 'items' => $this->passkeys->list($this->uid($r))]);
    }

    private function staff(Request $r): bool
    {
        return !\App\Services\Tenancy\TenantContext::active() && in_array((string) $r->attributes->get('uip_role'), self::STAFF_ROLES, true);
    }

    public function registerOptions(Request $r)
    {
        if ($this->staff($r)) {
            return $this->apiError('Passkeys are not available for this account type.', null, 403);
        }
        return $this->run(fn () => $this->passkeys->registrationOptions($this->uid($r)));
    }

    public function register(Request $r)
    {
        if ($this->staff($r)) {
            return $this->apiError('Passkeys are not available for this account type.', null, 403);
        }
        $cred = $r->input('credential');
        if (!is_array($cred)) {
            return $this->apiError('credential is required.', null, 422);
        }
        return $this->run(function () use ($r, $cred) {
            $row = $this->passkeys->finishRegistration($this->uid($r), $cred, $r->input('name'));
            SecurityLog::write('Passkey registered', ['user_id' => $this->uid($r), 'ip' => $r->ip()]);
            $this->audit->record($this->uid($r), 'identity.passkey_registered', 'Passkey', $row['id'], null, ['name' => $row['name']], $r->ip());
            return $row;
        }, 201);
    }

    public function rename(Request $r, int $id)
    {
        return $this->run(fn () => $this->passkeys->rename($this->uid($r), $id, (string) $r->input('name')));
    }

    public function destroy(Request $r, int $id)
    {
        return $this->run(function () use ($r, $id) {
            $this->passkeys->revoke($this->uid($r), $id);
            SecurityLog::write('Passkey removed', ['user_id' => $this->uid($r), 'ip' => $r->ip()]);
            $this->audit->record($this->uid($r), 'identity.passkey_removed', 'Passkey', $id, null, null, $r->ip());
            return null;
        });
    }
}
