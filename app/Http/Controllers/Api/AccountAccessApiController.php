<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountAccessGate;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\StudentJoinRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * بوابة الحساب المرتبط بجامعة — إدارة الوصول (شوف AccountAccessGate).
 *
 * الطالب:
 *   GET  students/me/access                       حالته + المميزات المسموحة + منحه + آخر طلب انضمام
 *
 * المدير (admin | university | faculty) — كله تحت prefix `access`:
 *   GET    access/features
 *   GET    access/plans                 قايمة الأسعار بتاعته (الأدمن بيشوف باقات كل الأدمنز)
 *   POST   access/plans                 {name_en,name_ar,price,currency,duration_days,features[],description?}
 *   PATCH  access/plans/{id}            + is_active
 *   DELETE access/plans/{id}            بيعطّلها (المنح القديمة تفضل زي ما هي)
 *   GET    access/accounts?q=           بحث عن حسابات طلاب مش مرتبطة بجامعة (بالاسم/الإيميل) + حالة كل واحد
 *   GET    access/grants?status=active|pending|expired|revoked|all
 *   POST   access/grants                {user_id|email, plan_id? | features[]+price+duration_days, paid?, note?}
 *   POST   access/grants/{id}/mark-paid
 *   POST   access/grants/{id}/extend    {days}
 *   POST   access/grants/{id}/revoke
 *
 * الكلية:
 *   GET  faculty/join-requests?status=pending|all
 *   POST faculty/join-requests/{id}/approve | reject      (نفس منطق الجامعة بالظبط)
 *
 * الدفع هنا تسجيل يدوي (الموافِق بيأكد إنه استلم المبلغ) — مفيش بوابة دفع.
 */
class AccountAccessApiController extends Controller
{
    private const MANAGERS = ['admin', 'university', 'faculty'];

    public function __construct(
        private AccountAccessGate $gate,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private StudentJoinRequestService $joinRequests
    ) {
    }

    private function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }

    private function role(Request $r): string
    {
        return (string) $r->attributes->get('uip_role');
    }

    private function denyUnlessManager(Request $r)
    {
        return in_array($this->role($r), self::MANAGERS, true) ? null : $this->apiError('Only admin, university or faculty accounts can manage access.', null, 403);
    }

    // ------------------------------------------------------------ student

    public function myAccess(Request $request)
    {
        $uid = $this->uid($request);
        $st = $this->gate->status($uid, $this->role($request));
        $history = DB::table('account_access_grants')->where('user_id', $uid)->orderByDesc('id')->limit(30)->get()
            ->map(fn ($g) => $this->grantRow($g))->all();

        return $this->apiSuccess($st + ['history' => $history, 'features_catalog' => AccountAccessGate::FEATURES]);
    }

    // ------------------------------------------------------------ manager: catalog

    public function features(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }

        return $this->apiSuccess(AccountAccessGate::FEATURES);
    }

    // ------------------------------------------------------------ manager: plans

    private function plansQuery(Request $r)
    {
        $q = DB::table('access_plans');

        return $this->role($r) === 'admin' ? $q->where('owner_role', 'admin') : $q->where('owner_user_id', $this->uid($r));
    }

    private function planRow($p): array
    {
        return [
            'id' => (int) $p->id, 'name_en' => $p->name_en, 'name_ar' => $p->name_ar, 'description' => $p->description,
            'price' => (float) $p->price, 'currency' => $p->currency, 'duration_days' => $p->duration_days !== null ? (int) $p->duration_days : null,
            'features' => json_decode($p->features, true) ?: [], 'is_active' => (bool) $p->is_active,
            'owner_role' => $p->owner_role, 'created_at' => $p->created_at,
        ];
    }

    public function plans(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $rows = $this->plansQuery($request)->orderByDesc('is_active')->orderBy('price')->get()->map(fn ($p) => $this->planRow($p))->all();

        return $this->apiSuccess($rows);
    }

    /** @return array{0:?array,1:?\Illuminate\Http\JsonResponse} */
    private function validatedPlan(Request $request, bool $partial)
    {
        $req = $partial ? 'sometimes' : 'required';
        $v = validator($request->all(), [
            'name_en' => "$req|string|max:120", 'name_ar' => "$req|string|max:120", 'description' => 'nullable|string|max:1000',
            'price' => "$req|numeric|min:0|max:99999999", 'currency' => 'sometimes|string|size:3|alpha',
            'duration_days' => 'nullable|integer|min:1|max:3650', 'features' => "$req|array|min:1", 'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails()) {
            return [null, $this->apiError('Validation failed.', $v->errors()->toArray(), 422)];
        }
        $d = $v->validated();
        if (isset($d['features'])) {
            try {
                $d['features'] = AccountAccessGate::validFeatures($d['features']);
            } catch (\InvalidArgumentException $e) {
                return [null, $this->apiError($e->getMessage(), ['features' => [$e->getMessage()]], 422)];
            }
        }
        if (isset($d['currency'])) {
            $d['currency'] = strtoupper($d['currency']);
        }

        return [$d, null];
    }

    public function storePlan(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        [$d, $err] = $this->validatedPlan($request, false);
        if ($err) {
            return $err;
        }
        $id = DB::table('access_plans')->insertGetId([
            'owner_user_id' => $this->uid($request), 'owner_role' => $this->role($request),
            'name_en' => $d['name_en'], 'name_ar' => $d['name_ar'], 'description' => $d['description'] ?? null,
            'price' => $d['price'], 'currency' => $d['currency'] ?? 'EGP', 'duration_days' => $d['duration_days'] ?? null,
            'features' => json_encode($d['features']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditLog->record($this->uid($request), 'access_plan.created', 'AccessPlan', $id, null, $d, $request->ip());

        return $this->apiSuccess($this->planRow(DB::table('access_plans')->find($id)), 'Plan created.', 201);
    }

    public function updatePlan(Request $request, int $id)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $plan = $this->plansQuery($request)->where('id', $id)->first();
        if (!$plan) {
            return $this->apiError('Plan not found.', null, 404);
        }
        [$d, $err] = $this->validatedPlan($request, true);
        if ($err) {
            return $err;
        }
        if (isset($d['features'])) {
            $d['features'] = json_encode($d['features']);
        }
        if ($d) {
            DB::table('access_plans')->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->auditLog->record($this->uid($request), 'access_plan.updated', 'AccessPlan', $id, null, array_keys($d), $request->ip());
        }

        return $this->apiSuccess($this->planRow(DB::table('access_plans')->find($id)), 'Plan updated.');
    }

    public function deletePlan(Request $request, int $id)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $n = $this->plansQuery($request)->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
        if (!$n) {
            return $this->apiError('Plan not found.', null, 404);
        }
        $this->auditLog->record($this->uid($request), 'access_plan.disabled', 'AccessPlan', $id, null, null, $request->ip());

        return $this->apiSuccess(['id' => $id], 'Plan disabled.');
    }

    // ------------------------------------------------------------ manager: accounts + grants

    private function grantState($g): string
    {
        if ($g->revoked_at) {
            return 'revoked';
        }
        if ($g->payment_status === 'pending') {
            return 'pending';
        }
        if ($g->expires_at && strtotime((string) $g->expires_at) <= time()) {
            return 'expired';
        }

        return 'active';
    }

    private function grantRow($g, ?object $user = null): array
    {
        return [
            'id' => (int) $g->id, 'user_id' => (int) $g->user_id, 'state' => $this->grantState($g),
            'features' => json_decode($g->features, true) ?: [], 'plan_id' => $g->plan_id,
            'price' => (float) $g->price, 'currency' => $g->currency, 'duration_days' => $g->duration_days !== null ? (int) $g->duration_days : null,
            'payment_status' => $g->payment_status, 'paid_at' => $g->paid_at, 'starts_at' => $g->starts_at, 'expires_at' => $g->expires_at,
            'revoked_at' => $g->revoked_at, 'note' => $g->note, 'granted_by_role' => $g->granted_by_role, 'created_at' => $g->created_at,
            'user' => $user ? ['name' => $user->full_name ?? null, 'email' => $user->email ?? null] : null,
        ];
    }

    public function accounts(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $q = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->whereNull('s.university_id')->whereNull('u.deleted_at');
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('u.email', 'like', $like));
        }
        $page = $q->orderByDesc('u.id')->select('u.id', 'u.full_name', 'u.email', 'u.status', 'u.created_at', 's.id as student_id')
            ->paginate(max(10, min(100, (int) $request->query('per_page', 25))), ['*'], 'page', max(1, (int) $request->query('page', 1)));

        $ids = $page->getCollection()->pluck('id')->all();
        $sids = $page->getCollection()->pluck('student_id')->all();
        $feat = [];
        $req = [];
        if ($ids) {
            foreach ($ids as $id) {
                $feat[$id] = $this->gate->activeGrants((int) $id);
            }
            foreach (DB::table('student_university_requests as r')->leftJoin('universities as un', 'un.id', '=', 'r.university_id')
                ->whereIn('r.student_id', $sids)->orderBy('r.id')
                ->get(['r.student_id', 'r.status', 'r.created_at', 'un.official_name_en as university_en', 'un.official_name_ar as university_ar']) as $r) {
                $req[$r->student_id] = ['status' => $r->status, 'created_at' => $r->created_at, 'university_en' => $r->university_en, 'university_ar' => $r->university_ar];
            }
        }
        $items = $page->getCollection()->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $r->full_name, 'email' => $r->email, 'account_status' => $r->status, 'created_at' => $r->created_at,
            'active_grants' => $feat[$r->id] ?? [], 'request' => $req[$r->student_id] ?? null,
        ])->all();

        return $this->apiSuccess([
            'enabled' => \App\Support\PlatformPolicy::flag('require_university_link'),
            'items' => $items,
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function grantsQuery(Request $r)
    {
        $q = DB::table('account_access_grants as g')->join('users as u', 'u.id', '=', 'g.user_id');

        return $this->role($r) === 'admin' ? $q : $q->where('g.granted_by', $this->uid($r));
    }

    public function grants(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $q = $this->grantsQuery($request);
        $now = now();
        switch ((string) $request->query('status', 'all')) {
            case 'active':
                $q->whereNull('g.revoked_at')->whereIn('g.payment_status', ['free', 'paid'])->where(fn ($w) => $w->whereNull('g.expires_at')->orWhere('g.expires_at', '>', $now));
                break;
            case 'pending':
                $q->whereNull('g.revoked_at')->where('g.payment_status', 'pending');
                break;
            case 'expired':
                $q->whereNull('g.revoked_at')->whereNotNull('g.expires_at')->where('g.expires_at', '<=', $now);
                break;
            case 'revoked':
                $q->whereNotNull('g.revoked_at');
                break;
        }
        $rows = $q->orderByDesc('g.id')->limit(300)->get(['g.*', 'u.full_name', 'u.email'])->map(fn ($g) => $this->grantRow($g, $g))->all();

        return $this->apiSuccess(['items' => $rows]);
    }

    public function grant(Request $request)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $v = validator($request->all(), [
            'user_id' => 'nullable|integer', 'email' => 'nullable|email', 'plan_id' => 'nullable|integer',
            'features' => 'nullable|array', 'price' => 'nullable|numeric|min:0|max:99999999', 'currency' => 'nullable|string|size:3|alpha',
            'duration_days' => 'nullable|integer|min:1|max:3650', 'paid' => 'nullable|boolean', 'note' => 'nullable|string|max:300',
        ]);
        if ($v->fails()) {
            return $this->apiError('Validation failed.', $v->errors()->toArray(), 422);
        }

        $user = $request->filled('user_id')
            ? DB::table('users')->where('id', (int) $request->input('user_id'))->whereNull('deleted_at')->first(['id', 'email'])
            : DB::table('users')->where('email', (string) $request->input('email'))->whereNull('deleted_at')->first(['id', 'email']);
        if (!$user) {
            return $this->apiError('Account not found.', null, 404);
        }
        $stu = DB::table('students')->where('user_id', $user->id)->first(['id', 'university_id']);
        if (!$stu) {
            return $this->apiError('Only student accounts can be granted access.', null, 422);
        }
        if ($stu->university_id) {
            return $this->apiError('This account is already linked to a university; it does not need a grant.', null, 422);
        }

        if ($request->filled('plan_id')) {
            $plan = $this->plansQuery($request)->where('id', (int) $request->input('plan_id'))->where('is_active', 1)->first();
            if (!$plan) {
                return $this->apiError('Plan not found.', null, 404);
            }
            $features = json_decode($plan->features, true) ?: [];
            $price = (float) $plan->price;
            $currency = $plan->currency;
            $days = $plan->duration_days !== null ? (int) $plan->duration_days : null;
            $planId = (int) $plan->id;
        } else {
            if (!$request->filled('features')) {
                return $this->apiError('Choose a plan or send the features to grant.', ['features' => ['required']], 422);
            }
            try {
                $features = AccountAccessGate::validFeatures((array) $request->input('features'));
            } catch (\InvalidArgumentException $ex) {
                return $this->apiError($ex->getMessage(), ['features' => [$ex->getMessage()]], 422);
            }
            $price = (float) $request->input('price', 0);
            $currency = strtoupper((string) $request->input('currency', 'EGP'));
            $days = $request->filled('duration_days') ? (int) $request->input('duration_days') : null;
            $planId = null;
        }
        if (!$features) {
            return $this->apiError('A grant needs at least one feature.', null, 422);
        }

        // مجاني => شغال فورًا. مدفوع: لو الموافِق أكّد الاستلام (paid=true) شغال فورًا، غير كده pending لحد mark-paid.
        $payment = $price <= 0 ? 'free' : ($request->boolean('paid') ? 'paid' : 'pending');
        $active = $payment !== 'pending';
        $start = $active ? now() : null;
        $id = DB::table('account_access_grants')->insertGetId([
            'user_id' => $user->id, 'granted_by' => $this->uid($request), 'granted_by_role' => $this->role($request), 'plan_id' => $planId,
            'features' => json_encode($features), 'price' => $price, 'currency' => $currency, 'duration_days' => $days,
            'payment_status' => $payment, 'paid_at' => $payment === 'paid' ? now() : null,
            'starts_at' => $start, 'expires_at' => $start && $days ? now()->addDays($days) : null,
            'note' => mb_substr(trim((string) $request->input('note', '')), 0, 300) ?: null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($active) {
            $this->notifications->notify($user->id, 'account_access_granted', 'Your account was approved', 'You can now use: ' . implode(', ', $features) . ($days ? " for $days days." : '.'), '/student/dashboard');
        } else {
            $this->notifications->notify($user->id, 'account_access_pending_payment', 'Access approved — payment pending', "Your access will start once the payment of $price $currency is confirmed.", '/student/profile');
        }
        $this->auditLog->record($this->uid($request), 'account_access.granted', 'User', (int) $user->id, null, [
            'grant_id' => $id, 'features' => $features, 'price' => $price, 'currency' => $currency, 'days' => $days, 'payment' => $payment,
        ], $request->ip());

        return $this->apiSuccess($this->grantRow(DB::table('account_access_grants')->find($id)), 'Access granted.', 201);
    }

    private function ownGrant(Request $request, int $id)
    {
        $q = DB::table('account_access_grants')->where('id', $id);
        if ($this->role($request) !== 'admin') {
            $q->where('granted_by', $this->uid($request));
        }

        return $q->first();
    }

    public function markPaid(Request $request, int $id)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $g = $this->ownGrant($request, $id);
        if (!$g || $g->revoked_at) {
            return $this->apiError('Grant not found.', null, 404);
        }
        if ($g->payment_status !== 'pending') {
            return $this->apiError('This grant is not waiting for payment.', null, 422);
        }
        DB::table('account_access_grants')->where('id', $id)->update([
            'payment_status' => 'paid', 'paid_at' => now(), 'starts_at' => now(),
            'expires_at' => $g->duration_days ? now()->addDays((int) $g->duration_days) : null, 'updated_at' => now(),
        ]);
        $this->notifications->notify((int) $g->user_id, 'account_access_granted', 'Your access is active', 'Payment confirmed — you can now use the platform.', '/student/dashboard');
        $this->auditLog->record($this->uid($request), 'account_access.paid', 'User', (int) $g->user_id, null, ['grant_id' => $id], $request->ip());

        return $this->apiSuccess($this->grantRow(DB::table('account_access_grants')->find($id)), 'Payment confirmed; access is active.');
    }

    public function extend(Request $request, int $id)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $days = (int) $request->input('days', 0);
        if ($days < 1 || $days > 3650) {
            return $this->apiError('days must be between 1 and 3650.', ['days' => ['invalid']], 422);
        }
        $g = $this->ownGrant($request, $id);
        if (!$g || $g->revoked_at || !$g->starts_at) {
            return $this->apiError('Grant not found or not active yet.', null, 404);
        }
        if ($g->expires_at === null) {
            return $this->apiError('This grant has no expiry.', null, 422);
        }
        // لو خلصت بالفعل نبدأ العدّ من دلوقتي، غير كده نزوّد على التاريخ الحالي.
        $base = strtotime((string) $g->expires_at) > time() ? \Illuminate\Support\Carbon::parse($g->expires_at) : now();
        DB::table('account_access_grants')->where('id', $id)->update(['expires_at' => $base->copy()->addDays($days), 'updated_at' => now()]);
        $this->notifications->notify((int) $g->user_id, 'account_access_extended', 'Your access was extended', "+$days days.", '/student/dashboard');
        $this->auditLog->record($this->uid($request), 'account_access.extended', 'User', (int) $g->user_id, null, ['grant_id' => $id, 'days' => $days], $request->ip());

        return $this->apiSuccess($this->grantRow(DB::table('account_access_grants')->find($id)), 'Access extended.');
    }

    public function revoke(Request $request, int $id)
    {
        if ($e = $this->denyUnlessManager($request)) {
            return $e;
        }
        $g = $this->ownGrant($request, $id);
        if (!$g || $g->revoked_at) {
            return $this->apiError('Grant not found.', null, 404);
        }
        DB::table('account_access_grants')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $this->uid($request), 'updated_at' => now()]);
        $this->notifications->notify((int) $g->user_id, 'account_access_revoked', 'Your access was withdrawn', null, '/student/profile');
        $this->auditLog->record($this->uid($request), 'account_access.revoked', 'User', (int) $g->user_id, ['grant_id' => $id], null, $request->ip());

        return $this->apiSuccess(['id' => $id], 'Access withdrawn.');
    }

    // ------------------------------------------------------------ faculty join requests

    private function facultyOf(Request $request)
    {
        return DB::table('faculties')->where('user_id', $this->uid($request))->first(['id', 'university_id']);
    }

    public function facultyIndex(Request $request)
    {
        $f = $this->facultyOf($request);
        if (!$f) {
            return $this->apiError('Only faculty accounts have a join-request queue.', null, 403);
        }
        $q = DB::table('student_university_requests as r')->join('students as s', 's.id', '=', 'r.student_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->where('r.faculty_id', $f->id)->where('r.university_id', $f->university_id);
        if ($request->query('status', 'pending') === 'pending') {
            $q->where('r.status', 'pending');
        }
        $rows = $q->orderByDesc('r.id')->limit(300)->get(['r.id', 'r.status', 'r.created_at', 'r.reviewed_at', 'u.full_name', 'u.email', 'd.name_en as department_en', 'd.name_ar as department_ar'])
            ->map(fn ($r) => (array) $r)->all();

        return $this->apiSuccess(['items' => $rows, 'pending' => count(array_filter($rows, fn ($r) => $r['status'] === 'pending'))]);
    }

    private function facultyDecide(Request $request, int $id, bool $approve)
    {
        $f = $this->facultyOf($request);
        if (!$f) {
            return $this->apiError('Only faculty accounts can decide join requests.', null, 403);
        }
        $row = DB::table('student_university_requests')->where('id', $id)->where('faculty_id', $f->id)->where('university_id', $f->university_id)->where('status', 'pending')->first(['id']);
        if (!$row) {
            return $this->apiError('Join request not found.', null, 404);
        }
        $ok = $approve
            ? $this->joinRequests->approve($id, (int) $f->university_id, $this->uid($request))
            : $this->joinRequests->reject($id, (int) $f->university_id, $this->uid($request));

        return $ok ? $this->apiSuccess(['id' => $id], $approve ? 'Request approved.' : 'Request declined.') : $this->apiError('The request could not be updated.', null, 422);
    }

    public function facultyApprove(Request $request, int $id)
    {
        return $this->facultyDecide($request, $id, true);
    }

    public function facultyReject(Request $request, int $id)
    {
        return $this->facultyDecide($request, $id, false);
    }
}
