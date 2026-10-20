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
 * six plus the exam platform, staff, students and courses tables) — this is
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

];
