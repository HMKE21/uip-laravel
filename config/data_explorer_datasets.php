<?php
/**
 * Config: data_explorer_datasets
 *
 * Static CATALOG metadata for the Data Explorer (enhancement spec section
 * 2). The actual column lists are introspected live from the database
 * (INFORMATION_SCHEMA on MySQL, PRAGMA table_info on SQLite) by
 * DataExplorerRepository, so they never drift from the real schema —
 * this file only holds what introspection can't tell us: display
 * labels, an icon, which columns are sensitive and must be redacted
 * from any preview/export, and the declared relationships to other
 * datasets in the catalog (for the "Data Relationships" tab).
 *
 * Every `table` value here MUST be deliberately allow-listed (the original
 * six plus the exam platform, staff, students, courses, attendance,
 * assignments, grading, course-content, registration, fees, summer-term,
 * academic-structure and graduation tables) — this is
 * a read-only exploration surface, not a generic "browse any table"
 * tool, so the allow-list is deliberately closed.
 * @package UIP
 */

return [

    'projects' => [
        'table' => 'projects',
        'label' => ['en' => 'Projects', 'ar' => 'المشاريع'],
        'description' => [
            'en' => 'Graduation and innovation projects submitted across all universities.',
            'ar' => 'مشاريع التخرج والابتكار المقدَّمة من جميع الجامعات.',
        ],
        'icon' => 'projects',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'owner_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Owner (student)', 'ar' => 'صاحب المشروع']],
            ['column' => 'id', 'references' => 'ai_analysis', 'references_column' => 'project_id',
                'label' => ['en' => 'AI analyses of this project', 'ar' => 'تحليلات الذكاء الاصطناعي لهذا المشروع']],
        ],
    ],

    'users' => [
        'table' => 'users',
        'label' => ['en' => 'Users', 'ar' => 'المستخدمون'],
        'description' => [
            'en' => 'Every platform account across all portals (students, universities, admins).',
            'ar' => 'كل حسابات المنصة عبر جميع البوابات (طلاب، جامعات، شركات، مستثمرون، مديرون).',
        ],
        'icon' => 'users',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        // Never previewable/exportable from the Data Explorer, regardless
        // of the viewer's role — these are redacted at the repository
        // level, not just hidden in the view.
        'sensitive_columns' => ['password_hash', 'remember_token'],
        'relationships' => [
            ['column' => 'id', 'references' => 'projects', 'references_column' => 'owner_id',
                'label' => ['en' => 'Owned projects', 'ar' => 'المشاريع المملوكة']],
            ['column' => 'id', 'references' => 'security_logs', 'references_column' => 'user_id',
                'label' => ['en' => 'Security log events', 'ar' => 'أحداث سجل الأمان']],
        ],
    ],

    'analytics_records' => [
        'table' => 'analytics_records',
        'label' => ['en' => 'Analytics Records', 'ar' => 'سجلات التحليلات'],
        'description' => [
            'en' => 'Time-series platform metrics (metric_key/value per day) that power dashboards and charts.',
            'ar' => 'مقاييس زمنية للمنصة (مفتاح/قيمة لكل يوم) تُغذّي لوحات المعلومات والرسوم البيانية.',
        ],
        'icon' => 'bar-chart',
        'primary_key' => 'id',
        'default_sort' => 'recorded_for_date',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [],
    ],

    'innovation_statistics' => [
        'table' => 'innovation_statistics',
        'label' => ['en' => 'Innovation Statistics', 'ar' => 'إحصائيات الابتكار'],
        'description' => [
            'en' => 'Per-university, per-category rollups of project counts, approval counts, and average AI readiness score.',
            'ar' => 'ملخصات لكل جامعة وتصنيف لعدد المشاريع، عدد الموافقات، ومتوسط درجة جاهزية الذكاء الاصطناعي.',
        ],
        'icon' => 'trend',
        'primary_key' => 'id',
        'default_sort' => 'period_end',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'projects', 'references_column' => 'university_id',
                'label' => ['en' => 'Projects at this university', 'ar' => 'مشاريع هذه الجامعة']],
        ],
    ],

    'ai_analysis' => [
        'table' => 'ai_analysis',
        'label' => ['en' => 'AI Analysis', 'ar' => 'تحليلات الذكاء الاصطناعي'],
        'description' => [
            'en' => 'AI-generated results per project: readiness scores, classification, summaries, startup potential.',
            'ar' => 'نتائج الذكاء الاصطناعي لكل مشروع: درجات الجاهزية، التصنيف، الملخصات، إمكانية النجاح كشركة ناشئة.',
        ],
        'icon' => 'sparkles',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'project_id', 'references' => 'projects', 'references_column' => 'id',
                'label' => ['en' => 'Analyzed project', 'ar' => 'المشروع المُحلَّل']],
            ['column' => 'requested_by', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Requested by', 'ar' => 'طلب التحليل']],
        ],
    ],

    'security_logs' => [
        'table' => 'security_logs',
        'label' => ['en' => 'Security Logs', 'ar' => 'سجلات الأمان'],
        'description' => [
            'en' => 'Append-only security/audit events (logins, password resets, 2FA changes) across the platform.',
            'ar' => 'أحداث أمنية للتدقيق (تسجيل الدخول، إعادة تعيين كلمة المرور، تغييرات التحقق بخطوتين) عبر المنصة.',
        ],
        'icon' => 'shield',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'User', 'ar' => 'المستخدم']],
        ],
    ],

    // ------------------------------------------------------------------
    // Exam platform + academic staff datasets (read-only analytics).
    // Answer keys, access passwords, session tokens/IPs and password hashes
    // are listed in sensitive_columns and are blocked everywhere.
    // ------------------------------------------------------------------

    'exams' => [
        'table' => 'exams',
        'label' => ['en' => 'Exams', 'ar' => 'الامتحانات'],
        'description' => [
            'en' => 'Every exam: type, schedule, duration, marks, passing score, status and security settings.',
            'ar' => 'كل الامتحانات: النوع، الموعد، المدة، الدرجات، درجة النجاح، الحالة وإعدادات الأمان.',
        ],
        'icon' => 'note',
        'primary_key' => 'id',
        // الجدول بيستخدم soft-delete: الـ Explorer والـ Query Builder بيستبعدوا الصفوف المحذوفة تلقائيًا.
        'soft_deletes' => true,
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['access_password'],
        'relationships' => [
            ['column' => 'created_by_academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Created by (doctor)', 'ar' => 'أنشأه الدكتور']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'id', 'references' => 'exam_questions', 'references_column' => 'exam_id',
                'label' => ['en' => 'Exam questions', 'ar' => 'أسئلة الامتحان']],
            ['column' => 'id', 'references' => 'exam_attempts', 'references_column' => 'exam_id',
                'label' => ['en' => 'Student attempts', 'ar' => 'محاولات الطلاب']],
            ['column' => 'id', 'references' => 'exam_security_events', 'references_column' => 'exam_id',
                'label' => ['en' => 'Security events', 'ar' => 'الأحداث الأمنية']],
            ['column' => 'id', 'references' => 'exam_grade_appeals', 'references_column' => 'exam_id',
                'label' => ['en' => 'Grade appeals', 'ar' => 'تظلمات الدرجات']],
        ],
    ],

    'exam_attempts' => [
        'table' => 'exam_attempts',
        'label' => ['en' => 'Exam Attempts', 'ar' => 'محاولات الامتحانات'],
        'description' => [
            'en' => 'Each student attempt: status, score, percentage, timing, lateness and violation counts.',
            'ar' => 'كل محاولة طالب: الحالة، الدرجة، النسبة، التوقيت، التأخير وعدد المخالفات.',
        ],
        'icon' => 'award',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['session_token_hash', 'session_ip', 'session_user_agent', 'session_device_id'],
        'relationships' => [
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
            ['column' => 'id', 'references' => 'exam_grades', 'references_column' => 'exam_attempt_id',
                'label' => ['en' => 'Per-question grades', 'ar' => 'درجات الأسئلة']],
        ],
    ],

    'exam_grades' => [
        'table' => 'exam_grades',
        'label' => ['en' => 'Exam Grades', 'ar' => 'درجات الأسئلة'],
        'description' => [
            'en' => 'Per-question marks awarded (automatic, instructor or AI) with feedback and grader source.',
            'ar' => 'الدرجة الممنوحة لكل سؤال (تلقائي أو دكتور أو ذكاء اصطناعي) مع الملاحظات ومصدر التصحيح.',
        ],
        'icon' => 'check-circle',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_attempt_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt', 'ar' => 'المحاولة']],
            ['column' => 'exam_question_id', 'references' => 'exam_questions', 'references_column' => 'id',
                'label' => ['en' => 'Exam question', 'ar' => 'سؤال الامتحان']],
            ['column' => 'graded_by_academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Graded by (doctor)', 'ar' => 'صححه الدكتور']],
        ],
    ],

    'exam_security_events' => [
        'table' => 'exam_security_events',
        'label' => ['en' => 'Exam Security Events', 'ar' => 'الأحداث الأمنية للامتحانات'],
        'description' => [
            'en' => 'Proctoring and secure-mode events (tab switches, violations) recorded during attempts.',
            'ar' => 'أحداث المراقبة والوضع الآمن (تبديل التبويب، المخالفات) أثناء المحاولات.',
        ],
        'icon' => 'shield',
        'primary_key' => 'id',
        'default_sort' => 'occurred_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_attempt_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt', 'ar' => 'المحاولة']],
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'exam_similarity_flags' => [
        'table' => 'exam_similarity_flags',
        'label' => ['en' => 'Similarity Flags', 'ar' => 'تنبيهات التشابه'],
        'description' => [
            'en' => 'Answer-similarity (possible cheating) flags between two attempts, with review status.',
            'ar' => 'تنبيهات تشابه الإجابات (غش محتمل) بين محاولتين مع حالة المراجعة.',
        ],
        'icon' => 'alert-triangle',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
            ['column' => 'attempt_a_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt A', 'ar' => 'المحاولة أ']],
            ['column' => 'attempt_b_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt B', 'ar' => 'المحاولة ب']],
        ],
    ],

    'exam_grade_appeals' => [
        'table' => 'exam_grade_appeals',
        'label' => ['en' => 'Grade Appeals', 'ar' => 'تظلمات الدرجات'],
        'description' => [
            'en' => 'Student grade appeals with status, response and score before/after.',
            'ar' => 'تظلمات الطلاب على الدرجات مع الحالة والرد والدرجة قبل/بعد.',
        ],
        'icon' => 'message-square',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
            ['column' => 'exam_attempt_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt', 'ar' => 'المحاولة']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'questions' => [
        'table' => 'questions',
        'label' => ['en' => 'Questions', 'ar' => 'الأسئلة'],
        'description' => [
            'en' => 'Question-bank questions: type, difficulty, topic, marks and status (answer keys hidden).',
            'ar' => 'أسئلة بنوك الأسئلة: النوع، الصعوبة، الموضوع، الدرجة والحالة (مفاتيح الإجابة مخفية).',
        ],
        'icon' => 'file',
        'primary_key' => 'id',
        // الجدول بيستخدم soft-delete: الـ Explorer والـ Query Builder بيستبعدوا الصفوف المحذوفة تلقائيًا.
        'soft_deletes' => true,
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['correct_answer', 'accepted_answers', 'model_answer', 'expected_concepts', 'grading_instructions'],
        'relationships' => [
            ['column' => 'question_bank_id', 'references' => 'question_banks', 'references_column' => 'id',
                'label' => ['en' => 'Question bank', 'ar' => 'بنك الأسئلة']],
            ['column' => 'created_by_academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Author (doctor)', 'ar' => 'كاتب السؤال']],
            ['column' => 'id', 'references' => 'exam_questions', 'references_column' => 'question_id',
                'label' => ['en' => 'Used in exams', 'ar' => 'مستخدم في امتحانات']],
        ],
    ],

    'question_banks' => [
        'table' => 'question_banks',
        'label' => ['en' => 'Question Banks', 'ar' => 'بنوك الأسئلة'],
        'description' => [
            'en' => 'Question banks by university, faculty, department and owner.',
            'ar' => 'بنوك الأسئلة حسب الجامعة والكلية والقسم والمالك.',
        ],
        'icon' => 'folder',
        'primary_key' => 'id',
        // الجدول بيستخدم soft-delete: الـ Explorer والـ Query Builder بيستبعدوا الصفوف المحذوفة تلقائيًا.
        'soft_deletes' => true,
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'created_by_academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Owner (doctor)', 'ar' => 'المالك (الدكتور)']],
            ['column' => 'id', 'references' => 'questions', 'references_column' => 'question_bank_id',
                'label' => ['en' => 'Questions', 'ar' => 'الأسئلة']],
        ],
    ],

    'academic_staff' => [
        'table' => 'academic_staff',
        'label' => ['en' => 'Academic Staff (Doctors)', 'ar' => 'أعضاء هيئة التدريس (الدكاترة)'],
        'description' => [
            'en' => 'Doctors and teaching staff: university, faculty, department, rank and status.',
            'ar' => 'الدكاترة وهيئة التدريس: الجامعة والكلية والقسم والرتبة والحالة.',
        ],
        'icon' => 'briefcase',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['password_encrypted'],
        'relationships' => [
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'User account', 'ar' => 'حساب المستخدم']],
            ['column' => 'id', 'references' => 'exams', 'references_column' => 'created_by_academic_staff_id',
                'label' => ['en' => 'Exams created', 'ar' => 'الامتحانات المنشأة']],
            ['column' => 'id', 'references' => 'question_banks', 'references_column' => 'created_by_academic_staff_id',
                'label' => ['en' => 'Question banks', 'ar' => 'بنوك الأسئلة']],
        ],
    ],

    'students' => [
        'table' => 'students',
        'label' => ['en' => 'Students', 'ar' => 'الطلاب'],
        'description' => [
            'en' => 'Student profiles: university, faculty, department, academic year, GPA and status.',
            'ar' => 'ملفات الطلاب: الجامعة والكلية والقسم والسنة الدراسية والمعدل.',
        ],
        'icon' => 'users',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'User account', 'ar' => 'حساب المستخدم']],
            ['column' => 'id', 'references' => 'exam_attempts', 'references_column' => 'student_id',
                'label' => ['en' => 'Exam attempts', 'ar' => 'محاولات الامتحانات']],
            ['column' => 'id', 'references' => 'course_enrollments', 'references_column' => 'student_id',
                'label' => ['en' => 'Course enrollments', 'ar' => 'تسجيلات المقررات']],
            ['column' => 'id', 'references' => 'attendance_records', 'references_column' => 'student_id',
                'label' => ['en' => 'Attendance records', 'ar' => 'سجلات الحضور']],
            ['column' => 'id', 'references' => 'assignment_submissions', 'references_column' => 'student_id',
                'label' => ['en' => 'Assignment submissions', 'ar' => 'تسليمات التكليفات']],
            ['column' => 'id', 'references' => 'course_final_grades', 'references_column' => 'student_id',
                'label' => ['en' => 'Course final grades', 'ar' => 'الدرجات النهائية للمقررات']],
            ['column' => 'id', 'references' => 'course_video_views', 'references_column' => 'student_id',
                'label' => ['en' => 'Video views', 'ar' => 'مشاهدات الفيديو']],
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'faculty_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty', 'ar' => 'الكلية']],
            ['column' => 'department_id', 'references' => 'departments', 'references_column' => 'id',
                'label' => ['en' => 'Department', 'ar' => 'القسم']],
            ['column' => 'group_id', 'references' => 'student_groups', 'references_column' => 'id',
                'label' => ['en' => 'Student group', 'ar' => 'المجموعة الطلابية']],
            ['column' => 'id', 'references' => 'student_fees', 'references_column' => 'student_id',
                'label' => ['en' => 'Fee dues', 'ar' => 'مستحقات الرسوم']],
            ['column' => 'id', 'references' => 'fee_payments', 'references_column' => 'student_id',
                'label' => ['en' => 'Fee payments', 'ar' => 'دفعات الرسوم']],
            ['column' => 'id', 'references' => 'summer_registrations', 'references_column' => 'student_id',
                'label' => ['en' => 'Summer registrations', 'ar' => 'التسجيل الصيفي']],
            ['column' => 'id', 'references' => 'graduation_records', 'references_column' => 'student_id',
                'label' => ['en' => 'Graduation record', 'ar' => 'سجل التخرج']],
        ],
    ],

    'courses' => [
        'table' => 'courses',
        'label' => ['en' => 'Courses', 'ar' => 'المقررات'],
        'description' => [
            'en' => 'Courses by university, faculty and department, linked to exams.',
            'ar' => 'المقررات حسب الجامعة والكلية والقسم، مرتبطة بالامتحانات.',
        ],
        'icon' => 'layers',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'id', 'references' => 'exams', 'references_column' => 'course_id',
                'label' => ['en' => 'Exams of this course', 'ar' => 'امتحانات هذا المقرر']],
            ['column' => 'id', 'references' => 'course_enrollments', 'references_column' => 'course_id',
                'label' => ['en' => 'Enrollments', 'ar' => 'التسجيلات']],
            ['column' => 'id', 'references' => 'course_staff', 'references_column' => 'course_id',
                'label' => ['en' => 'Teaching staff', 'ar' => 'المدرّسون']],
            ['column' => 'id', 'references' => 'course_sessions', 'references_column' => 'course_id',
                'label' => ['en' => 'Timetable sessions', 'ar' => 'محاضرات الجدول']],
            ['column' => 'id', 'references' => 'attendance_records', 'references_column' => 'course_id',
                'label' => ['en' => 'Attendance records', 'ar' => 'سجلات الحضور']],
            ['column' => 'id', 'references' => 'course_assignments', 'references_column' => 'course_id',
                'label' => ['en' => 'Assignments', 'ar' => 'التكليفات']],
            ['column' => 'id', 'references' => 'course_final_grades', 'references_column' => 'course_id',
                'label' => ['en' => 'Final grades', 'ar' => 'الدرجات النهائية']],
            ['column' => 'id', 'references' => 'course_materials', 'references_column' => 'course_id',
                'label' => ['en' => 'Materials', 'ar' => 'المواد']],
            ['column' => 'id', 'references' => 'course_videos', 'references_column' => 'course_id',
                'label' => ['en' => 'Videos', 'ar' => 'الفيديوهات']],
            ['column' => 'faculty_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty (empty = whole university)', 'ar' => 'الكلية (الفارغ = الجامعة كلها)']],
            ['column' => 'department_id', 'references' => 'departments', 'references_column' => 'id',
                'label' => ['en' => 'Department', 'ar' => 'القسم']],
            ['column' => 'id', 'references' => 'summer_offerings', 'references_column' => 'course_id',
                'label' => ['en' => 'Summer offerings', 'ar' => 'العروض الصيفية']],
            ['column' => 'id', 'references' => 'summer_registrations', 'references_column' => 'course_id',
                'label' => ['en' => 'Summer registrations', 'ar' => 'التسجيل الصيفي']],
        ],
    ],

    'exam_questions' => [
        'table' => 'exam_questions',
        'label' => ['en' => 'Exam Questions', 'ar' => 'أسئلة الامتحانات'],
        'description' => [
            'en' => 'Which questions are placed in which exam, with mark overrides and ordering.',
            'ar' => 'الأسئلة الموضوعة في كل امتحان مع تعديل الدرجة والترتيب.',
        ],
        'icon' => 'layers',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
            ['column' => 'question_id', 'references' => 'questions', 'references_column' => 'id',
                'label' => ['en' => 'Question', 'ar' => 'السؤال']],
            ['column' => 'id', 'references' => 'exam_grades', 'references_column' => 'exam_question_id',
                'label' => ['en' => 'Grades', 'ar' => 'الدرجات']],
            ['column' => 'id', 'references' => 'exam_answers', 'references_column' => 'exam_question_id',
                'label' => ['en' => 'Student answers', 'ar' => 'إجابات الطلاب']],
        ],
    ],

    'exam_answers' => [
        'table' => 'exam_answers',
        'label' => ['en' => 'Exam Answers', 'ar' => 'إجابات الامتحانات'],
        'description' => [
            'en' => 'Actual student answers (selected options / free text) per attempt and question.',
            'ar' => 'إجابات الطلاب الفعلية (الاختيارات / النص) لكل محاولة وسؤال.',
        ],
        'icon' => 'note',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'exam_attempt_id', 'references' => 'exam_attempts', 'references_column' => 'id',
                'label' => ['en' => 'Attempt', 'ar' => 'المحاولة']],
            ['column' => 'exam_question_id', 'references' => 'exam_questions', 'references_column' => 'id',
                'label' => ['en' => 'Exam question', 'ar' => 'سؤال الامتحان']],
        ],
    ],

    // ------------------------------------------------------------------
    // Courses, attendance, assignments, grading and course content
    // (read-only analytics). Storage paths, video passwords, HMAC attendance
    // secrets, exact GPS coordinates and device fingerprints are listed in
    // sensitive_columns and are blocked everywhere (Explorer, Query Builder,
    // Advanced Analytics, Data Quality).
    // NOTE: the Query Builder rejects raw SQL that mentions ANY sensitive
    // column name from ANY dataset, so keep these names specific.
    // ------------------------------------------------------------------

    'course_enrollments' => [
        'table' => 'course_enrollments',
        'label' => ['en' => 'Course Enrollments', 'ar' => 'تسجيلات المقررات'],
        'description' => [
            'en' => 'Which student is enrolled in which course, how (self-enrolled or added by staff) and when.',
            'ar' => 'أي طالب مسجَّل في أي مقرر، وبأي طريقة (تسجيل ذاتي أو من الدكتور) ومتى.',
        ],
        'icon' => 'users',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_staff' => [
        'table' => 'course_staff',
        'label' => ['en' => 'Course Staff', 'ar' => 'مدرّسو المقررات'],
        'description' => [
            'en' => 'Which academic staff members teach which course.',
            'ar' => 'أعضاء هيئة التدريس المسؤولون عن كل مقرر.',
        ],
        'icon' => 'briefcase',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Doctor / staff member', 'ar' => 'الدكتور / عضو هيئة التدريس']],
        ],
    ],

    'course_sessions' => [
        'table' => 'course_sessions',
        'label' => ['en' => 'Course Sessions (Timetable)', 'ar' => 'محاضرات المقررات (الجدول)'],
        'description' => [
            'en' => 'Weekly timetable slots per course: kind, day, time, room, group and instructor.',
            'ar' => 'مواعيد المحاضرات الأسبوعية لكل مقرر: النوع واليوم والوقت والقاعة والمجموعة والمحاضر.',
        ],
        'icon' => 'layers',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'academic_staff_id', 'references' => 'academic_staff', 'references_column' => 'id',
                'label' => ['en' => 'Instructor', 'ar' => 'المحاضر']],
            ['column' => 'id', 'references' => 'attendance_windows', 'references_column' => 'course_session_id',
                'label' => ['en' => 'Attendance windows of this session', 'ar' => 'فترات الحضور لهذه المحاضرة']],
        ],
    ],

    'attendance_windows' => [
        'table' => 'attendance_windows',
        'label' => ['en' => 'Attendance Windows', 'ar' => 'فترات تسجيل الحضور'],
        'description' => [
            'en' => 'Each time an instructor opens attendance for a course: opening/closing time, status and radius. The signing secret and exact location are hidden.',
            'ar' => 'كل مرة يفتح فيها الدكتور تسجيل الحضور لمقرر: وقت الفتح والإغلاق والحالة ونصف القطر. السر المستخدم في التوقيع والموقع الدقيق مخفيان.',
        ],
        'icon' => 'check-circle',
        'primary_key' => 'id',
        'default_sort' => 'opens_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['secret', 'lat', 'lng'],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'course_session_id', 'references' => 'course_sessions', 'references_column' => 'id',
                'label' => ['en' => 'Timetable session', 'ar' => 'المحاضرة في الجدول']],
            ['column' => 'id', 'references' => 'attendance_records', 'references_column' => 'window_id',
                'label' => ['en' => 'Attendance records', 'ar' => 'سجلات الحضور']],
        ],
    ],

    'attendance_records' => [
        'table' => 'attendance_records',
        'label' => ['en' => 'Attendance Records', 'ar' => 'سجلات الحضور'],
        'description' => [
            'en' => 'One row per student per attendance window: present, late, absent or excused, the method used and distance from campus/instructor. Exact coordinates and device fingerprints are hidden.',
            'ar' => 'صف لكل طالب في كل فترة حضور: حاضر أو متأخر أو غائب أو بعذر، والطريقة المستخدمة والمسافة من الحرم/الدكتور. الإحداثيات الدقيقة وبصمات الأجهزة مخفية.',
        ],
        'icon' => 'check-circle',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['lat', 'lng', 'device_hash'],
        'relationships' => [
            ['column' => 'window_id', 'references' => 'attendance_windows', 'references_column' => 'id',
                'label' => ['en' => 'Attendance window', 'ar' => 'فترة الحضور']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_assignments' => [
        'table' => 'course_assignments',
        'label' => ['en' => 'Course Assignments', 'ar' => 'تكليفات المقررات'],
        'description' => [
            'en' => 'Assignments per course: title, due date, max score, weight, late policy and status.',
            'ar' => 'التكليفات لكل مقرر: العنوان وموعد التسليم والدرجة العظمى والوزن وسياسة التأخير والحالة.',
        ],
        'icon' => 'note',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['file_path'],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'id', 'references' => 'assignment_submissions', 'references_column' => 'assignment_id',
                'label' => ['en' => 'Submissions', 'ar' => 'التسليمات']],
        ],
    ],

    'assignment_submissions' => [
        'table' => 'assignment_submissions',
        'label' => ['en' => 'Assignment Submissions', 'ar' => 'تسليمات التكليفات'],
        'description' => [
            'en' => 'Student submissions: submitted time, lateness, attempts, status, score and who graded. Stored file paths are hidden.',
            'ar' => 'تسليمات الطلاب: وقت التسليم والتأخير وعدد المحاولات والحالة والدرجة ومن صحّح. مسارات الملفات المخزّنة مخفية.',
        ],
        'icon' => 'note',
        'primary_key' => 'id',
        'default_sort' => 'submitted_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['file_path'],
        'relationships' => [
            ['column' => 'assignment_id', 'references' => 'course_assignments', 'references_column' => 'id',
                'label' => ['en' => 'Assignment', 'ar' => 'التكليف']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_final_grades' => [
        'table' => 'course_final_grades',
        'label' => ['en' => 'Course Final Grades', 'ar' => 'الدرجات النهائية للمقررات'],
        'description' => [
            'en' => 'Final result per student per course: points, percentage, letter, grade points, fail/deprived flags, overrides and publish status.',
            'ar' => 'النتيجة النهائية لكل طالب في كل مقرر: النقاط والنسبة والتقدير وعدد النقاط وحالات الرسوب/الحرمان والتعديل اليدوي وحالة النشر.',
        ],
        'icon' => 'award',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_grade_components' => [
        'table' => 'course_grade_components',
        'label' => ['en' => 'Course Grade Components', 'ar' => 'مكوّنات درجة المقرر'],
        'description' => [
            'en' => 'How each course grade is built: assignment, attendance or manual components with their weights.',
            'ar' => 'طريقة تكوين درجة كل مقرر: مكوّنات (تكليف أو حضور أو يدوي) وأوزانها.',
        ],
        'icon' => 'bar-chart',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'id', 'references' => 'course_grade_component_scores', 'references_column' => 'component_id',
                'label' => ['en' => 'Scores of this component', 'ar' => 'درجات هذا المكوّن']],
        ],
    ],

    'course_grade_component_scores' => [
        'table' => 'course_grade_component_scores',
        'label' => ['en' => 'Grade Component Scores', 'ar' => 'درجات مكوّنات المقرر'],
        'description' => [
            'en' => 'Each student score on each grade component of a course.',
            'ar' => 'درجة كل طالب في كل مكوّن من مكوّنات درجة المقرر.',
        ],
        'icon' => 'bar-chart',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'component_id', 'references' => 'course_grade_components', 'references_column' => 'id',
                'label' => ['en' => 'Grade component', 'ar' => 'مكوّن الدرجة']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_grade_items' => [
        'table' => 'course_grade_items',
        'label' => ['en' => 'Course Grade Exam Items', 'ar' => 'امتحانات داخل درجة المقرر'],
        'description' => [
            'en' => 'Which exams count toward a course grade, their weight and the attempt policy (highest, latest or first).',
            'ar' => 'الامتحانات المحتسبة في درجة المقرر ووزن كل منها وسياسة المحاولات (الأعلى أو الأحدث أو الأولى).',
        ],
        'icon' => 'award',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'exam_id', 'references' => 'exams', 'references_column' => 'id',
                'label' => ['en' => 'Exam', 'ar' => 'الامتحان']],
        ],
    ],

    'grade_scales' => [
        'table' => 'grade_scales',
        'label' => ['en' => 'Grade Scales', 'ar' => 'سلالم التقدير'],
        'description' => [
            'en' => 'Grading scales per scope (global, university or faculty): deprivation policy, approval requirement and GPA precision.',
            'ar' => 'سلالم التقدير حسب النطاق (عام أو جامعة أو كلية): سياسة الحرمان ومتطلب الاعتماد ودقة المعدل.',
        ],
        'icon' => 'award',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'id', 'references' => 'grade_scale_bands', 'references_column' => 'scale_id',
                'label' => ['en' => 'Letter bands of this scale', 'ar' => 'شرائح التقدير لهذا السلّم']],
        ],
    ],

    'grade_scale_bands' => [
        'table' => 'grade_scale_bands',
        'label' => ['en' => 'Grade Scale Bands', 'ar' => 'شرائح سلالم التقدير'],
        'description' => [
            'en' => 'Letter grades of each scale with minimum percentage, grade points and fail flag.',
            'ar' => 'التقديرات الحرفية لكل سلّم مع الحد الأدنى للنسبة وعدد النقاط وعلامة الرسوب.',
        ],
        'icon' => 'award',
        'primary_key' => 'id',
        'default_sort' => 'id',
        'default_sort_dir' => 'asc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'scale_id', 'references' => 'grade_scales', 'references_column' => 'id',
                'label' => ['en' => 'Grade scale', 'ar' => 'سلّم التقدير']],
        ],
    ],

    'course_materials' => [
        'table' => 'course_materials',
        'label' => ['en' => 'Course Materials', 'ar' => 'مواد المقررات'],
        'description' => [
            'en' => 'Lectures, files, links and announcements posted per course, with kind, status, pinning and author role. Stored file paths are hidden.',
            'ar' => 'المحاضرات والملفات والروابط والإعلانات المنشورة لكل مقرر مع النوع والحالة والتثبيت ودور الناشر. مسارات الملفات المخزّنة مخفية.',
        ],
        'icon' => 'file',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['file_path'],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'id', 'references' => 'course_material_saves', 'references_column' => 'material_id',
                'label' => ['en' => 'Student saves', 'ar' => 'حفظ الطلاب']],
        ],
    ],

    'course_material_saves' => [
        'table' => 'course_material_saves',
        'label' => ['en' => 'Course Material Saves', 'ar' => 'حفظ الطلاب لمواد المقررات'],
        'description' => [
            'en' => 'Which students saved which course materials (an engagement signal).',
            'ar' => 'أي الطلاب حفظوا أي مواد مقررات (مؤشر تفاعل).',
        ],
        'icon' => 'file',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'material_id', 'references' => 'course_materials', 'references_column' => 'id',
                'label' => ['en' => 'Material', 'ar' => 'المادة']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    'course_videos' => [
        'table' => 'course_videos',
        'label' => ['en' => 'Course Videos', 'ar' => 'فيديوهات المقررات'],
        'description' => [
            'en' => 'Recorded lecture videos per course: duration, order and status. Stored file paths and video passwords are hidden.',
            'ar' => 'فيديوهات المحاضرات المسجّلة لكل مقرر: المدة والترتيب والحالة. مسارات الملفات وكلمات سر الفيديو مخفية.',
        ],
        'icon' => 'folder',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['file_path', 'password_hash'],
        'relationships' => [
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'id', 'references' => 'course_video_views', 'references_column' => 'video_id',
                'label' => ['en' => 'Student views', 'ar' => 'مشاهدات الطلاب']],
        ],
    ],

    'course_video_views' => [
        'table' => 'course_video_views',
        'label' => ['en' => 'Course Video Views', 'ar' => 'مشاهدات فيديوهات المقررات'],
        'description' => [
            'en' => 'Per student per video watch progress: watched seconds, percent, play count and completion.',
            'ar' => 'تقدّم مشاهدة كل طالب لكل فيديو: الثواني المشاهدة والنسبة وعدد مرات التشغيل والإكمال.',
        ],
        'icon' => 'trend',
        'primary_key' => 'id',
        'default_sort' => 'last_watched_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'video_id', 'references' => 'course_videos', 'references_column' => 'id',
                'label' => ['en' => 'Video', 'ar' => 'الفيديو']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
        ],
    ],

    // ------------------------------------------------------------------
    // Registration, fees and summer terms (read-only analytics).
    // Bank-transfer references and fee-waiver notes are listed in
    // sensitive_columns and are blocked everywhere (Explorer, Query Builder,
    // Advanced Analytics, Data Quality). NOTE: the Query Builder rejects raw
    // SQL that mentions ANY sensitive column name from ANY dataset.
    // scope_type/scope_id on registration_periods/policies is polymorphic
    // (university | faculty), so both relationships are declared with a label
    // that says which scope_type applies.
    // ------------------------------------------------------------------

    'registration_periods' => [
        'table' => 'registration_periods',
        'label' => ['en' => 'Registration Periods', 'ar' => 'فترات التسجيل'],
        'description' => [
            'en' => 'Course enrollment/drop windows per university or faculty: kind (enroll, drop, both), opening and closing time.',
            'ar' => 'فترات تسجيل وحذف المقررات لكل جامعة أو كلية: النوع (تسجيل أو حذف أو الاثنين) ووقت الفتح والإغلاق.',
        ],
        'icon' => 'calendar',
        'primary_key' => 'id',
        'default_sort' => 'opens_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'scope_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University (when scope_type = university)', 'ar' => 'الجامعة (لو النطاق جامعة)']],
            ['column' => 'scope_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty (when scope_type = faculty)', 'ar' => 'الكلية (لو النطاق كلية)']],
            ['column' => 'created_by_user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Created by', 'ar' => 'أنشأها']],
        ],
    ],

    'registration_policies' => [
        'table' => 'registration_policies',
        'label' => ['en' => 'Registration Policies', 'ar' => 'سياسات التسجيل'],
        'description' => [
            'en' => 'Registration rules per university or faculty: period/prerequisite/hours/conflict enforcement and GPA-based credit-hour limits (empty = inherited).',
            'ar' => 'قواعد التسجيل لكل جامعة أو كلية: إلزام الفترات والمتطلبات السابقة والساعات والتعارض وحدود الساعات حسب المعدل (الفارغ = موروث).',
        ],
        'icon' => 'settings',
        'primary_key' => 'id',
        'default_sort' => 'updated_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'scope_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University (when scope_type = university)', 'ar' => 'الجامعة (لو النطاق جامعة)']],
            ['column' => 'scope_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty (when scope_type = faculty)', 'ar' => 'الكلية (لو النطاق كلية)']],
            ['column' => 'updated_by_user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Last updated by', 'ar' => 'آخر تعديل بواسطة']],
        ],
    ],

    'fee_items' => [
        'table' => 'fee_items',
        'label' => ['en' => 'Fee Items', 'ar' => 'بنود الرسوم'],
        'description' => [
            'en' => 'Fee items issued by a university or faculty: kind (tuition, registration, service, other), amount, currency and due date.',
            'ar' => 'بنود الرسوم التي تصدرها الجامعة أو الكلية: النوع (مصاريف دراسية أو تسجيل أو خدمة أو أخرى) والمبلغ والعملة وتاريخ الاستحقاق.',
        ],
        'icon' => 'tag',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'faculty_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty (empty = whole university)', 'ar' => 'الكلية (الفارغ = الجامعة كلها)']],
            ['column' => 'id', 'references' => 'student_fees', 'references_column' => 'fee_item_id',
                'label' => ['en' => 'Student dues of this item', 'ar' => 'مستحقات الطلاب لهذا البند']],
            ['column' => 'created_by_user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Created by', 'ar' => 'أنشأه']],
        ],
    ],

    'student_fees' => [
        'table' => 'student_fees',
        'label' => ['en' => 'Student Fees', 'ar' => 'مستحقات الطلاب'],
        'description' => [
            'en' => 'What each student owes per fee item: amount, paid amount, due date and status (unpaid, partial, paid, waived). Waiver notes are hidden.',
            'ar' => 'المستحق على كل طالب لكل بند رسوم: المبلغ والمدفوع وتاريخ الاستحقاق والحالة (غير مدفوع أو جزئي أو مدفوع أو معفى). ملاحظات الإعفاء مخفية.',
        ],
        'icon' => 'dollar',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['waive_note'],
        'relationships' => [
            ['column' => 'fee_item_id', 'references' => 'fee_items', 'references_column' => 'id',
                'label' => ['en' => 'Fee item', 'ar' => 'بند الرسوم']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
            ['column' => 'id', 'references' => 'fee_payments', 'references_column' => 'student_fee_id',
                'label' => ['en' => 'Payments against this due', 'ar' => 'الدفعات على هذا المستحق']],
        ],
    ],

    'fee_payments' => [
        'table' => 'fee_payments',
        'label' => ['en' => 'Fee Payments', 'ar' => 'دفعات الرسوم'],
        'description' => [
            'en' => 'Payments against student fees: amount, method (cash, bank transfer, card, other), status (pending, confirmed, rejected) and decision time. Transfer references are hidden.',
            'ar' => 'الدفعات على مستحقات الطلاب: المبلغ وطريقة الدفع (نقدي أو تحويل بنكي أو بطاقة أو أخرى) والحالة (معلّقة أو مؤكَّدة أو مرفوضة) ووقت القرار. مراجع التحويل مخفية.',
        ],
        'icon' => 'dollar',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['reference'],
        'relationships' => [
            ['column' => 'student_fee_id', 'references' => 'student_fees', 'references_column' => 'id',
                'label' => ['en' => 'Student fee (due)', 'ar' => 'المستحق']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
            ['column' => 'recorded_by_user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Recorded by', 'ar' => 'سجّلها']],
        ],
    ],

    'summer_terms' => [
        'table' => 'summer_terms',
        'label' => ['en' => 'Summer Terms', 'ar' => 'الفصول الصيفية'],
        'description' => [
            'en' => 'Summer terms per university or faculty: registration window, term dates, maximum credit hours and failed-courses-only flag.',
            'ar' => 'الفصول الصيفية لكل جامعة أو كلية: فترة التسجيل وتواريخ الفصل والحد الأقصى للساعات وعلامة "المواد الراسب فيها فقط".',
        ],
        'icon' => 'sun',
        'primary_key' => 'id',
        'default_sort' => 'opens_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'faculty_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty (empty = whole university)', 'ar' => 'الكلية (الفارغ = الجامعة كلها)']],
            ['column' => 'id', 'references' => 'summer_offerings', 'references_column' => 'term_id',
                'label' => ['en' => 'Offered courses', 'ar' => 'المواد المعروضة']],
            ['column' => 'id', 'references' => 'summer_registrations', 'references_column' => 'term_id',
                'label' => ['en' => 'Registration requests', 'ar' => 'طلبات التسجيل']],
        ],
    ],

    'summer_offerings' => [
        'table' => 'summer_offerings',
        'label' => ['en' => 'Summer Offerings', 'ar' => 'المواد المعروضة صيفيًا'],
        'description' => [
            'en' => 'Courses offered in each summer term with their seat capacity (empty = unlimited).',
            'ar' => 'المواد المعروضة في كل فصل صيفي مع السعة (الفارغ = بدون حد).',
        ],
        'icon' => 'layers',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'term_id', 'references' => 'summer_terms', 'references_column' => 'id',
                'label' => ['en' => 'Summer term', 'ar' => 'الفصل الصيفي']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
        ],
    ],

    'summer_registrations' => [
        'table' => 'summer_registrations',
        'label' => ['en' => 'Summer Registrations', 'ar' => 'التسجيل الصيفي'],
        'description' => [
            'en' => 'Student summer-course requests: status (pending, approved, rejected, cancelled), who decided, in which role, and when.',
            'ar' => 'طلبات الطلاب لمواد الصيفي: الحالة (معلّق أو موافق أو مرفوض أو ملغي) ومن قرّر وبأي دور ومتى.',
        ],
        'icon' => 'sun',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'term_id', 'references' => 'summer_terms', 'references_column' => 'id',
                'label' => ['en' => 'Summer term', 'ar' => 'الفصل الصيفي']],
            ['column' => 'course_id', 'references' => 'courses', 'references_column' => 'id',
                'label' => ['en' => 'Course', 'ar' => 'المقرر']],
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Student', 'ar' => 'الطالب']],
            ['column' => 'decided_by_user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Decided by', 'ar' => 'قرّر']],
        ],
    ],

    // ------------------------------------------------------------------
    // Academic structure and graduation (read-only analytics).
    // Stored image paths (logos, signatures, stamps), exact campus GPS,
    // faculty contact details and graduation certificate numbers are listed in
    // sensitive_columns and are blocked everywhere. universities and
    // departments use soft-delete (deleted_at), so removed rows are excluded
    // automatically from the Explorer and the Query Builder.
    // ------------------------------------------------------------------

    'universities' => [
        'table' => 'universities',
        'label' => ['en' => 'Universities', 'ar' => 'الجامعات'],
        'description' => [
            'en' => 'Universities on the platform: names, country, city, verification status and expiry, visibility and email domain. Logos, signatures and stamps are hidden.',
            'ar' => 'الجامعات على المنصة: الأسماء والدولة والمدينة وحالة التوثيق وانتهائه والظهور ودومين الإيميل. الشعارات والتوقيعات والأختام مخفية.',
        ],
        'icon' => 'building',
        'primary_key' => 'id',
        'soft_deletes' => true,
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['logo_path', 'signature_path', 'stamp_path', 'dean_signature_path'],
        'relationships' => [
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'University account', 'ar' => 'حساب الجامعة']],
            ['column' => 'id', 'references' => 'faculties', 'references_column' => 'university_id',
                'label' => ['en' => 'Faculties', 'ar' => 'الكليات']],
            ['column' => 'id', 'references' => 'student_groups', 'references_column' => 'university_id',
                'label' => ['en' => 'Student groups', 'ar' => 'المجموعات الطلابية']],
            ['column' => 'id', 'references' => 'students', 'references_column' => 'university_id',
                'label' => ['en' => 'Students', 'ar' => 'الطلاب']],
            ['column' => 'id', 'references' => 'graduation_records', 'references_column' => 'university_id',
                'label' => ['en' => 'Graduation records', 'ar' => 'سجلات التخرج']],
            ['column' => 'id', 'references' => 'fee_items', 'references_column' => 'university_id',
                'label' => ['en' => 'Fee items', 'ar' => 'بنود الرسوم']],
            ['column' => 'id', 'references' => 'summer_terms', 'references_column' => 'university_id',
                'label' => ['en' => 'Summer terms', 'ar' => 'الفصول الصيفية']],
        ],
    ],

    'faculties' => [
        'table' => 'faculties',
        'label' => ['en' => 'Faculties', 'ar' => 'الكليات'],
        'description' => [
            'en' => 'Faculties per university: names, status, visibility and campus geofence radius. Images, contact details and exact campus coordinates are hidden.',
            'ar' => 'الكليات لكل جامعة: الأسماء والحالة والظهور ونصف قطر نطاق الحرم. الصور وبيانات التواصل وإحداثيات الحرم الدقيقة مخفية.',
        ],
        'icon' => 'building',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['logo_path', 'cover_path', 'contact_email', 'contact_phone', 'campus_lat', 'campus_lng'],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Faculty account', 'ar' => 'حساب الكلية']],
            ['column' => 'id', 'references' => 'departments', 'references_column' => 'faculty_id',
                'label' => ['en' => 'Departments', 'ar' => 'الأقسام']],
            ['column' => 'id', 'references' => 'students', 'references_column' => 'faculty_id',
                'label' => ['en' => 'Students', 'ar' => 'الطلاب']],
            ['column' => 'id', 'references' => 'courses', 'references_column' => 'faculty_id',
                'label' => ['en' => 'Courses', 'ar' => 'المقررات']],
            ['column' => 'id', 'references' => 'fee_items', 'references_column' => 'faculty_id',
                'label' => ['en' => 'Fee items', 'ar' => 'بنود الرسوم']],
            ['column' => 'id', 'references' => 'summer_terms', 'references_column' => 'faculty_id',
                'label' => ['en' => 'Summer terms', 'ar' => 'الفصول الصيفية']],
        ],
    ],

    'departments' => [
        'table' => 'departments',
        'label' => ['en' => 'Departments', 'ar' => 'الأقسام'],
        'description' => [
            'en' => 'Academic departments per faculty: names, status and visibility.',
            'ar' => 'الأقسام الأكاديمية لكل كلية: الأسماء والحالة والظهور.',
        ],
        'icon' => 'layers',
        'primary_key' => 'id',
        'soft_deletes' => true,
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'faculty_id', 'references' => 'faculties', 'references_column' => 'id',
                'label' => ['en' => 'Faculty', 'ar' => 'الكلية']],
            ['column' => 'id', 'references' => 'students', 'references_column' => 'department_id',
                'label' => ['en' => 'Students', 'ar' => 'الطلاب']],
            ['column' => 'id', 'references' => 'courses', 'references_column' => 'department_id',
                'label' => ['en' => 'Courses', 'ar' => 'المقررات']],
        ],
    ],

    'student_groups' => [
        'table' => 'student_groups',
        'label' => ['en' => 'Student Groups', 'ar' => 'المجموعات الطلابية'],
        'description' => [
            'en' => 'Student groups (sections/batches) per university with their member limit. Membership is the group_id column on students.',
            'ar' => 'المجموعات الطلابية (الشُّعَب/الدفعات) لكل جامعة مع حد الأعضاء. العضوية في عمود group_id بجدول الطلاب.',
        ],
        'icon' => 'users',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'id', 'references' => 'students', 'references_column' => 'group_id',
                'label' => ['en' => 'Member students', 'ar' => 'الطلاب الأعضاء']],
        ],
    ],

    'graduation_records' => [
        'table' => 'graduation_records',
        'label' => ['en' => 'Graduation Records', 'ar' => 'سجلات التخرج'],
        'description' => [
            'en' => 'One record per graduate: graduation date, final GPA, degree/faculty/department names and status (graduated or revoked). Certificate numbers are hidden.',
            'ar' => 'سجل لكل خريج: تاريخ التخرج والمعدل النهائي وأسماء الدرجة والكلية والقسم والحالة (متخرج أو ملغى). أرقام الشهادات مخفية.',
        ],
        'icon' => 'graduation-cap',
        'primary_key' => 'id',
        'default_sort' => 'graduation_date',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => ['certificate_number'],
        'relationships' => [
            ['column' => 'student_id', 'references' => 'students', 'references_column' => 'id',
                'label' => ['en' => 'Graduate (student)', 'ar' => 'الخريج (الطالب)']],
            ['column' => 'university_id', 'references' => 'universities', 'references_column' => 'id',
                'label' => ['en' => 'University', 'ar' => 'الجامعة']],
            ['column' => 'approved_by', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Approved by', 'ar' => 'اعتمده']],
            ['column' => 'revoked_by', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Revoked by', 'ar' => 'ألغاه']],
        ],
    ],

];
