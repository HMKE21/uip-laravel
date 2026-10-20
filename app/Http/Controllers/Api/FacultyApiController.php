<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\Paginates;
use App\Models\Department;
use App\Models\User;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\UniversityRepository;
use App\Services\AuditLogService;
use App\Services\FacultyAccountService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Controllers/Api/FacultyApiController.php القديمة — نفس
 * الـ 16 endpoint بالظبط (CRUD جامعة-مملوكة + دليل عام + provisioning
 * تسجيل الدخول + سجل الكلية نفسها + خدمة ذاتية لبورتال الكلية + أقسام/
 * برامج متداخلة). نفس شكل الـ JSON، نفس رسائل الأخطاء، نفس قواعد الملكية.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/hasRole() ->
 * $request->attributes->get('uip_user_id')/'uip_role'، وuse الـ Paginates
 * trait بنسختها الملارافيلة (بتاخد $request صراحة).
 *
 * RBAC: uip.auth بتغطي الجروب كله؛ publicIndex()/publicShow() بتعدي من
 * جوه نفس الجروب برضو (زي القديم بالظبط) بس من غير أي hasRole() check —
 * دليل عام حقيقي بدون احتياج صلاحية معينة.
 */
class FacultyApiController extends Controller
{
    use Paginates;

    public function __construct(
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private UniversityRepository $universities,
        private AuditLogService $auditLog,
        private FacultyAccountService $accounts,
        private FileUploadService $uploads
    ) {
    }

    // -- University-owned CRUD -------------------------------------------------

    /**
     * GET /api/v1/faculty — كليات جامعة الكولر نفسه، بعدادات. Role جامعة
     * بس. بتدعم `search` (على name_en/name_ar) و`page`/`per_page`.
     *
     * `status`: لو مش معدّى، بترجع كل حاجة ما عدا الأرشيف (السلوك
     * الافتراضي للقائمة العادية). `status=archived` بترجع تاب الأرشيف
     * بس (عشان يقدر يسترجعها). أي قيمة تانية (زي 'active') بتتفلتر
     * مطابقة تامة زي ما هي.
     */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can list their faculties.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $rows = $this->faculties->forUniversityWithCounts($university->id, $status);
        } else {
            $rows = array_values(array_filter(
                $this->faculties->forUniversityWithCounts($university->id),
                fn ($f) => ($f['status'] ?? 'active') !== 'archived'
            ));
        }

        $rows = $this->filterBySearch($request, $rows, ['name_en', 'name_ar']);
        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess($items, 'Faculties retrieved successfully.', 200, $this->meta($page, $perPage, $total));
    }

    /** GET /api/v1/faculty/{id} — محكوم بالملكية، مع عدادات بروفايل + أقسام. Role جامعة بس. */
    public function show(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can view a faculty this way.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $faculty = $this->faculties->findOwned($id, $university->id);
        if (!$faculty) {
            return $this->apiError('Faculty not found.', null, 404);
        }

        $stats = $this->faculties->withProfileStats((int) $faculty->id);
        $departmentRows = $this->departments->forFaculty((int) $faculty->id);

        $trashedRows = $this->departments->trashedForFaculty((int) $faculty->id);

        // Student counts per department (live + deleted) so the UI can warn before a delete
        // and show what comes back on restore.
        $ids = array_merge(array_map(fn (Department $d) => (int) $d->id, $departmentRows), array_map(fn (Department $d) => (int) $d->id, $trashedRows));
        $counts = $ids ? DB::table('students')->whereIn('department_id', $ids)->selectRaw('department_id, COUNT(*) as c')->groupBy('department_id')->pluck('c', 'department_id')->all() : [];
        $withCount = fn (Department $d) => $d->toArray() + ['students_count' => (int) ($counts[$d->id] ?? 0)];

        return $this->apiSuccess([
            'faculty'             => $stats ?? $faculty->toArray(),
            'departments'         => array_map($withCount, $departmentRows),
            'deleted_departments' => array_map($withCount, $trashedRows),
        ], 'Faculty retrieved successfully.');
    }

    /**
     * POST /api/v1/faculty — إنشاء. Role جامعة بس؛ الكلية دايمًا بتتبع
     * جامعة الكولر نفسه. `email`/`password` اختياريين — لو الإيميل
     * موجود، بيتعمل حساب دخول للكلية جوه نفس المعاملة (نفس منطق
     * provisionLogin()، فرق إنه هنا اختياري ومربوط بخطوة الإنشاء بدل
     * كون خطوة منفصلة بعدين). لو `password` فاضية، بيترجع لتوليد باسورد
     * مؤقت وإرساله زي القديم بالظبط.
     */
    public function store(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can create a faculty.', null, 403);
        }

        $nameEn = trim((string) $request->input('name_en', ''));
        $nameAr = trim((string) $request->input('name_ar', ''));
        if ($nameEn === '' || $nameAr === '') {
            return $this->apiError('Validation failed.', ['name_en' => 'Required.', 'name_ar' => 'Required.'], 422);
        }

        $email = trim((string) $request->input('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('Validation failed.', ['email' => 'A valid email is required.'], 422);
        }
        if ($email !== '' && ($why = \App\Support\DisposableEmail::problem($email, (string) $request->input('locale', 'ar')))) {
            return $this->apiError('Validation failed.', ['email' => $why], 422);
        }
        $password = (string) $request->input('password', '');

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        $slug = $this->slugify($nameEn);
        if ($slug === '' || $this->faculties->slugExists($university->id, $slug)) {
            return $this->apiError('A faculty with a similar name already exists.', null, 422);
        }

        $locale = (string) $request->input('locale', 'ar');

        try {
            $faculty = DB::transaction(function () use ($nameEn, $nameAr, $slug, $request, $university, $userId, $email, $password, $locale) {
                $faculty = $this->faculties->create([
                    'university_id' => $university->id,
                    'name_en'       => $nameEn,
                    'name_ar'       => $nameAr,
                    'slug'          => $slug,
                    'description'   => $request->input('description'),
                    'status'        => 'active',
                    'is_public'     => 1,
                ]);

                $this->auditLog->record($userId, 'faculty.create', 'Faculty', $faculty->id, null, $faculty->toArray());

                if ($email !== '') {
                    $result = $this->accounts->provisionLogin($faculty->id, $university->id, $userId, $email, $locale, $password !== '' ? $password : null);
                    if (!$result['success']) {
                        throw new \RuntimeException($result['message']);
                    }
                }

                return $faculty;
            });
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), ['email' => $e->getMessage()], 422);
        }

        return $this->apiSuccess($faculty->fresh()->toArray(), 'Faculty created successfully.', 201);
    }

    /**
     * PATCH /api/v1/faculty/{id} — name_en/name_ar/description/is_public.
     * Role جامعة بس.
     */
    public function update(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a faculty.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $faculty = $this->faculties->findOwned($id, $university->id);
        if (!$faculty) {
            return $this->apiError('Faculty not found.', null, 404);
        }

        $before = $faculty->toArray();

        $changes = [];
        if ($request->input('name_en') !== null) {
            $changes['name_en'] = trim((string) $request->input('name_en'));
        }
        if ($request->input('name_ar') !== null) {
            $changes['name_ar'] = trim((string) $request->input('name_ar'));
        }
        if ($request->input('description') !== null) {
            $changes['description'] = $request->input('description');
        }
        if ($request->input('is_public') !== null) {
            $changes['is_public'] = $request->boolean('is_public');
        }

        if ($changes === []) {
            return $this->apiError('No updatable fields provided.', null, 422);
        }

        $faculty->fill($changes);
        $faculty->save();

        $this->auditLog->record($userId, 'faculty.update', 'Faculty', $faculty->id, $before, $faculty->toArray());

        return $this->apiSuccess($faculty->toArray(), 'Faculty updated successfully.');
    }

    /**
     * POST /api/v1/faculty/{id}/archive — حذف ناعم. مفيش destroy() ولا
     * hard delete على الإطلاق: الكلية مرتبط بيها أقسام/طلاب/أعضاء هيئة
     * تدريس/إعلانات/منشورات (faculty_id في كل الجداول دي)، فمسحها فعليًا
     * هيسيب سجلات يتيمة أو هيكسر الداتا. بدل كده بنقلب status لـ
     * 'archived' (نفس اتفاقية Project::archive() تمامًا) — بتختفي من
     * index()/publicIndex()/publicShow() بس البيانات كلها فاضلة زي ما
     * هي، وترجع تاني بـ unarchive(). بنقفل is_public كمان عشان الأرشفة
     * تشيلها فورًا من أي دليل عام حتى لو حصل race مع طلب متزامن. Role
     * جامعة بس.
     */
    public function archive(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can archive a faculty.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $faculty = $this->faculties->findOwned($id, $university->id);
        if (!$faculty) {
            return $this->apiError('Faculty not found.', null, 404);
        }
        if ($faculty->status === 'archived') {
            return $this->apiError('Faculty is already archived.', null, 409);
        }

        $before = $faculty->toArray();
        $faculty->fill(['status' => 'archived', 'is_public' => false]);
        $faculty->save();

        $this->auditLog->record($userId, 'faculty.archive', 'Faculty', $faculty->id, $before, $faculty->toArray());

        return $this->apiSuccess($faculty->toArray(), 'Faculty archived successfully.');
    }

    /**
     * POST /api/v1/faculty/{id}/unarchive — يرجّع الكلية لـ 'active'
     * وتظهر تاني في القوائم. بتفضل is_public خاص (زي ما هي عليه من
     * وقت الأرشفة) — الجامعة هي اللي تقرر تعلنها تاني لو عايزة، مش
     * بترجع عامة تلقائي. Role جامعة بس.
     */
    public function unarchive(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can restore a faculty.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $faculty = $this->faculties->findOwned($id, $university->id);
        if (!$faculty) {
            return $this->apiError('Faculty not found.', null, 404);
        }
        if ($faculty->status !== 'archived') {
            return $this->apiError('Faculty is not archived.', null, 409);
        }

        $before = $faculty->toArray();
        $faculty->fill(['status' => 'active']);
        $faculty->save();

        $this->auditLog->record($userId, 'faculty.unarchive', 'Faculty', $faculty->id, $before, $faculty->toArray());

        return $this->apiSuccess($faculty->toArray(), 'Faculty restored successfully.');
    }

    // -- Login provisioning -----------------------------------------------------

    /** POST /api/v1/faculty/{id}/login — بيدي الكلية login خاص بيها. Role جامعة بس. */
    public function provisionLogin(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can provision a faculty login.', null, 403);
        }

        $email = trim((string) $request->input('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('Validation failed.', ['email' => 'A valid email is required.'], 422);
        }
        if ($why = \App\Support\DisposableEmail::problem($email, (string) $request->input('locale', 'ar'))) {
            return $this->apiError('Validation failed.', ['email' => $why], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $locale = (string) $request->input('locale', 'ar');
        $password = (string) $request->input('password', '');

        $result = $this->accounts->provisionLogin($id, $university->id, $userId, $email, $locale, $password !== '' ? $password : null);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'], 201)
            : $this->apiError($result['message'], null, 422);
    }

    /** POST /api/v1/faculty/{id}/login/reset — يولّد باسورد مؤقت جديد ويبعته. Role جامعة بس. */
    public function resetLogin(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can reset a faculty login.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $locale = (string) $request->input('locale', 'ar');

        $result = $this->accounts->resetLogin($id, $university->id, $userId, $locale);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /** PATCH /api/v1/faculty/{id}/login/email — تغيير إيميل الكلية يدويًا (مش Reset). Role جامعة بس. */
    public function updateLoginEmail(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can change a faculty login email.', null, 403);
        }

        $email = trim((string) $request->input('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->apiError('Validation failed.', ['email' => 'A valid email is required.'], 422);
        }
        if ($why = \App\Support\DisposableEmail::problem($email, (string) $request->input('locale', 'ar'))) {
            return $this->apiError('Validation failed.', ['email' => $why], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $locale = (string) $request->input('locale', 'ar');

        $result = $this->accounts->updateLoginEmail($id, $university->id, $userId, $email, $locale);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /** PATCH /api/v1/faculty/{id}/login/password — تغيير كلمة مرور الكلية يدويًا (مش توليد عشوائي). Role جامعة بس. */
    public function updateLoginPassword(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can change a faculty login password.', null, 403);
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            return $this->apiError('Validation failed.', ['password' => 'A new password is required.'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $locale = (string) $request->input('locale', 'ar');

        $result = $this->accounts->updateLoginPassword($id, $university->id, $userId, $password, $locale);

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    // -- Public directory ---------------------------------------------------

    /** GET /api/v1/faculty/public/{universitySlug} — كليات جامعة موثقة وعامة ونشطة. مفيش role مطلوب. */
    public function publicIndex(Request $request, string $universitySlug)
    {
        $university = $this->universities->findBySlugOrId($universitySlug);
        if (!$university || !(int) $university->is_public) {
            return $this->apiError('University not found.', null, 404);
        }

        $rows = array_values(array_filter(
            $this->faculties->forUniversity($university->id, 'active'),
            fn ($f) => (int) $f->is_public === 1
        ));

        return $this->apiSuccess(array_map(fn ($f) => $f->toArray(), $rows), 'Faculties retrieved successfully.');
    }

    /** GET /api/v1/faculty/public/{universitySlug}/{facultySlug} — بروفايل كلية عامة واحدة + أقسامها العامة. مفيش role مطلوب. */
    public function publicShow(Request $request, string $universitySlug, string $facultySlug)
    {
        $university = $this->universities->findBySlugOrId($universitySlug);
        if (!$university || !(int) $university->is_public) {
            return $this->apiError('Faculty not found.', null, 404);
        }

        $faculty = $this->faculties->findBySlug($university->id, $facultySlug);
        if (!$faculty || !(int) $faculty->is_public || !$faculty->isActive()) {
            return $this->apiError('Faculty not found.', null, 404);
        }

        $profile = $this->faculties->withProfileStats($faculty->id);
        $departmentRows = array_values(array_filter(
            $this->departments->forFaculty($faculty->id, 'active'),
            fn (Department $d) => (int) $d->is_public === 1
        ));

        return $this->apiSuccess([
            'university'  => $university->toArray(),
            'faculty'     => $profile ?? $faculty->toArray(),
            'departments' => array_map(fn (Department $d) => $d->toArray(), $departmentRows),
        ], 'Faculty retrieved successfully.');
    }

    // -- Faculty's own record -------------------------------------------------

    /**
     * GET /api/v1/faculty/me — سجل الكلية نفسها + عدادات بروفايل، مدموج
     * بهوية الشخص المسجل دخوله (اسم/إيميل/صورة) وshare_url عام. Role
     * كلية بس.
     */
    public function me(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts have this profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $faculty = $this->faculties->findByUserId($userId);
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }

        $stats = $this->faculties->withProfileStats((int) $faculty->id);
        $user = User::find($userId);
        $university = $this->universities->find($faculty->university_id);

        $shareUrl = ($university && $faculty->slug)
            ? $this->publicBaseUrl() . '/universities/' . rawurlencode((string) $university->slug) . '/faculties/' . rawurlencode((string) $faculty->slug)
            : null;

        return $this->apiSuccess([
            'faculty'   => $stats ?? $faculty->toArray(),
            'user'      => $user?->toArray(),
            'share_url' => $shareUrl,
        ], 'Faculty profile retrieved successfully.');
    }

    /**
     * PATCH /api/v1/faculty/me/visibility — تشغيل/إيقاف صفحة البورتفوليو
     * العامة بتاعة الكلية نفسها. Role كلية بس.
     */
    public function updateOwnVisibility(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can update their own portfolio visibility.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $faculty = $this->faculties->findByUserId($userId);
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }

        $before = ['is_public' => (bool) $faculty->is_public];
        $faculty->fill(['is_public' => $request->boolean('is_public')]);
        $faculty->save();

        $this->auditLog->record($userId, 'faculty.visibility_update', 'Faculty', $faculty->id, $before, ['is_public' => (bool) $faculty->is_public]);

        return $this->apiSuccess(['is_public' => (bool) $faculty->is_public], 'Portfolio visibility updated successfully.');
    }

    /**
     * POST /api/v1/faculty/me/avatar — رفع multipart. Role كلية بس. دي
     * صورة الشخص نفسه، مش لوجو الكلية (عمود/endpoint تاني منفصل).
     */
    public function uploadAvatar(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can update this profile photo.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        try {
            $stored = $this->uploads->store($request->file('avatar'), 'avatars', (string) $user->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($user->avatar_path) {
            $this->uploads->delete($user->avatar_path);
        }

        $before = ['avatar_path' => $user->avatar_path];
        $user->fill(['avatar_path' => $stored['stored_path']]);
        $user->save();

        $this->auditLog->record($userId, 'faculty.avatar_update', 'User', $user->id, $before, ['avatar_path' => $stored['stored_path']]);

        return $this->apiSuccess(['avatar_path' => $stored['stored_path']], 'Profile photo updated successfully.');
    }

    /**
     * GET /api/v1/faculty/my/tree — تسلسل قسم -> برنامج الخاص بالكلية
     * الحالية، لـ selects صفحات الطلاب/الكادر الأكاديمي. Role كلية بس.
     */
    public function myTree(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts have this hierarchy.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $faculty = $this->faculties->findByUserId($userId);
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }

        $departmentRows = $this->departments->forFaculty((int) $faculty->id);
        return $this->apiSuccess([
            'departments' => array_map(fn (Department $d) => $d->toArray(), $departmentRows),
        ], 'Faculty hierarchy retrieved successfully.');
    }

    // -- Departments (متداخلة تحت الكلية) --------------------------------------

    /**
     * POST /api/v1/faculty/{id}/departments — إنشاء قسم تحت واحدة من
     * كليات الكولر نفسه. {id} هو معرّف الكلية. Role جامعة بس.
     */
    public function storeDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can create a department.', null, 403);
        }

        $nameEn = trim((string) $request->input('name_en', ''));
        $nameAr = trim((string) $request->input('name_ar', ''));
        if ($nameEn === '' || $nameAr === '') {
            return $this->apiError('Validation failed.', ['name_en' => 'Required.', 'name_ar' => 'Required.'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $faculty = $this->faculties->findOwned($id, $university->id);
        if (!$faculty) {
            return $this->apiError('Faculty not found.', null, 404);
        }
        if ($faculty->status === 'archived') {
            return $this->apiError('This faculty is archived. Restore it first to add departments.', null, 422);
        }

        $slug = $this->slugify($nameEn);
        if ($slug === '' || $this->departments->slugExists($faculty->id, $slug)) {
            return $this->apiError('A department with a similar name already exists in this faculty.', null, 422);
        }

        $department = $this->departments->create([
            'faculty_id'  => $faculty->id,
            'name_en'     => $nameEn,
            'name_ar'     => $nameAr,
            'slug'        => $slug,
            'description' => $request->input('description'),
            'status'      => 'active',
            'is_public'   => 1,
        ]);

        $this->auditLog->record($userId, 'department.create', 'Department', $department->id, null, $department->toArray());

        return $this->apiSuccess($department->toArray(), 'Department created successfully.', 201);
    }

    /**
     * GET /api/v1/departments/{id} — يقابل faculty/{id} show() بالظبط،
     * لصفحة Department Portfolio الجديدة (كانت الصفحة والـ endpoint
     * مفقودين خالص — الرابط من faculty-portfolio.jsx كان بيودّي لـ 404).
     * Role جامعة بس. بيرجّع القسم + إحصاءاته (DepartmentRepository::
     * withProfileStats) + برامجه.
     */
    public function showDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can view a department this way.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $department = $this->departments->findOwnedByUniversity($id, $university->id);
        if (!$department) {
            return $this->apiError('Department not found.', null, 404);
        }

        $stats = $this->departments->withProfileStats((int) $department->id);

        return $this->apiSuccess([
            'department' => $stats ?? $department->toArray(),
        ], 'Department retrieved successfully.');
    }

    /**
     * PATCH /api/v1/departments/{id} — is_public زي ما كان، وكمان
     * name_en/name_ar/description دلوقتي (نفس نطاق faculty's update())
     * عشان "Department Details" edit form في الصفحة الجديدة. أي حقل
     * منهم اختياري — لو معدّاش، بيفضل زي ما هو. Role جامعة بس.
     */
    public function updateDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a department.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $department = $this->departments->findOwnedByUniversity($id, $university->id);
        if (!$department) {
            return $this->apiError('Department not found.', null, 404);
        }

        $before = $department->toArray();

        $changes = [];
        if ($request->input('name_en') !== null) {
            $changes['name_en'] = trim((string) $request->input('name_en'));
        }
        if ($request->input('name_ar') !== null) {
            $changes['name_ar'] = trim((string) $request->input('name_ar'));
        }
        if ($request->input('description') !== null) {
            $changes['description'] = $request->input('description');
        }
        if ($request->input('is_public') !== null) {
            $changes['is_public'] = $request->boolean('is_public');
        }

        if ($changes === []) {
            return $this->apiError('No updatable fields provided.', null, 422);
        }

        $department->fill($changes);
        $department->save();

        $this->auditLog->record($userId, 'department.update', 'Department', $department->id, $before, $department->toArray());

        return $this->apiSuccess($department->toArray(), 'Department updated successfully.');
    }

    /**
     * POST /api/v1/departments/{id}/archive — نفس منطق faculty's
     * archive() (حذف ناعم فقط: status='archived' + is_public=false).
     * مفيش destroy() هنا برضه لنفس السبب — طلاب/أعضاء هيئة تدريس/برامج
     * مرتبطين بالقسم. Role جامعة بس.
     */
    public function archiveDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can archive a department.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $department = $this->departments->findOwnedByUniversity($id, $university->id);
        if (!$department) {
            return $this->apiError('Department not found.', null, 404);
        }
        if ($department->status === 'archived') {
            return $this->apiError('Department is already archived.', null, 409);
        }

        $before = $department->toArray();
        $department->fill(['status' => 'archived', 'is_public' => false]);
        $department->save();

        $this->auditLog->record($userId, 'department.archive', 'Department', $department->id, $before, $department->toArray());

        return $this->apiSuccess($department->toArray(), 'Department archived successfully.');
    }

    /**
     * DELETE /api/v1/departments/{id} — soft delete (Role جامعة بس). The row, its students, staff and
     * exams are left untouched (only deleted_at is set), so POST /departments/{id}/restore brings
     * everything back exactly as it was. Every student of the department gets a notification.
     */
    public function deleteDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can delete a department.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $department = $this->departments->findOwnedByUniversity($id, $university->id);
        if (!$department) {
            return $this->apiError('Department not found.', null, 404);
        }

        $before = $department->toArray();
        $department->deleted_by = $userId;
        $department->save();
        $department->delete();

        $notified = $this->notifyDepartmentStudents(
            (int) $department->id,
            'department_deleted',
            'Your department was removed — تم حذف قسمك',
            sprintf('The department "%s" was deleted by your university. Your data is kept, and you will be notified if it is restored. / تم حذف قسم "%s" من الجامعة، بياناتك محفوظة وهيجيلك إشعار لو اتسترجع القسم.', $department->name_en, $department->name_ar)
        );

        $this->auditLog->record($userId, 'department.delete', 'Department', $department->id, $before, ['deleted' => true, 'students_notified' => $notified]);

        return $this->apiSuccess(['id' => (int) $department->id, 'students_notified' => $notified], 'Department deleted successfully.');
    }

    /** POST /api/v1/departments/{id}/restore — full restore of a soft-deleted department. Role جامعة بس. */
    public function restoreDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can restore a department.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $row = $this->departments->findOwnedByUniversity($id, $university->id, true);
        if (!$row || $row->deleted_at === null) {
            return $this->apiError('Deleted department not found.', null, 404);
        }

        $department = Department::withTrashed()->find($row->id);
        $department->restore();
        $department->deleted_by = null;
        $department->save();

        $notified = $this->notifyDepartmentStudents(
            (int) $department->id,
            'department_restored',
            'Your department was restored — تم استرجاع قسمك',
            sprintf('The department "%s" is active again. / تم استرجاع قسم "%s" وبقى شغال تاني.', $department->name_en, $department->name_ar)
        );

        $this->auditLog->record($userId, 'department.restore', 'Department', $department->id, ['deleted' => true], array_merge($department->toArray(), ['students_notified' => $notified]));

        return $this->apiSuccess($department->toArray() + ['students_notified' => $notified], 'Department restored successfully.');
    }

    private function notifyDepartmentStudents(int $departmentId, string $type, string $title, string $body): int
    {
        $notifications = app(\App\Services\NotificationService::class);
        $count = 0;
        DB::table('students')->where('department_id', $departmentId)->whereNotNull('user_id')->orderBy('id')
            ->select('user_id')->chunk(200, function ($rows) use ($notifications, $type, $title, $body, &$count) {
                foreach ($rows as $r) {
                    try {
                        $notifications->notify((int) $r->user_id, $type, $title, $body, '/student/profile');
                        $count++;
                    } catch (\Throwable $e) {
                        // a failed notification must not block the delete/restore
                    }
                }
            });

        return $count;
    }

    /** POST /api/v1/departments/{id}/unarchive — يقابل faculty's unarchive(). Role جامعة بس. */
    public function unarchiveDepartment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can restore a department.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);
        $department = $this->departments->findOwnedByUniversity($id, $university->id);
        if (!$department) {
            return $this->apiError('Department not found.', null, 404);
        }
        if ($department->status !== 'archived') {
            return $this->apiError('Department is not archived.', null, 409);
        }

        $before = $department->toArray();
        $department->fill(['status' => 'active']);
        $department->save();

        $this->auditLog->record($userId, 'department.unarchive', 'Department', $department->id, $before, $department->toArray());

        return $this->apiSuccess($department->toArray(), 'Department restored successfully.');
    }

    // -- Faculty portal self-service (Role كلية؛ كلية بتضيف لشجرتها هي
    // نفسها، عكس storeDepartment() اللي فوق ده اللي هي الجامعة بتنشئ قسم
    // تحت واحدة من كلياتها هي). الكلية (وملكية القسم بالنسبة للبرامج)
    // دايمًا بتتحل من login الكولر نفسه، مش أي id جاي من العميل. --------

    /**
     * POST /api/v1/faculty/departments — تسجيل دخول كلية بينشئ قسم تحت
     * كليته هو (بدون {id} — الكلية دايمًا هي كلية الكولر). Role كلية بس.
     */
    public function storeOwnDepartment(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'faculty') {
            return $this->apiError('Only faculty accounts can create a department this way.', null, 403);
        }

        $nameEn = trim((string) $request->input('name_en', ''));
        $nameAr = trim((string) $request->input('name_ar', ''));
        if ($nameEn === '' || $nameAr === '') {
            return $this->apiError('Validation failed.', ['name_en' => 'Required.', 'name_ar' => 'Required.'], 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $faculty = $this->faculties->findByUserId($userId);
        if (!$faculty) {
            return $this->apiError('This login is not linked to a faculty.', null, 404);
        }
        if ($faculty->status === 'archived') {
            return $this->apiError('Your faculty is archived. Ask your university to restore it first.', null, 422);
        }

        $slug = $this->slugify($nameEn);
        if ($slug === '' || $this->departments->slugExists($faculty->id, $slug)) {
            return $this->apiError('A department with a similar name already exists in your faculty.', null, 422);
        }

        $department = $this->departments->create([
            'faculty_id'  => $faculty->id, // ثابت بكلية الكولر — أبدًا مش input من العميل
            'name_en'     => $nameEn,
            'name_ar'     => $nameAr,
            'slug'        => $slug,
            'description' => $request->input('description'),
            'status'      => 'active',
            'is_public'   => 1,
        ]);

        $this->auditLog->record($userId, 'faculty.department_create', 'Department', $department->id, null, $department->toArray());

        return $this->apiSuccess($department->toArray(), 'Department created successfully.', 201);
    }

    // -- helpers --------------------------------------------------------------

    /** نفس اتفاقية slugify القديمة بالظبط — مكررة عمدًا (مفيش helper مشترك لسه). */
    private function slugify(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    }

    /**
     * أساس رابط الفرونت (SPA) عشان بناء share_url — config('app.url') هنا
     * عنوان الباك إند نفسه (Laravel API)، مش الفرونت الـ React اللي شغال
     * على بورت/دومين تاني في التطوير (Vite على :5173 مثلًا). نفس منطق
     * MailService::loginUrl()/UniversitiesApiController::publicBaseUrl()
     * بالظبط: FRONTEND_URL لو متظبطة في .env، وإلا رجوع لـ APP_URL.
     */
    private function publicBaseUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
    }
}
