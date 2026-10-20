<?php

namespace App\Repositories;

use App\Models\University;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * منقولة من app/Repositories/UniversityRepository.php القديمة (Core\Database
 * -> DB facade). بند 3 بيستخدم منها بس المتود اللي فعلًا وراها استعمال في
 * UniversitiesApiController — القديمة فيها ميثودز تانية (allForRegistration،
 * allWithStats، countStats، countByStatus، innovationHubPatents/...) خاصة
 * بـ Admin Dashboard/تسجيل الطلاب (بنود لاحقة)، مش منقولة هنا عمدًا؛ هتتضاف
 * لما بندها ييجي بدل تكرار كود مالوش مستهلك دلوقتي.
 */
class UniversityRepository
{
    public function findByUserId($userId): ?University
    {
        return University::where('user_id', $userId)->first();
    }

    /**
     * نفس findByUserId()، لكن بتعمل self-heal لحساب اتسجل قبل ما التسجيل
     * يبقى بيعمل provision لصف universities تلقائي — صفحات Settings/
     * Verification بتنادي دي بدل findByUserId() عشان محدش يواجه "profile
     * not found" أبدًا.
     */
    public function getOrCreate($userId, string $fallbackName = ''): University
    {
        $university = $this->findByUserId($userId);
        if ($university) {
            return $university;
        }

        // مينفعش نعمل صف جامعة لحساب مش جامعة (أدمن/طالب/...) ولا لحساب محذوف/محظور —
        // ده كان بيخلي اسم المستخدم يظهر كجامعة في القوايم حتى بعد الحذف.
        $account = DB::table('users')->where('id', $userId)->first(['id', 'status', 'deleted_at']);
        $isUniversityRole = $account && DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->where('r.slug', 'university')->exists();
        if (!$account || $account->deleted_at !== null || $account->status === 'banned' || !$isUniversityRole
            || University::withTrashed()->where('user_id', $userId)->exists()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(University::class, [$userId]);
        }

        // The fallback name must never duplicate an existing university.
        if ($fallbackName !== '' && \App\Support\UniversityName::conflicts($fallbackName, $fallbackName, (int) $userId)) {
            $fallbackName .= ' (' . $userId . ')';
        }

        return University::create([
            'user_id'          => $userId,
            'official_name_ar' => $fallbackName,
            'official_name_en' => $fallbackName,
            'country'          => '',
        ]);
    }

    /** كل الجامعات، مرتبة بالاسم — القايمة اللي فورم طلب شراكة الشركة (بند 6) بيعرضها. @return University[] */
    public function allForRegistration(): array
    {
        return University::applyListable(University::query())->orderBy('official_name_en')->get()->all();
    }

    public function find($id): ?University
    {
        return University::find($id);
    }

    /**
     * منقولة من UniversityRepository::countStats() القديمة — بند 25
     * (AdminUniversitiesApiController::index() -> 'counts'، كروت إحصائيات
     * صفحة Universities). عدّات رخيصة بدون تحميل صفوف.
     * @return array{total:int,verified:int,pending:int,total_students:int}
     */
    public function countStats(): array
    {
        $row = DB::table('universities')
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->first();

        return [
            'total'          => (int) ($row->total ?? 0),
            'verified'       => (int) ($row->verified ?? 0),
            'pending'        => (int) ($row->pending ?? 0),
            'total_students' => DB::table('students')->count(),
        ];
    }

    /**
     * كل الجامعات + عدد الطلاب/المشاريع لايف، الأحدث انضمامًا الأول — بند
     * الـ Reports (ReportService::universityOnboardingDigest()) بس، مش
     * فلترة/ترقيم زي paginateWithStats() فوق.
     * @return array<int,array<string,mixed>>
     */
    public function allWithStats(): array
    {
        return DB::table('universities as uni')
            ->whereNull('uni.deleted_at')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.university_id', 'uni.id');
            }, 'projects_count')
            ->orderByDesc('uni.created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * صفوف الجامعات + عدد الطلاب/المشاريع لايف، مع بحث وفلتر حالة، محدودة
     * بـ LIMIT/OFFSET — عشان صفحة الـ directory ميحملش كل الجامعات مرة واحدة.
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function paginateWithStats(string $search, string $status, int $page, int $perPage, bool $trashed = false): array
    {
        $query = DB::table('universities as uni')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.university_id', 'uni.id');
            }, 'projects_count');

        $trashed ? $query->whereNotNull('uni.deleted_at') : $query->whereNull('uni.deleted_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('uni.official_name_en', 'like', $like)
                    ->orWhere('uni.official_name_ar', 'like', $like)
                    ->orWhere('uni.city', 'like', $like)
                    ->orWhere('uni.country', 'like', $like);
            });
        }
        if ($status !== '') {
            $query->where('uni.verification_status', $status);
        }

        $total = (clone $query)->count();

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;
        $rows = $query->orderByDesc('uni.created_at')->limit($perPage)->offset($offset)->get();

        return ['rows' => $rows->map(fn ($r) => (array) $r)->all(), 'total' => $total];
    }

    /**
     * جامعة واحدة + عدد الطلاب/المشاريع المنشورة لايف — شكل صفحات البروفايل
     * العامة (يطابق UniversityRepository::withProfileStats() القديمة).
     * @return array<string,mixed>|null
     */
    public function withProfileStats($id): ?array
    {
        $row = DB::table('universities as uni')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')
                    ->whereColumn('p.university_id', 'uni.id')
                    ->where('p.status', 'published');
            }, 'published_projects_count')
            ->where('uni.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * تجميع Innovation Hub (براءات اختراع/شراكات) —
     * يطابق innovationHubStats() القديمة حرف بحرف.
     * @return array<string,int>
     */
    public function innovationHubStats($id): array
    {
        $row = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM patents pt INNER JOIN projects p ON p.id = pt.project_id WHERE p.university_id = :id1) AS patents_count",
            ['id1' => $id]
        );
        $row = $row ? (array) $row : [];

        return [
            'patents_count'               => (int) ($row['patents_count'] ?? 0),
        ];
    }

    /** أحدث براءات اختراع الجامعة، لعرض Innovation Hub. @return array<int,array<string,mixed>> */
    public function innovationHubPatents($id, int $limit = 6): array
    {
        return DB::table('patents as pt')
            ->join('projects as p', 'p.id', '=', 'pt.project_id')
            ->where('p.university_id', $id)
            ->select('pt.title', 'pt.status', 'pt.filed_at')
            ->orderByDesc('pt.created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * قرار الأدمن على طلب اعتماد الجامعة. بس pending -> verified/rejected؛
     * جامعة اتقرر فيها قبل كده متتلمسش تاني (idempotent).
     */
    public function decide($id, string $status, $adminId): bool
    {
        $university = University::find($id);
        if (!$university || $university->verification_status !== 'pending') {
            return false;
        }

        $fields = [
            'verification_status' => $status,
            'verified_at'         => now(),
            'verified_by'         => $adminId,
        ];

        // Automatic Re-verification (094): أول دورة اعتماد بتبدأ هنا —
        // المدة بالأيام خاصة بالجامعة لو متسجلة، وإلا الافتراضي العام.
        if ($status === 'verified') {
            $days = (int) ($university->verification_period_days ?: config('verification.period_days', 365));
            $fields['verification_expires_at'] = now()->addDays($days);
            $fields['verification_expiry_notified_at'] = null;
        }

        return $university->fill($fields)->save();
    }

    /** إعدادات إعادة الاعتماد التلقائي (Admin) لجامعة واحدة. null بترجّع للافتراضي العام. */
    public function updateReverificationSettings($id, ?int $periodDays, bool $autoReverifyEnabled): bool
    {
        $university = University::find($id);
        if (!$university) {
            return false;
        }

        return $university->fill([
            'verification_period_days' => $periodDays,
            'auto_reverify_enabled'    => $autoReverifyEnabled,
        ])->save();
    }

    /**
     * حذف الجامعة (soft delete — قابل للاسترجاع):
     *  - صف الجامعة بيتعلّم deleted_at (مفيش cascade على الكليات/المجموعات/الطلبات).
     *  - حساب الجامعة بيتعمله soft delete + banned.
     *  - كل طلاب الجامعة بيتوقفوا (suspended) وجلساتهم بتتقفل، وبيوصلهم إشعار
     *    في المنصة + إيميل. بنسجّل حالتهم الأصلية عشان الاسترجاع يرجّعها.
     *
     * @return array{ok:bool, students_suspended:int}
     */
    public function softDelete($id, ?int $deletedBy = null): array
    {
        $university = University::find($id);
        if (!$university) {
            return ['ok' => false, 'students_suspended' => 0];
        }

        $uniName = ['ar' => $university->official_name_ar ?: $university->official_name_en, 'en' => $university->official_name_en ?: $university->official_name_ar];
        $affected = [];

        $uniAccount = null;
        DB::transaction(function () use ($university, $deletedBy, &$affected, &$uniAccount) {
            if ($university->user_id) {
                $prev = DB::table('users')->where('id', $university->user_id)->value('status');
                DB::table('users')->where('id', $university->user_id)->update([
                    'deleted_at'           => now(),
                    'status'               => 'banned',
                    'status_before_delete' => $prev && $prev !== 'banned' ? $prev : null,
                ]);
                $uniAccount = DB::table('users')->where('id', $university->user_id)->first(['id', 'email', 'full_name', 'preferred_language']);
            }

            // كل المرتبطين بالجامعة: طلاب + مشرفين + هيئة أكاديمية.
            // النشطين/المعلّقين بس — اللي مش active (banned مثلاً) نسيبهم زي ما هم.
            $memberIds = DB::table('students')->where('university_id', $university->id)->pluck('user_id')
                ->merge(DB::table('supervisors')->where('university_id', $university->id)->whereNotNull('user_id')->pluck('user_id'))
                ->merge(DB::table('academic_staff')->where('university_id', $university->id)->pluck('user_id'))
                ->unique()->values()->all();

            $affected = $memberIds === [] ? [] : DB::table('users')
                ->whereIn('id', $memberIds)
                ->where('id', '!=', (int) $university->user_id)
                ->whereNull('deleted_at')
                ->whereIn('status', ['active', 'pending'])
                ->get(['id', 'email', 'full_name', 'status', 'preferred_language'])
                ->all();

            foreach ($affected as $row) {
                DB::table('users')->where('id', $row->id)->update([
                    'status'                           => 'suspended',
                    'suspended_by_university_id'       => $university->id,
                    'status_before_university_removal' => $row->status,
                ]);
            }

            $university->deleted_by = $deletedBy;
            $university->save();
            $university->delete();
        });

        $this->notifyStudents($affected, $uniName, removed: true);
        if ($uniAccount) {
            $this->notifyUniversityAccount($uniAccount, $uniName, removed: true);
            app(\App\Services\AccountLifecycleService::class)->revokeAccess((int) $uniAccount->id, 'account_deleted');
        }

        return ['ok' => true, 'students_suspended' => count($affected)];
    }

    /** توافق مع أي كولر قديم بيستنى bool. */
    public function delete($id): bool
    {
        return $this->softDelete($id)['ok'];
    }

    /**
     * استرجاع جامعة محذوفة بحسابها وطلابها.
     * @return array{ok:bool, error:?string, students_restored:int}
     */
    public function restore($id, ?string $emailOverride = null): array
    {
        $university = University::withTrashed()->find($id);
        if (!$university || !$university->trashed()) {
            return ['ok' => false, 'error' => 'University not found in the trash.', 'students_restored' => 0];
        }

        // الاسم اتاخد من جامعة تانية بعد الحذف => مينفعش يتكرر، الاسترجاع مرفوض.
        $dup = \App\Support\UniversityName::conflicts($university->official_name_ar, $university->official_name_en, $university->user_id ? (int) $university->user_id : null);
        if ($dup) {
            return ['ok' => false, 'error' => 'Another university already uses this name: ' . reset($dup), 'students_restored' => 0];
        }

        // لو الإيميل اتأخد من حساب تاني بعد الحذف، منرجّعش بتعارض.
        $account = $university->user_id ? DB::table('users')->where('id', $university->user_id)->first() : null;
        $restoredEmail = null;
        if ($account) {
            $restoredEmail = ($emailOverride !== null && trim($emailOverride) !== '') ? trim($emailOverride) : \App\Support\DeletedEmail::original($account);
            $taken = DB::table('users')->whereNull('deleted_at')->where('id', '!=', $account->id)
                ->whereRaw('LOWER(email) = ?', [strtolower($restoredEmail)])->exists();
            if ($taken) {
                return ['ok' => false, 'error' => 'The university email is now used by another account.', 'students_restored' => 0];
            }
        }

        $affected = [];
        DB::transaction(function () use ($university, $account, $restoredEmail, &$affected) {
            if ($account) {
                \App\Support\DeletedEmail::release((string) $restoredEmail, (int) $account->id);
                DB::table('users')->where('id', $account->id)->update([
                    'deleted_at'           => null,
                    'status'               => $account->status_before_delete ?: 'active',
                    'status_before_delete' => null,
                    'email'                => $restoredEmail,
                    'original_email'       => null,
                ]);
            }

            $affected = DB::table('users')
                ->where('suspended_by_university_id', $university->id)
                ->whereNull('deleted_at')
                ->get(['id', 'email', 'full_name', 'status_before_university_removal', 'preferred_language'])
                ->all();

            foreach ($affected as $row) {
                DB::table('users')->where('id', $row->id)->update([
                    'status'                           => $row->status_before_university_removal ?: 'active',
                    'suspended_by_university_id'       => null,
                    'status_before_university_removal' => null,
                ]);
            }

            $university->deleted_by = null;
            $university->save();
            $university->restore();
        });

        $uniName = ['ar' => $university->official_name_ar ?: $university->official_name_en, 'en' => $university->official_name_en ?: $university->official_name_ar];
        $this->notifyStudents($affected, $uniName, removed: false);
        if ($account) {
            $this->notifyUniversityAccount($account, $uniName, removed: false);
        }

        return ['ok' => true, 'error' => null, 'students_restored' => count($affected)];
    }

    /** إشعار داخل المنصة + إيميل لكل طالب اتأثر (فشل الإيميل مبيوقفش العملية). */
    private function notifyStudents(array $students, array $uniName, bool $removed): void
    {
        $notifications = app(\App\Services\NotificationService::class);
        $mail = app(\App\Services\MailService::class);

        foreach ($students as $row) {
            $ar = ($row->preferred_language ?? 'ar') === 'ar';
            $name = $ar ? $uniName['ar'] : $uniName['en'];

            if ($removed) {
                $title = $ar ? "تم إيقاف حسابك: تمت إزالة {$name} من المنصة" : "Your account was suspended: {$name} was removed from the platform";
                $body  = $ar
                    ? "تمت إزالة جامعتك ({$name}) من المنصة، ولذلك تم إيقاف حسابك مؤقتًا. تواصل مع إدارة المنصة لو محتاج مساعدة."
                    : "Your university ({$name}) was removed from the platform, so your account has been suspended. Contact platform support if you need help.";
                app(\App\Services\AccountLifecycleService::class)->revokeAccess((int) $row->id, 'university_removed');
            } else {
                $title = $ar ? "تمت إعادة تفعيل حسابك: رجعت {$name} للمنصة" : "Your account was reactivated: {$name} is back on the platform";
                $body  = $ar ? "تمت إعادة جامعتك ({$name}) للمنصة، وتم إعادة تفعيل حسابك. تقدر تسجّل الدخول دلوقتي."
                             : "Your university ({$name}) was restored, and your account has been reactivated. You can sign in again.";
            }

            try { $notifications->notify((int) $row->id, 'university_status', $title, $body, null, 'high'); } catch (\Throwable $e) { report($e); }
            try { $mail->sendNotificationEmail((string) $row->email, (string) $row->full_name, $title, $body, null, $ar ? 'ar' : 'en'); } catch (\Throwable $e) { report($e); }
        }
    }

    /** إيميل + إشعار لحساب الجامعة نفسه عند حذفها/استرجاعها. */
    private function notifyUniversityAccount(object $account, array $uniName, bool $removed): void
    {
        $ar = ($account->preferred_language ?? 'ar') === 'ar';
        $name = $ar ? $uniName['ar'] : $uniName['en'];
        if ($removed) {
            $title = $ar ? "تم حذف جامعتك ({$name}) من المنصة" : "Your university ({$name}) was removed from the platform";
            $body  = $ar ? 'تم حذف حساب الجامعة وإيقاف جميع المرتبطين بها. تواصل مع إدارة المنصة لو محتاج مساعدة أو لاسترجاعها.'
                         : 'The university account was removed and everyone linked to it was suspended. Contact platform support for help or to restore it.';
        } else {
            $title = $ar ? "تم استرجاع جامعتك ({$name})" : "Your university ({$name}) was restored";
            $body  = $ar ? 'تم استرجاع حساب الجامعة وجميع المرتبطين بها. تقدر تسجّل الدخول دلوقتي.'
                         : 'The university account and everyone linked to it were reactivated. You can sign in again.';
        }
        try { app(\App\Services\NotificationService::class)->notify((int) $account->id, 'university_status', $title, $body, null, 'high'); } catch (\Throwable $e) { report($e); }
        try { app(\App\Services\MailService::class)->sendNotificationEmail((string) $account->email, (string) $account->full_name, $title, $body, null, $ar ? 'ar' : 'en'); } catch (\Throwable $e) { report($e); }
    }

    /** جامعة واحدة بالـ slug العام (migration 137). */
    public function findBySlug(string $slug): ?University
    {
        return University::where('slug', $slug)->first();
    }

    /**
     * البحث اللي وراه routes/faculty/public/{universitySlug}* — بيقبل id
     * رقمي كمان لأي كولر داخلي لسه معاه واحد. جامعة اتسجلت قبل migration
     * 137 (أو الـ slug فاضي لأي سبب) بيتعمل لها backfill أول مرة تتطلب
     * هنا، بنفس اتفاقية self-healing اللي في UniversityRepository الأصلية.
     */
    public function findBySlugOrId(string $identifier): ?University
    {
        $university = $this->findBySlug($identifier);
        if ($university) {
            return $university;
        }

        if (!ctype_digit($identifier)) {
            return null;
        }

        $university = University::find((int) $identifier);
        if (!$university) {
            return null;
        }

        if (empty($university->slug)) {
            $university->slug = $this->generateUniqueSlug($university);
            $university->save();
        }

        return $university;
    }

    private function generateUniqueSlug(University $university): string
    {
        $base = Str::slug((string) ($university->official_name_en ?: $university->official_name_ar));
        if ($base === '') {
            $base = 'university';
        }
        $base = mb_substr($base, 0, 180);

        do {
            $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        } while (University::where('slug', $slug)->exists());

        return $slug;
    }
}
