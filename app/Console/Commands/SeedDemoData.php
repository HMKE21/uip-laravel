<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a full demo hierarchy in one go:
 *   universities -> faculties -> departments
 *   + faculty accounts, academic staff (doctors), supervisors
 *   + N students (default 10,000), each with an account and a project,
 *     some projects with teammates.
 *
 * Every user created is logged in demo_seed_log under a batch id in
 * demo_seed_batches, so a batch can be removed later with --purge.
 * Deleting the users cascades to universities/faculties/departments,
 * students, projects, staff, supervisors, team members.
 *
 * Usage:
 *   php artisan demo:seed
 *   php artisan demo:seed --students=2000 --universities=2
 *   php artisan demo:seed --list
 *   php artisan demo:seed --purge=<batch_id>
 *
 * All demo accounts use the password:  Passw0rd@2026
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {--students=10000 : Total students to create}
        {--universities=3 : Number of universities}
        {--faculties=4 : Faculties per university (max 8)}
        {--departments=3 : Departments per faculty (max 4)}
        {--staff=5 : Academic staff (doctors) per department}
        {--supervisors=6 : Supervisors per university}
        {--label= : Optional label for the batch}
        {--list : List existing demo batches}
        {--purge= : Delete a demo batch by its batch_id}';

    protected $description = 'Generate demo universities, faculties, doctors, supervisors, students and projects (trackable and removable).';

    private const PASSWORD = 'Passw0rd@2026';
    private const CHUNK = 500;

    private string $batchId;
    private string $tag;
    private string $passwordHash;
    private int $seq = 0;
    private int $projectSeq = 0;
    private array $roleIds = [];

    // ---- name / content pools -------------------------------------------------

    private array $firstMale = ['محمد', 'أحمد', 'محمود', 'مصطفى', 'عمر', 'يوسف', 'إبراهيم', 'علي', 'حسن', 'خالد', 'كريم', 'طارق', 'هشام', 'أمير', 'ياسر', 'عبدالله', 'سعيد', 'زياد', 'مروان', 'إسلام'];
    private array $firstFemale = ['فاطمة', 'مريم', 'سارة', 'نور', 'هدى', 'آية', 'ياسمين', 'منى', 'دينا', 'رنا', 'إيمان', 'شيماء', 'ندى', 'ريم', 'هبة', 'أسماء', 'جميلة', 'سلمى', 'لمياء', 'بسمة'];
    private array $family = ['السيد', 'حسن', 'علي', 'إبراهيم', 'محمود', 'عبدالرحمن', 'سالم', 'فؤاد', 'مصطفى', 'عثمان', 'الشناوي', 'البنا', 'النجار', 'الجمال', 'صلاح', 'رمضان', 'عيسى', 'منصور', 'الدسوقي', 'شعبان'];

    private array $cities = ['Cairo', 'Alexandria', 'Giza', 'Mansoura', 'Assiut', 'Tanta', 'Beni Suef', 'Aswan'];
    private array $uniNames = [
        ['جامعة النيل التجريبية', 'Nile Demo University'],
        ['جامعة الأهرام التجريبية', 'Ahram Demo University'],
        ['جامعة الدلتا التجريبية', 'Delta Demo University'],
        ['جامعة سيناء التجريبية', 'Sinai Demo University'],
        ['جامعة الصعيد التجريبية', 'Upper Egypt Demo University'],
        ['جامعة المتوسط التجريبية', 'Mediterranean Demo University'],
    ];

    /** [faculty ar, faculty en, [[dept ar, dept en], ...]] */
    private array $facultyPool = [
        ['كلية الهندسة', 'Faculty of Engineering', [['الهندسة المدنية', 'Civil Engineering'], ['الهندسة الكهربائية', 'Electrical Engineering'], ['الهندسة الميكانيكية', 'Mechanical Engineering'], ['الهندسة المعمارية', 'Architecture']]],
        ['كلية الحاسبات والذكاء الاصطناعي', 'Faculty of Computers and AI', [['علوم الحاسب', 'Computer Science'], ['نظم المعلومات', 'Information Systems'], ['الذكاء الاصطناعي', 'Artificial Intelligence'], ['أمن المعلومات', 'Cybersecurity']]],
        ['كلية التجارة', 'Faculty of Commerce', [['المحاسبة', 'Accounting'], ['إدارة الأعمال', 'Business Administration'], ['التسويق', 'Marketing'], ['الاقتصاد', 'Economics']]],
        ['كلية العلوم', 'Faculty of Science', [['الفيزياء', 'Physics'], ['الكيمياء', 'Chemistry'], ['الرياضيات', 'Mathematics'], ['علم الأحياء', 'Biology']]],
        ['كلية الطب', 'Faculty of Medicine', [['الطب الحيوي', 'Biomedical Sciences'], ['الجراحة', 'Surgery'], ['الباطنة', 'Internal Medicine'], ['طب الأسرة', 'Family Medicine']]],
        ['كلية الصيدلة', 'Faculty of Pharmacy', [['الكيمياء الصيدلية', 'Pharmaceutical Chemistry'], ['علم الأدوية', 'Pharmacology'], ['الصيدلة الإكلينيكية', 'Clinical Pharmacy'], ['الصيدلانيات', 'Pharmaceutics']]],
        ['كلية الآداب', 'Faculty of Arts', [['اللغة العربية', 'Arabic Language'], ['اللغة الإنجليزية', 'English Language'], ['التاريخ', 'History'], ['الإعلام', 'Media']]],
        ['كلية التربية', 'Faculty of Education', [['المناهج وطرق التدريس', 'Curriculum and Instruction'], ['علم النفس التربوي', 'Educational Psychology'], ['تكنولوجيا التعليم', 'Educational Technology'], ['التربية الخاصة', 'Special Education']]],
    ];

    private array $categories = [
        ['ai', 'Artificial Intelligence', 'الذكاء الاصطناعي'],
        ['web', 'Web Development', 'تطوير الويب'],
        ['mobile', 'Mobile Apps', 'تطبيقات الموبايل'],
        ['iot', 'Internet of Things', 'إنترنت الأشياء'],
        ['data-science', 'Data Science', 'علوم البيانات'],
        ['cybersecurity', 'Cybersecurity', 'أمن المعلومات'],
        ['healthtech', 'Health Technology', 'التكنولوجيا الصحية'],
        ['edtech', 'Education Technology', 'تكنولوجيا التعليم'],
    ];

    /** [ar, en] */
    private array $topics = [
        ['إدارة المستشفيات', 'hospital management'], ['متابعة حضور الطلاب', 'student attendance tracking'],
        ['التجارة الإلكترونية', 'e-commerce'], ['الزراعة الذكية', 'smart agriculture'],
        ['توصيل الطلبات', 'order delivery'], ['حجز المواعيد الطبية', 'medical appointment booking'],
        ['إدارة المخزون', 'inventory management'], ['تعلم اللغات', 'language learning'],
        ['ترشيد استهلاك الكهرباء', 'energy consumption optimization'], ['مراقبة جودة المياه', 'water quality monitoring'],
        ['البحث عن الوظائف', 'job matching'], ['المواصلات العامة', 'public transportation'],
        ['الأمن السيبراني للشركات الصغيرة', 'small business cybersecurity'], ['تحليل المشاعر في وسائل التواصل', 'social media sentiment analysis'],
        ['إدارة المشاريع الجامعية', 'university project management'], ['المكتبات الرقمية', 'digital libraries'],
        ['المنازل الذكية', 'smart homes'], ['إدارة النفايات', 'waste management'],
        ['التنبؤ بأسعار العقارات', 'real estate price prediction'], ['دعم ذوي الاحتياجات الخاصة', 'accessibility support'],
        ['إدارة المرور', 'traffic management'], ['الاختبارات الإلكترونية', 'online examinations'],
        ['التمويل الشخصي', 'personal finance'], ['حجز الفعاليات', 'event ticketing'],
    ];

    /** [ar, en] */
    private array $approaches = [
        ['نظام ذكي', 'Smart System'], ['تطبيق موبايل', 'Mobile App'], ['منصة ويب', 'Web Platform'],
        ['نموذج تعلم آلة', 'Machine Learning Model'], ['نظام إنترنت الأشياء', 'IoT System'],
        ['لوحة تحكم تحليلية', 'Analytics Dashboard'], ['مساعد ذكي', 'Smart Assistant'], ['نظام توصية', 'Recommendation System'],
    ];

    private array $technologies = ['Laravel', 'React', 'Python', 'TensorFlow', 'Flutter', 'Node.js', 'MySQL', 'MongoDB', 'Arduino', 'Raspberry Pi', 'Docker', 'Vue.js', 'Django', 'PyTorch', 'Firebase'];
    private array $skills = ['PHP', 'JavaScript', 'Python', 'SQL', 'Data Analysis', 'UI/UX', 'Machine Learning', 'Networking', 'Public Speaking', 'Project Management', 'Java', 'C++'];

    // ---------------------------------------------------------------------------

    public function handle(): int
    {
        DB::disableQueryLog();
        @set_time_limit(0);
        @ini_set('memory_limit', '1G');

        if ($this->option('list')) {
            return $this->listBatches();
        }
        if ($this->option('purge')) {
            return $this->purge((string) $this->option('purge'));
        }

        return $this->generate();
    }

    // ---- list / purge ---------------------------------------------------------

    private function listBatches(): int
    {
        $rows = DB::table('demo_seed_batches')->orderByDesc('id')->get(['batch_id', 'label', 'students_count', 'status', 'started_at', 'finished_at']);
        if ($rows->isEmpty()) {
            $this->info('No demo batches yet.');
            return self::SUCCESS;
        }
        $this->table(['batch_id', 'label', 'students', 'status', 'started', 'finished'], $rows->map(fn ($r) => (array) $r)->all());
        return self::SUCCESS;
    }

    private function purge(string $batchId): int
    {
        $batch = DB::table('demo_seed_batches')->where('batch_id', $batchId)->first();
        if (!$batch) {
            $this->error("Batch {$batchId} not found. Use --list to see batches.");
            return self::FAILURE;
        }
        if (!$this->confirm("Delete ALL users and data created by batch {$batchId}?", false)) {
            return self::SUCCESS;
        }

        $ids = DB::table('demo_seed_log')->where('batch_id', $batchId)->where('table_name', 'users')->pluck('record_id')->all();
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();
        // Cascades remove universities, faculties, departments, students, projects, staff, supervisors...
        foreach (array_chunk($ids, 200) as $chunk) {
            DB::table('users')->whereIn('id', $chunk)->delete();
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();

        DB::table('demo_seed_log')->where('batch_id', $batchId)->delete();
        DB::table('demo_seed_batches')->where('batch_id', $batchId)->update(['status' => 'deleted']);
        $this->info('Batch deleted.');
        return self::SUCCESS;
    }

    // ---- generate -------------------------------------------------------------

    private function generate(): int
    {
        $students = max(1, (int) $this->option('students'));
        $uniCount = max(1, min(6, (int) $this->option('universities')));
        $facCount = max(1, min(8, (int) $this->option('faculties')));
        $depCount = max(1, min(4, (int) $this->option('departments')));
        $staffPerDept = max(0, (int) $this->option('staff'));
        $supPerUni = max(0, (int) $this->option('supervisors'));

        $roles = DB::table('roles')->whereIn('slug', ['student', 'university', 'faculty', 'academic_staff', 'supervisor'])->pluck('id', 'slug')->all();
        foreach (['student', 'university', 'faculty', 'academic_staff', 'supervisor'] as $slug) {
            if (!isset($roles[$slug])) {
                $this->error("Role '{$slug}' is missing. Run:  php artisan db:seed --class=AcademicRolesSeeder  (and RolesSeeder/UsersSeeder).");
                return self::FAILURE;
            }
        }
        $this->roleIds = $roles;

        $this->batchId = (string) Str::uuid();
        $this->tag = substr(str_replace('-', '', $this->batchId), 0, 6);
        $this->passwordHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        DB::table('demo_seed_batches')->insert([
            'batch_id' => $this->batchId,
            'label' => $this->option('label') ?: "demo-{$this->tag}",
            'students_count' => 0,
            'status' => 'running',
            'notes' => json_encode(compact('students', 'uniCount', 'facCount', 'depCount', 'staffPerDept', 'supPerUni')),
        ]);

        $this->info("Batch {$this->batchId}");

        try {
            $categoryIds = $this->ensureCategories();
            $rankIds = DB::table('academic_ranks')->where('category', 'academic')->where('is_active', 1)->pluck('id')->all();

            $tree = $this->buildOrganizations($uniCount, $facCount, $depCount, $staffPerDept, $supPerUni, $rankIds);
            $created = $this->buildStudents($students, $tree, $categoryIds);

            DB::table('demo_seed_batches')->where('batch_id', $this->batchId)->update([
                'students_count' => $created, 'status' => 'completed', 'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('demo_seed_batches')->where('batch_id', $this->batchId)->update([
                'status' => 'failed', 'finished_at' => now(), 'notes' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            $this->error('Failed: ' . $e->getMessage());
            $this->line("Clean up the partial data with:  php artisan demo:seed --purge={$this->batchId}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Done. {$created} students created.");
        $this->line('Password for every demo account: ' . self::PASSWORD);
        $this->line("Sample logins:  university1.{$this->tag}@uip.demo | faculty1.1.{$this->tag}@uip.demo | staff1.{$this->tag}@uip.demo | supervisor1.{$this->tag}@uip.demo | student1.{$this->tag}@uip.demo");
        $this->line("Remove later:   php artisan demo:seed --purge={$this->batchId}");
        return self::SUCCESS;
    }

    private function ensureCategories(): array
    {
        foreach ($this->categories as $i => [$slug, $en, $ar]) {
            DB::table('categories')->insertOrIgnore(['slug' => $slug, 'name_en' => $en, 'name_ar' => $ar, 'icon' => 'folder', 'sort_order' => $i, 'is_active' => 1]);
        }
        $map = DB::table('categories')->whereIn('slug', array_column($this->categories, 0))->pluck('id', 'slug')->all();
        $out = [];
        foreach ($this->categories as [$slug, $en]) {
            if (isset($map[$slug])) {
                $out[] = ['id' => $map[$slug], 'name' => $en];
            }
        }
        return $out;
    }

    /**
     * @return array<int, array> universities with faculties/departments/supervisors
     */
    private function buildOrganizations(int $uniCount, int $facCount, int $depCount, int $staffPerDept, int $supPerUni, array $rankIds): array
    {
        $this->info('Building universities, faculties, departments, doctors, supervisors...');
        $tree = [];

        for ($u = 1; $u <= $uniCount; $u++) {
            [$uniAr, $uniEn] = $this->uniNames[($u - 1) % count($this->uniNames)];
            // University names are unique (pool wrap-around or an earlier demo batch): number the repeats.
            [$baseAr, $baseEn, $n] = [$uniAr, $uniEn, 1];
            while (\App\Support\UniversityName::conflicts($uniAr, $uniEn)) {
                $n++;
                [$uniAr, $uniEn] = ["$baseAr $n", "$baseEn $n"];
            }
            $userId = $this->createUsers([["university{$u}.{$this->tag}@uip.demo", $uniAr]], 'university')[0];

            $universityId = DB::table('universities')->insertGetId([
                'user_id' => $userId,
                'official_name_ar' => $uniAr,
                'official_name_en' => $uniEn,
            ] + \App\Support\UniversityName::keys($uniAr, $uniEn) + [
                'country' => 'Egypt',
                'city' => $this->cities[($u - 1) % count($this->cities)],
                'website' => "https://demo-{$this->tag}-{$u}.example.com",
                'verification_status' => 'verified',
                'verified_at' => now(),
                'is_public' => 1,
                'slug' => "demo-uni-{$this->tag}-{$u}",
            ]);

            // one group per academic year
            $groupIds = [];
            foreach ([1 => 'الفرقة الأولى', 2 => 'الفرقة الثانية', 3 => 'الفرقة الثالثة', 4 => 'الفرقة الرابعة'] as $year => $name) {
                $groupIds[$year] = DB::table('student_groups')->insertGetId(['university_id' => $universityId, 'name' => $name, 'max_members' => null]);
            }

            $uni = ['id' => $universityId, 'user_id' => $userId, 'groups' => $groupIds, 'depts' => [], 'supervisors' => [], 'faculties' => []];

            $staffNo = 0;
            for ($f = 1; $f <= $facCount; $f++) {
                [$facAr, $facEn, $depts] = $this->facultyPool[($f - 1) % count($this->facultyPool)];
                $facUserId = $this->createUsers([["faculty{$u}.{$f}.{$this->tag}@uip.demo", "عميد {$facAr}"]], 'faculty')[0];
                $facultyId = DB::table('faculties')->insertGetId([
                    'university_id' => $universityId,
                    'name_ar' => $facAr,
                    'name_en' => $facEn,
                    'slug' => Str::slug($facEn) . "-{$u}-{$f}",
                    'description' => "{$facEn} - demo data",
                    'contact_email' => "faculty{$u}.{$f}.{$this->tag}@uip.demo",
                    'status' => 'active',
                    'is_public' => 1,
                    'user_id' => $facUserId,
                ]);
                $uni['faculties'][] = $facultyId;

                for ($d = 0; $d < $depCount; $d++) {
                    [$depAr, $depEn] = $depts[$d % count($depts)];
                    $departmentId = DB::table('departments')->insertGetId([
                        'faculty_id' => $facultyId,
                        'name_ar' => $depAr,
                        'name_en' => $depEn,
                        'slug' => Str::slug($depEn),
                        'status' => 'active',
                        'is_public' => 1,
                    ]);
                    $uni['depts'][] = [
                        'department_id' => $departmentId, 'faculty_id' => $facultyId,
                        'faculty_name' => $facEn, 'department_name' => $depEn,
                    ];

                    // academic staff (doctors)
                    $defs = [];
                    for ($s = 0; $s < $staffPerDept; $s++) {
                        $staffNo++;
                        $n = ($u - 1) * 100000 + $staffNo;
                        $defs[] = ["staff{$n}.{$this->tag}@uip.demo", 'د. ' . $this->randomName()];
                    }
                    if ($defs) {
                        $ids = $this->createUsers($defs, 'academic_staff');
                        $rows = [];
                        foreach ($ids as $i => $uid) {
                            $rows[] = [
                                'user_id' => $uid, 'university_id' => $universityId, 'faculty_id' => $facultyId,
                                'department_id' => $departmentId,
                                'academic_rank_id' => $rankIds ? $rankIds[array_rand($rankIds)] : null,
                                'staff_number' => 'STF' . $u . str_pad((string) $staffNo, 4, '0', STR_PAD_LEFT) . $i,
                                'status' => 'active', 'invitation_status' => 'accepted', 'accepted_at' => now(),
                            ];
                        }
                        DB::table('academic_staff')->insert($rows);
                    }
                }
            }

            // supervisors
            for ($s = 1; $s <= $supPerUni; $s++) {
                $n = ($u - 1) * 1000 + $s;
                $name = 'د. ' . $this->randomName();
                $email = "supervisor{$n}.{$this->tag}@uip.demo";
                $uid = $this->createUsers([[$email, $name]], 'supervisor')[0];
                $facId = $uni['faculties'][($s - 1) % count($uni['faculties'])];
                $supId = DB::table('supervisors')->insertGetId([
                    'university_id' => $universityId, 'full_name' => $name, 'name_ar' => $name, 'name_en' => $this->englishNameFromEmail($email), 'email' => $email,
                    'department' => 'Demo', 'title' => 'Dr.', 'status' => 'active',
                    'invited_at' => now(), 'activated_at' => now(), 'user_id' => $uid,
                    'permissions' => '', 'invitation_status' => 'accepted', 'accepted_at' => now(),
                ]);
                DB::table('supervisor_assignments')->insert([
                    'supervisor_id' => $supId, 'university_id' => $universityId,
                    'scope_type' => 'faculty', 'scope_value' => (string) $facId,
                ]);
                $uni['supervisors'][] = $name;
            }
            if (!$uni['supervisors']) {
                $uni['supervisors'][] = 'د. ' . $this->randomName();
            }

            $tree[] = $uni;
        }

        return $tree;
    }

    private function buildStudents(int $total, array $tree, array $categoryIds): int
    {
        $this->info("Creating {$total} students with projects...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $uniCount = count($tree);
        $created = 0;
        $statuses = ['published' => 30, 'approved' => 20, 'under_review' => 15, 'submitted' => 15, 'draft' => 15, 'rejected' => 5];

        // split students across universities
        $perUni = array_fill(0, $uniCount, intdiv($total, $uniCount));
        for ($i = 0; $i < $total % $uniCount; $i++) {
            $perUni[$i]++;
        }

        foreach ($tree as $ui => $uni) {
            $remaining = $perUni[$ui];
            $depts = $uni['depts'];

            while ($remaining > 0) {
                $n = min(self::CHUNK, $remaining);
                $remaining -= $n;

                $defs = [];
                $meta = [];
                for ($i = 0; $i < $n; $i++) {
                    $this->seq++;
                    $prog = $depts[array_rand($depts)];
                    $year = random_int(1, 4);
                    $email = "student{$this->seq}.{$this->tag}@uip.demo";
                    $name = $this->randomName();
                    $defs[] = [$email, $name];
                    $meta[] = ['prog' => $prog, 'year' => $year, 'name' => $name, 'seq' => $this->seq];
                }

                $userIds = $this->createUsers($defs, 'student');

                $studentRows = [];
                $projectRows = [];
                foreach ($userIds as $i => $uid) {
                    $m = $meta[$i];
                    $p = $m['prog'];
                    $start = now()->subYears($m['year'] - 1)->startOfYear()->addMonths(8)->addDays(14);
                    $studentRows[] = [
                        'user_id' => $uid, 'university_id' => $uni['id'],
                        'student_number' => sprintf('%02d%d%06d', now()->format('y'), $ui + 1, $m['seq']),
                        'faculty' => $p['faculty_name'], 'department' => $p['department_name'],
                        'academic_year' => $m['year'], 'gpa' => round(random_int(200, 400) / 100, 2),
                        'bio' => 'Demo student - ' . $p['department_name'],
                        'skills' => json_encode(array_slice($this->shuffled($this->skills), 0, random_int(2, 5))),
                        'current_semester' => $m['year'] * 2 - random_int(0, 1),
                        'group_id' => $uni['groups'][$m['year']],
                        'invitation_status' => 'accepted', 'accepted_at' => now(),
                        'faculty_id' => $p['faculty_id'], 'department_id' => $p['department_id'],
                        'study_start_date' => $start->format('Y-m-d'),
                        'expected_graduation_date' => $start->copy()->addYears(4)->format('Y-m-d'),
                    ];

                    $this->projectSeq++;
                    $topic = $this->topics[array_rand($this->topics)];
                    $appr = $this->approaches[array_rand($this->approaches)];
                    $cat = $categoryIds ? $categoryIds[array_rand($categoryIds)] : null;
                    $status = $this->weighted($statuses);
                    $visibility = $status === 'published' ? 'public' : ($status === 'approved' ? 'university_only' : 'private');
                    $published = $status === 'published';
                    $techs = array_slice($this->shuffled($this->technologies), 0, random_int(2, 4));
                    $startD = now()->subDays(random_int(60, 300));

                    $projectRows[] = [
                        'uuid' => (string) Str::uuid(),
                        'owner_id' => $uid,
                        'university_id' => $uni['id'],
                        'title_ar' => "{$appr[0]} لـ{$topic[0]}",
                        'title_en' => "{$appr[1]} for " . ucfirst($topic[1]),
                        'summary' => "مشروع تخرج تجريبي: {$appr[0]} لـ{$topic[0]}. / Demo graduation project: {$appr[1]} for {$topic[1]}.",
                        'category' => $cat['name'] ?? null,
                        'category_id' => $cat['id'] ?? null,
                        'tags' => json_encode([$topic[1], $appr[1]]),
                        'visibility' => $visibility,
                        'status' => $status,
                        'views_count' => $published ? random_int(5, 900) : random_int(0, 30),
                        'likes_count' => $published ? random_int(0, 120) : 0,
                        'published_at' => $published ? now()->subDays(random_int(1, 120)) : null,
                        'supervisor_name' => $uni['supervisors'][array_rand($uni['supervisors'])],
                        'technologies' => json_encode($techs),
                        'sdgs' => json_encode([random_int(1, 17)]),
                        'budget' => random_int(0, 1) ? random_int(1000, 50000) : null,
                        'timeline_start' => $startD->format('Y-m-d'),
                        'timeline_end' => $startD->copy()->addMonths(random_int(4, 9))->format('Y-m-d'),
                        'keywords' => json_encode([$topic[1], strtolower($appr[1])]),
                        'slug' => "demo-{$this->tag}-{$this->projectSeq}",
                        'created_at' => $startD->format('Y-m-d H:i:s'),
                    ];
                }

                DB::table('students')->insert($studentRows);
                DB::table('projects')->insert($projectRows);

                // teammates for ~30% of projects
                $slugs = array_column($projectRows, 'slug');
                $projIds = DB::table('projects')->whereIn('slug', $slugs)->pluck('id', 'slug')->all();
                $memberRows = [];
                foreach ($projectRows as $i => $pr) {
                    if (random_int(1, 100) > 30 || !isset($projIds[$pr['slug']])) {
                        continue;
                    }
                    $added = 0;
                    foreach ((array) array_rand($userIds, min(3, count($userIds))) as $j) {
                        if ($j === $i || $added >= 2) {
                            continue;
                        }
                        $memberRows[] = [
                            'project_id' => $projIds[$pr['slug']], 'user_id' => $userIds[$j],
                            'role' => 'student_member', 'status' => 'accepted',
                            'invited_by' => $userIds[$i], 'responded_at' => now(),
                            'member_name' => $meta[$j]['name'], 'academic_year' => $meta[$j]['year'],
                            'student_number' => $studentRows[$j]['student_number'],
                        ];
                        $added++;
                    }
                }
                foreach (array_chunk($memberRows, 500) as $c) {
                    DB::table('project_team_members')->insert($c);
                }

                $created += $n;
                $bar->advance($n);
            }
        }

        $bar->finish();
        return $created;
    }

    // ---- helpers --------------------------------------------------------------

    /** Demo data only has Arabic names — derive a readable English one from the e-mail local part. */
    private function englishNameFromEmail(string $email): string
    {
        return ucwords(str_replace(['.', '_', '-'], ' ', (string) strstr($email, '@', true))) ?: 'Demo User';
    }

    /**
     * @param array<int, array{0:string,1:string}> $defs [email, full_name]
     * @return int[] user ids in the same order as $defs
     */
    private function createUsers(array $defs, string $roleSlug): array
    {
        $all = [];
        foreach (array_chunk($defs, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as [$email, $name]) {
                $ts = now()->subDays(random_int(0, 400))->format('Y-m-d H:i:s');
                $rows[] = [
                    'uuid' => (string) Str::uuid(), 'full_name' => $name, 'name_ar' => $name, 'name_en' => $this->englishNameFromEmail($email), 'email' => $email,
                    'password_hash' => $this->passwordHash, 'preferred_language' => 'ar',
                    'status' => 'active', 'email_verified_at' => $ts, 'created_at' => $ts, 'updated_at' => $ts,
                ];
            }
            DB::table('users')->insert($rows);

            $map = DB::table('users')->whereIn('email', array_column($rows, 'email'))->pluck('id', 'email')->all();
            $ids = [];
            $roleRows = [];
            $logRows = [];
            foreach ($chunk as [$email]) {
                $ids[] = $map[$email];
                $roleRows[] = ['user_id' => $map[$email], 'role_id' => $this->roleIds[$roleSlug]];
                $logRows[] = ['batch_id' => $this->batchId, 'table_name' => 'users', 'record_id' => $map[$email]];
            }
            DB::table('user_roles')->insert($roleRows);
            DB::table('demo_seed_log')->insert($logRows);
            array_push($all, ...$ids);
        }
        return $all;
    }

    private function randomName(): string
    {
        $pool = random_int(0, 1) ? $this->firstMale : $this->firstFemale;
        $father = $this->firstMale[array_rand($this->firstMale)];
        return $pool[array_rand($pool)] . ' ' . $father . ' ' . $this->family[array_rand($this->family)];
    }

    private function shuffled(array $a): array
    {
        shuffle($a);
        return $a;
    }

    /** @param array<string,int> $weights */
    private function weighted(array $weights): string
    {
        $r = random_int(1, array_sum($weights));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }
        return array_key_first($weights);
    }
}
