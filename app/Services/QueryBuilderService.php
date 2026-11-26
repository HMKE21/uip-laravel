<?php

namespace App\Services;

use App\Repositories\QueryBuilderRepository;
use RuntimeException;

/**
 * منقولة من app/Services/QueryBuilderService.php القديمة — بند 24
 * batch 4 (SQL Query Builder، enhancement spec section 3). طبقة
 * أوركستريشن فوق QueryBuilderRepository: بتحوّل طلب تشغيل (builder أو
 * نص SQL خام) لـ SQL اتفحص، بتنفذه، ودايمًا بتسجل المحاولة (نجحت أو
 * فشلت) في query_history. كمان بتعرض مجموعة صغيرة من قوالب استعلام
 * جاهزة (متطلب Query Templates) متولّدة من الكتالوج الحي بدل أسماء
 * جداول مكتوبة يدويًا، عشان عمرها ما تنحرف عن السكيما الحقيقية.
 */
class QueryBuilderService
{
    public function __construct(private QueryBuilderRepository $repo)
    {
    }

    public function catalog(): array
    {
        return $this->repo->catalog();
    }

    /** @return array<int,array<string,mixed>> علاقات الجداول الأساسية المعلنة، لـ builder الـ JOIN المرئي */
    public function relationshipHints(): array
    {
        $hints = [];
        foreach ($this->repo->catalog() as $key => $entry) {
            foreach ($entry['relationships'] ?? [] as $rel) {
                $hints[] = [
                    'from_table' => $entry['table'],
                    'from_key'   => $key,
                    'column'     => $rel['column'],
                    'to_table'   => $rel['references'],
                    'to_column'  => $rel['references_column'],
                    'label'      => $rel['label'] ?? null,
                ];
            }
        }
        return $hints;
    }

    /**
     * قوالب جاهزة مكتوبة يدويًا لمنصة الامتحانات + الدكاترة + الطلاب + التسجيل
     * والرسوم والفصول الصيفية + الهيكل الأكاديمي + التخرج. كلها
     * SELECT واحد، بتستخدم جداول من الـ allow-list بس ومفيهاش أعمدة حساسة،
     * فبتعدي من validateRawSql() زي أي استعلام مكتوب بإيد المحلل.
     * (الجداول soft-delete بتتفلتر تلقائيًا في QueryBuilderRepository.)
     *
     * @return array<int,array<string,string>>
     */
    private function curatedTemplates(): array
    {
        return [
            [
                'category'       => 'exams',
                'name'           => 'Exams by status',
                'name_ar'        => 'الامتحانات حسب الحالة',
                'description'    => 'Count of exams in each lifecycle status.',
                'description_ar' => 'عدد الامتحانات في كل حالة.',
                'sql_text'       => <<<'SQL'
SELECT status, COUNT(*) AS exams
FROM exams
WHERE deleted_at IS NULL
GROUP BY status
ORDER BY exams DESC
SQL,
            ],
            [
                'category'       => 'exams',
                'name'           => 'Exams by type',
                'name_ar'        => 'الامتحانات حسب النوع',
                'description'    => 'Exam count, average duration and total marks per exam type.',
                'description_ar' => 'عدد الامتحانات ومتوسط المدة والدرجة الكلية لكل نوع.',
                'sql_text'       => <<<'SQL'
SELECT exam_type, COUNT(*) AS exams, ROUND(AVG(duration_minutes), 1) AS avg_duration_min, ROUND(AVG(total_marks), 1) AS avg_total_marks
FROM exams
WHERE deleted_at IS NULL
GROUP BY exam_type
ORDER BY exams DESC
SQL,
            ],
            [
                'category'       => 'exams',
                'name'           => 'Upcoming exams',
                'name_ar'        => 'الامتحانات القادمة',
                'description'    => 'Exams scheduled to start from now on.',
                'description_ar' => 'الامتحانات المجدولة من الآن فصاعدًا.',
                'sql_text'       => <<<'SQL'
SELECT id, title, exam_type, status, start_at, end_at, duration_minutes
FROM exams
WHERE deleted_at IS NULL AND start_at >= CURRENT_TIMESTAMP
ORDER BY start_at ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'exams',
                'name'           => 'Exams per course',
                'name_ar'        => 'الامتحانات لكل مقرر',
                'description'    => 'Number of exams linked to each course.',
                'description_ar' => 'عدد الامتحانات المرتبطة بكل مقرر.',
                'sql_text'       => <<<'SQL'
SELECT c.code, c.name_en AS course, COUNT(e.id) AS exams
FROM courses c
LEFT JOIN exams e ON e.course_id = c.id AND e.deleted_at IS NULL
GROUP BY c.id, c.code, c.name_en
ORDER BY exams DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'results',
                'name'           => 'Pass rate per exam',
                'name_ar'        => 'نسبة النجاح لكل امتحان',
                'description'    => 'Attempts, average percentage and pass rate for each graded exam.',
                'description_ar' => 'عدد المحاولات ومتوسط النسبة ونسبة النجاح لكل امتحان مُصحَّح.',
                'sql_text'       => <<<'SQL'
SELECT e.id, e.title, COUNT(a.id) AS attempts, ROUND(AVG(a.percentage), 2) AS avg_percentage,
       ROUND(100.0 * SUM(CASE WHEN a.score >= e.passing_score THEN 1 ELSE 0 END) / COUNT(a.id), 1) AS pass_rate_pct
FROM exams e
INNER JOIN exam_attempts a ON a.exam_id = e.id
WHERE a.status = 'graded' AND e.deleted_at IS NULL AND e.passing_score IS NOT NULL
GROUP BY e.id, e.title
ORDER BY attempts DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'results',
                'name'           => 'Score distribution',
                'name_ar'        => 'توزيع الدرجات',
                'description'    => 'Graded attempts bucketed by percentage.',
                'description_ar' => 'المحاولات المُصحَّحة موزعة على شرائح النسبة المئوية.',
                'sql_text'       => <<<'SQL'
SELECT CASE WHEN percentage < 50 THEN '0-49'
            WHEN percentage < 60 THEN '50-59'
            WHEN percentage < 70 THEN '60-69'
            WHEN percentage < 80 THEN '70-79'
            WHEN percentage < 90 THEN '80-89'
            ELSE '90-100' END AS score_range,
       COUNT(*) AS attempts
FROM exam_attempts
WHERE status = 'graded' AND percentage IS NOT NULL
GROUP BY score_range
ORDER BY score_range
SQL,
            ],
            [
                'category'       => 'results',
                'name'           => 'Attempts by status',
                'name_ar'        => 'المحاولات حسب الحالة',
                'description'    => 'Distribution of attempts across statuses.',
                'description_ar' => 'توزيع المحاولات على الحالات.',
                'sql_text'       => <<<'SQL'
SELECT status, COUNT(*) AS attempts
FROM exam_attempts
GROUP BY status
ORDER BY attempts DESC
SQL,
            ],
            [
                'category'       => 'results',
                'name'           => 'Late submissions',
                'name_ar'        => 'التسليمات المتأخرة',
                'description'    => 'Late attempts with the penalty applied.',
                'description_ar' => 'المحاولات المتأخرة مع نسبة الخصم المطبقة.',
                'sql_text'       => <<<'SQL'
SELECT a.id, e.title, a.attempt_number, a.submitted_at, a.late_penalty_percent, a.score_before_penalty, a.score
FROM exam_attempts a
INNER JOIN exams e ON e.id = a.exam_id
WHERE a.is_late = 1
ORDER BY a.submitted_at DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'results',
                'name'           => 'Grading source breakdown',
                'name_ar'        => 'مصادر التصحيح',
                'description'    => 'Who graded the answers: automatic, instructor, AI, etc.',
                'description_ar' => 'من قام بالتصحيح: تلقائي، دكتور، ذكاء اصطناعي...',
                'sql_text'       => <<<'SQL'
SELECT source, COUNT(*) AS grades, ROUND(AVG(marks_awarded), 2) AS avg_marks, ROUND(AVG(max_marks), 2) AS avg_max_marks
FROM exam_grades
WHERE source IS NOT NULL
GROUP BY source
ORDER BY grades DESC
SQL,
            ],
            [
                'category'       => 'integrity',
                'name'           => 'Security events by type',
                'name_ar'        => 'الأحداث الأمنية حسب النوع',
                'description'    => 'Proctoring event counts and how many were violations.',
                'description_ar' => 'عدد أحداث المراقبة وكم منها مخالفات.',
                'sql_text'       => <<<'SQL'
SELECT event_type, COUNT(*) AS events, SUM(CASE WHEN is_violation = 1 THEN 1 ELSE 0 END) AS violations
FROM exam_security_events
GROUP BY event_type
ORDER BY events DESC
SQL,
            ],
            [
                'category'       => 'integrity',
                'name'           => 'Attempts with most violations',
                'name_ar'        => 'أكثر المحاولات مخالفات',
                'description'    => 'Attempts with violations, with student and exam.',
                'description_ar' => 'المحاولات ذات المخالفات مع الطالب والامتحان.',
                'sql_text'       => <<<'SQL'
SELECT a.id AS attempt_id, u.full_name AS student, e.title AS exam, a.violations_count, a.status, a.percentage
FROM exam_attempts a
INNER JOIN exams e ON e.id = a.exam_id
INNER JOIN students s ON s.id = a.student_id
INNER JOIN users u ON u.id = s.user_id
WHERE a.violations_count > 0
ORDER BY a.violations_count DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'integrity',
                'name'           => 'Pending similarity flags',
                'name_ar'        => 'تنبيهات التشابه المعلقة',
                'description'    => 'Highest answer-similarity flags still awaiting review.',
                'description_ar' => 'أعلى تنبيهات تشابه الإجابات التي لم تُراجع بعد.',
                'sql_text'       => <<<'SQL'
SELECT f.id, e.title AS exam, f.attempt_a_id, f.attempt_b_id, f.similarity, f.matched_words, f.created_at
FROM exam_similarity_flags f
INNER JOIN exams e ON e.id = f.exam_id
WHERE f.status = 'pending'
ORDER BY f.similarity DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'integrity',
                'name'           => 'Grade appeals summary',
                'name_ar'        => 'ملخص التظلمات',
                'description'    => 'Appeals by status with the average score change.',
                'description_ar' => 'التظلمات حسب الحالة مع متوسط تغيّر الدرجة.',
                'sql_text'       => <<<'SQL'
SELECT status, COUNT(*) AS appeals, ROUND(AVG(score_after - score_before), 2) AS avg_score_change
FROM exam_grade_appeals
GROUP BY status
ORDER BY appeals DESC
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Questions by type and difficulty',
                'name_ar'        => 'الأسئلة حسب النوع والصعوبة',
                'description'    => 'Question bank composition.',
                'description_ar' => 'تركيبة بنوك الأسئلة.',
                'sql_text'       => <<<'SQL'
SELECT type, difficulty, COUNT(*) AS questions
FROM questions
WHERE deleted_at IS NULL
GROUP BY type, difficulty
ORDER BY type, difficulty
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Question banks by university',
                'name_ar'        => 'بنوك الأسئلة لكل جامعة',
                'description'    => 'Question banks and their status per university.',
                'description_ar' => 'عدد بنوك الأسئلة وحالتها لكل جامعة.',
                'sql_text'       => <<<'SQL'
SELECT university_id, status, COUNT(*) AS banks
FROM question_banks
WHERE deleted_at IS NULL
GROUP BY university_id, status
ORDER BY banks DESC
SQL,
            ],
            [
                'category'       => 'doctors',
                'name'           => 'Doctors and their exams',
                'name_ar'        => 'الدكاترة وامتحاناتهم',
                'description'    => 'Each doctor with the number of exams created.',
                'description_ar' => 'كل دكتور مع عدد الامتحانات التي أنشأها.',
                'sql_text'       => <<<'SQL'
SELECT st.id, u.full_name AS doctor, st.staff_number, st.status, COUNT(e.id) AS exams
FROM academic_staff st
INNER JOIN users u ON u.id = st.user_id
LEFT JOIN exams e ON e.created_by_academic_staff_id = st.id AND e.deleted_at IS NULL
GROUP BY st.id, u.full_name, st.staff_number, st.status
ORDER BY exams DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'doctors',
                'name'           => 'Doctors per university',
                'name_ar'        => 'الدكاترة لكل جامعة',
                'description'    => 'Teaching staff count per university and status.',
                'description_ar' => 'عدد هيئة التدريس لكل جامعة وحالة.',
                'sql_text'       => <<<'SQL'
SELECT university_id, status, COUNT(*) AS doctors
FROM academic_staff
GROUP BY university_id, status
ORDER BY doctors DESC
SQL,
            ],
            [
                'category'       => 'doctors',
                'name'           => 'Doctor grading workload',
                'name_ar'        => 'عبء التصحيح لكل دكتور',
                'description'    => 'Manual grades entered by each doctor.',
                'description_ar' => 'عدد الدرجات التي صححها كل دكتور يدويًا.',
                'sql_text'       => <<<'SQL'
SELECT g.graded_by_academic_staff_id AS doctor_id, u.full_name AS doctor, COUNT(*) AS grades, ROUND(AVG(g.marks_awarded), 2) AS avg_marks
FROM exam_grades g
INNER JOIN academic_staff st ON st.id = g.graded_by_academic_staff_id
INNER JOIN users u ON u.id = st.user_id
GROUP BY g.graded_by_academic_staff_id, u.full_name
ORDER BY grades DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'doctors',
                'name'           => 'Doctor invitations',
                'name_ar'        => 'حالة دعوات الدكاترة',
                'description'    => 'Invitation status of teaching staff.',
                'description_ar' => 'حالة دعوات هيئة التدريس.',
                'sql_text'       => <<<'SQL'
SELECT invitation_status, COUNT(*) AS doctors
FROM academic_staff
GROUP BY invitation_status
ORDER BY doctors DESC
SQL,
            ],
            [
                'category'       => 'students',
                'name'           => 'Top students by exam average',
                'name_ar'        => 'أفضل الطلاب في الامتحانات',
                'description'    => 'Highest average percentage (2+ graded attempts).',
                'description_ar' => 'أعلى متوسط نسبة (بحد أدنى محاولتين مُصحَّحتين).',
                'sql_text'       => <<<'SQL'
SELECT s.id, u.full_name AS student, s.student_number, COUNT(a.id) AS attempts, ROUND(AVG(a.percentage), 2) AS avg_percentage
FROM students s
INNER JOIN users u ON u.id = s.user_id
INNER JOIN exam_attempts a ON a.student_id = s.id
WHERE a.status = 'graded' AND a.percentage IS NOT NULL
GROUP BY s.id, u.full_name, s.student_number
HAVING COUNT(a.id) >= 2
ORDER BY avg_percentage DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'students',
                'name'           => 'Students at risk',
                'name_ar'        => 'الطلاب المعرّضون للخطر',
                'description'    => 'Students whose graded-exam average is under 50%.',
                'description_ar' => 'طلاب متوسط درجاتهم في الامتحانات أقل من 50%.',
                'sql_text'       => <<<'SQL'
SELECT s.id, u.full_name AS student, s.student_number, COUNT(a.id) AS attempts, ROUND(AVG(a.percentage), 2) AS avg_percentage
FROM students s
INNER JOIN users u ON u.id = s.user_id
INNER JOIN exam_attempts a ON a.student_id = s.id
WHERE a.status = 'graded' AND a.percentage IS NOT NULL
GROUP BY s.id, u.full_name, s.student_number
HAVING AVG(a.percentage) < 50
ORDER BY avg_percentage ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'students',
                'name'           => 'Students by academic year',
                'name_ar'        => 'الطلاب حسب السنة الدراسية',
                'description'    => 'Student count and average GPA per academic year.',
                'description_ar' => 'عدد الطلاب ومتوسط المعدل لكل سنة دراسية.',
                'sql_text'       => <<<'SQL'
SELECT academic_year, COUNT(*) AS students, ROUND(AVG(gpa), 2) AS avg_gpa
FROM students
GROUP BY academic_year
ORDER BY academic_year
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Hardest questions',
                'name_ar'        => 'أصعب الأسئلة',
                'description'    => 'Questions with the lowest average score percentage (5+ graded answers).',
                'description_ar' => 'الأسئلة الأقل في متوسط نسبة الدرجة (5 إجابات مُصحَّحة على الأقل).',
                'sql_text'       => <<<'SQL'
SELECT q.id AS question_id, q.type, q.difficulty, q.topic, COUNT(g.id) AS graded_answers,
       ROUND(100.0 * SUM(g.marks_awarded) / SUM(g.max_marks), 1) AS avg_score_pct
FROM exam_grades g
INNER JOIN exam_questions eq ON eq.id = g.exam_question_id
INNER JOIN questions q ON q.id = eq.question_id
WHERE g.marks_awarded IS NOT NULL AND g.max_marks > 0
GROUP BY q.id, q.type, q.difficulty, q.topic
HAVING COUNT(g.id) >= 5
ORDER BY avg_score_pct ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Most missed questions',
                'name_ar'        => 'أكثر الأسئلة خطأً',
                'description'    => 'Questions answered incorrectly most often (auto-gradable).',
                'description_ar' => 'الأسئلة التي يخطئ فيها الطلاب أكثر (القابلة للتصحيح التلقائي).',
                'sql_text'       => <<<'SQL'
SELECT q.id AS question_id, q.type, q.topic, COUNT(g.id) AS answers,
       SUM(CASE WHEN g.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_answers,
       ROUND(100.0 * SUM(CASE WHEN g.is_correct = 0 THEN 1 ELSE 0 END) / COUNT(g.id), 1) AS wrong_pct
FROM exam_grades g
INNER JOIN exam_questions eq ON eq.id = g.exam_question_id
INNER JOIN questions q ON q.id = eq.question_id
WHERE g.is_correct IS NOT NULL
GROUP BY q.id, q.type, q.topic
HAVING COUNT(g.id) >= 5
ORDER BY wrong_pct DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Question reuse across exams',
                'name_ar'        => 'إعادة استخدام الأسئلة',
                'description'    => 'How many exams each question is used in.',
                'description_ar' => 'عدد الامتحانات التي يُستخدم فيها كل سؤال.',
                'sql_text'       => <<<'SQL'
SELECT q.id AS question_id, q.type, q.topic, COUNT(DISTINCT eq.exam_id) AS exams_used
FROM questions q
INNER JOIN exam_questions eq ON eq.question_id = q.id
GROUP BY q.id, q.type, q.topic
ORDER BY exams_used DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'questions',
                'name'           => 'Unanswered questions per exam',
                'name_ar'        => 'الأسئلة بدون إجابة لكل امتحان',
                'description'    => 'Blank answers (no text and no selected options) per exam.',
                'description_ar' => 'الإجابات الفارغة (بدون نص أو اختيار) لكل امتحان.',
                'sql_text'       => <<<'SQL'
SELECT e.id AS exam_id, e.title, COUNT(ans.id) AS answers,
       SUM(CASE WHEN (ans.answer_text IS NULL OR ans.answer_text = '') AND ans.selected_option_ids IS NULL THEN 1 ELSE 0 END) AS blank_answers
FROM exam_answers ans
INNER JOIN exam_questions eq ON eq.id = ans.exam_question_id
INNER JOIN exams e ON e.id = eq.exam_id
GROUP BY e.id, e.title
ORDER BY blank_answers DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'registration',
                'name'           => 'Registration periods by scope and kind',
                'name_ar'        => 'فترات التسجيل حسب النطاق والنوع',
                'description'    => 'Number of registration periods per scope (university or faculty) and kind (enroll, drop, both).',
                'description_ar' => 'عدد فترات التسجيل لكل نطاق (جامعة أو كلية) ونوع (تسجيل أو حذف أو الاثنين).',
                'sql_text'       => <<<'SQL'
SELECT scope_type, kind, COUNT(*) AS periods
FROM registration_periods
GROUP BY scope_type, kind
ORDER BY periods DESC
SQL,
            ],
            [
                'category'       => 'registration',
                'name'           => 'Open registration windows now',
                'name_ar'        => 'فترات التسجيل المفتوحة الآن',
                'description'    => 'Registration periods that are open at this moment, closing soonest first.',
                'description_ar' => 'فترات التسجيل المفتوحة حاليًا، الأقرب إغلاقًا أولًا.',
                'sql_text'       => <<<'SQL'
SELECT id, scope_type, scope_id, name, kind, opens_at, closes_at
FROM registration_periods
WHERE opens_at <= CURRENT_TIMESTAMP AND closes_at >= CURRENT_TIMESTAMP
ORDER BY closes_at ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'registration',
                'name'           => 'Registration policy enforcement',
                'name_ar'        => 'تطبيق سياسات التسجيل',
                'description'    => 'How many policies enforce each rule per scope, with the average credit-hour limit (empty = inherited).',
                'description_ar' => 'عدد السياسات التي تُلزم بكل قاعدة لكل نطاق مع متوسط حد الساعات (الفارغ = موروث).',
                'sql_text'       => <<<'SQL'
SELECT scope_type, COUNT(*) AS policies,
       SUM(CASE WHEN enforce_period = 1 THEN 1 ELSE 0 END) AS period_enforced,
       SUM(CASE WHEN enforce_prerequisites = 1 THEN 1 ELSE 0 END) AS prerequisites_enforced,
       SUM(CASE WHEN enforce_hours = 1 THEN 1 ELSE 0 END) AS hours_enforced,
       SUM(CASE WHEN enforce_conflicts = 1 THEN 1 ELSE 0 END) AS conflicts_enforced,
       ROUND(AVG(max_hours), 1) AS avg_max_hours
FROM registration_policies
GROUP BY scope_type
ORDER BY policies DESC
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Fee collection by kind and currency',
                'name_ar'        => 'تحصيل الرسوم حسب النوع والعملة',
                'description'    => 'Dues issued, amount due, amount paid and collection rate per fee kind (waived dues excluded).',
                'description_ar' => 'عدد المستحقات والمبلغ المستحق والمدفوع ونسبة التحصيل لكل نوع رسوم (بدون المعفى).',
                'sql_text'       => <<<'SQL'
SELECT i.kind, i.currency, COUNT(f.id) AS dues,
       ROUND(SUM(f.amount), 2) AS total_due, ROUND(SUM(f.paid_amount), 2) AS total_paid,
       ROUND(100.0 * SUM(f.paid_amount) / NULLIF(SUM(f.amount), 0), 1) AS collection_rate_pct
FROM fee_items i
INNER JOIN student_fees f ON f.fee_item_id = i.id
WHERE f.status != 'waived'
GROUP BY i.kind, i.currency
ORDER BY total_due DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Student fees by status',
                'name_ar'        => 'مستحقات الطلاب حسب الحالة',
                'description'    => 'Count and amounts of student dues in each status (unpaid, partial, paid, waived).',
                'description_ar' => 'عدد ومبالغ مستحقات الطلاب في كل حالة (غير مدفوع أو جزئي أو مدفوع أو معفى).',
                'sql_text'       => <<<'SQL'
SELECT status, COUNT(*) AS dues, ROUND(SUM(amount), 2) AS total_amount, ROUND(SUM(paid_amount), 2) AS total_paid
FROM student_fees
GROUP BY status
ORDER BY dues DESC
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Overdue unpaid fees',
                'name_ar'        => 'الرسوم المتأخرة غير المسددة',
                'description'    => 'Unpaid or partially paid dues whose due date has passed, oldest first.',
                'description_ar' => 'مستحقات غير مدفوعة أو مدفوعة جزئيًا فات موعدها، الأقدم أولًا.',
                'sql_text'       => <<<'SQL'
SELECT f.id, f.student_id, i.name AS fee_item, f.amount, f.paid_amount, f.due_on, f.status
FROM student_fees f
INNER JOIN fee_items i ON i.id = f.fee_item_id
WHERE f.status IN ('unpaid', 'partial') AND f.due_on IS NOT NULL AND f.due_on < CURRENT_DATE
ORDER BY f.due_on ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Highest outstanding balances',
                'name_ar'        => 'أعلى الأرصدة المستحقة',
                'description'    => 'Students with the largest remaining balance across their unpaid or partial dues.',
                'description_ar' => 'الطلاب أصحاب أكبر رصيد متبقٍّ عبر مستحقاتهم غير المدفوعة أو الجزئية.',
                'sql_text'       => <<<'SQL'
SELECT student_id, COUNT(*) AS open_dues, ROUND(SUM(amount - paid_amount), 2) AS outstanding
FROM student_fees
WHERE status IN ('unpaid', 'partial')
GROUP BY student_id
ORDER BY outstanding DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Payments by method and status',
                'name_ar'        => 'الدفعات حسب الطريقة والحالة',
                'description'    => 'Payment count and total amount per method (cash, bank transfer, card, other) and status.',
                'description_ar' => 'عدد الدفعات وإجمالي المبلغ لكل طريقة دفع (نقدي أو تحويل بنكي أو بطاقة أو أخرى) وحالة.',
                'sql_text'       => <<<'SQL'
SELECT method, status, COUNT(*) AS payments, ROUND(SUM(amount), 2) AS total_amount
FROM fee_payments
GROUP BY method, status
ORDER BY payments DESC
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Pending payments awaiting review',
                'name_ar'        => 'دفعات معلّقة بانتظار المراجعة',
                'description'    => 'Payment notices still pending confirmation, oldest first.',
                'description_ar' => 'إخطارات الدفع التي لم تُؤكَّد بعد، الأقدم أولًا.',
                'sql_text'       => <<<'SQL'
SELECT id, student_fee_id, student_id, amount, method, created_at
FROM fee_payments
WHERE status = 'pending'
ORDER BY created_at ASC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'fees',
                'name'           => 'Fee items per university',
                'name_ar'        => 'بنود الرسوم لكل جامعة',
                'description'    => 'Number of fee items and total amount issued per university.',
                'description_ar' => 'عدد بنود الرسوم وإجمالي مبالغها لكل جامعة.',
                'sql_text'       => <<<'SQL'
SELECT u.official_name_en AS university, COUNT(i.id) AS fee_items, ROUND(SUM(i.amount), 2) AS total_amount
FROM universities u
INNER JOIN fee_items i ON i.university_id = u.id
GROUP BY u.id, u.official_name_en
ORDER BY fee_items DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'summer',
                'name'           => 'Summer registrations by status',
                'name_ar'        => 'التسجيل الصيفي حسب الحالة',
                'description'    => 'Summer-course requests in each status (pending, approved, rejected, cancelled).',
                'description_ar' => 'طلبات المواد الصيفية في كل حالة (معلّق أو موافق أو مرفوض أو ملغي).',
                'sql_text'       => <<<'SQL'
SELECT status, COUNT(*) AS requests
FROM summer_registrations
GROUP BY status
ORDER BY requests DESC
SQL,
            ],
            [
                'category'       => 'summer',
                'name'           => 'Summer demand vs capacity per course',
                'name_ar'        => 'الطلب مقابل السعة لكل مادة صيفية',
                'description'    => 'For each offered summer course: seat capacity, requests received and requests approved.',
                'description_ar' => 'لكل مادة صيفية معروضة: السعة وعدد الطلبات المستلمة والمعتمدة.',
                'sql_text'       => <<<'SQL'
SELECT c.code, c.name_en AS course, o.capacity, COUNT(r.id) AS requests,
       SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved
FROM summer_offerings o
INNER JOIN courses c ON c.id = o.course_id
LEFT JOIN summer_registrations r ON r.term_id = o.term_id AND r.course_id = o.course_id
GROUP BY o.id, c.code, c.name_en, o.capacity
ORDER BY requests DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'summer',
                'name'           => 'Summer terms overview',
                'name_ar'        => 'نظرة عامة على الفصول الصيفية',
                'description'    => 'Each summer term with its window, credit-hour limit, offered courses and requests received.',
                'description_ar' => 'كل فصل صيفي مع فترته وحد الساعات والمواد المعروضة والطلبات المستلمة.',
                'sql_text'       => <<<'SQL'
SELECT t.id, t.name, t.opens_at, t.closes_at, t.max_hours, t.only_failed,
       COUNT(DISTINCT o.id) AS offerings, COUNT(DISTINCT r.id) AS requests
FROM summer_terms t
LEFT JOIN summer_offerings o ON o.term_id = t.id
LEFT JOIN summer_registrations r ON r.term_id = t.id
GROUP BY t.id, t.name, t.opens_at, t.closes_at, t.max_hours, t.only_failed
ORDER BY t.opens_at DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'structure',
                'name'           => 'Universities by verification status and country',
                'name_ar'        => 'الجامعات حسب حالة التوثيق والدولة',
                'description'    => 'Count of universities per verification status and country.',
                'description_ar' => 'عدد الجامعات لكل حالة توثيق ودولة.',
                'sql_text'       => <<<'SQL'
SELECT verification_status, country, COUNT(*) AS universities
FROM universities
GROUP BY verification_status, country
ORDER BY universities DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'structure',
                'name'           => 'Faculties per university',
                'name_ar'        => 'الكليات لكل جامعة',
                'description'    => 'Number of faculties in each university.',
                'description_ar' => 'عدد الكليات في كل جامعة.',
                'sql_text'       => <<<'SQL'
SELECT u.id, u.official_name_en AS university, COUNT(f.id) AS faculties
FROM universities u
LEFT JOIN faculties f ON f.university_id = u.id
GROUP BY u.id, u.official_name_en
ORDER BY faculties DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'structure',
                'name'           => 'Departments per faculty',
                'name_ar'        => 'الأقسام لكل كلية',
                'description'    => 'Number of departments in each faculty.',
                'description_ar' => 'عدد الأقسام في كل كلية.',
                'sql_text'       => <<<'SQL'
SELECT f.id, f.name_en AS faculty, COUNT(d.id) AS departments
FROM faculties f
LEFT JOIN departments d ON d.faculty_id = f.id
GROUP BY f.id, f.name_en
ORDER BY departments DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'structure',
                'name'           => 'Students and average GPA per faculty',
                'name_ar'        => 'الطلاب ومتوسط المعدل لكل كلية',
                'description'    => 'Student count and average GPA in each faculty.',
                'description_ar' => 'عدد الطلاب ومتوسط المعدل في كل كلية.',
                'sql_text'       => <<<'SQL'
SELECT f.id, f.name_en AS faculty, COUNT(s.id) AS students, ROUND(AVG(s.gpa), 2) AS avg_gpa
FROM faculties f
LEFT JOIN students s ON s.faculty_id = f.id
GROUP BY f.id, f.name_en
ORDER BY students DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'structure',
                'name'           => 'Student group sizes',
                'name_ar'        => 'أحجام المجموعات الطلابية',
                'description'    => 'Members per student group against the group member limit.',
                'description_ar' => 'عدد الأعضاء في كل مجموعة طلابية مقارنة بحد الأعضاء.',
                'sql_text'       => <<<'SQL'
SELECT g.id, g.name, g.max_members, COUNT(s.id) AS members
FROM student_groups g
LEFT JOIN students s ON s.group_id = g.id
GROUP BY g.id, g.name, g.max_members
ORDER BY members DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'graduation',
                'name'           => 'Graduates per university',
                'name_ar'        => 'الخريجون لكل جامعة',
                'description'    => 'Graduate count and average final GPA per university (revoked records excluded).',
                'description_ar' => 'عدد الخريجين ومتوسط المعدل النهائي لكل جامعة (بدون السجلات الملغاة).',
                'sql_text'       => <<<'SQL'
SELECT u.official_name_en AS university, COUNT(g.id) AS graduates, ROUND(AVG(g.final_gpa), 2) AS avg_final_gpa
FROM graduation_records g
INNER JOIN universities u ON u.id = g.university_id
WHERE g.status = 'graduated'
GROUP BY u.id, u.official_name_en
ORDER BY graduates DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'graduation',
                'name'           => 'Graduations by month',
                'name_ar'        => 'التخرج حسب الشهر',
                'description'    => 'Graduation records per month and status (graduated or revoked).',
                'description_ar' => 'سجلات التخرج لكل شهر وحالة (متخرج أو ملغى).',
                'sql_text'       => <<<'SQL'
SELECT SUBSTR(graduation_date, 1, 7) AS grad_month, status, COUNT(*) AS records
FROM graduation_records
GROUP BY SUBSTR(graduation_date, 1, 7), status
ORDER BY grad_month DESC
LIMIT 50
SQL,
            ],
            [
                'category'       => 'graduation',
                'name'           => 'Final GPA distribution',
                'name_ar'        => 'توزيع المعدل النهائي',
                'description'    => 'Graduates bucketed by final GPA on the 4.0 scale.',
                'description_ar' => 'الخريجون موزعون على شرائح المعدل النهائي (مقياس 4.0).',
                'sql_text'       => <<<'SQL'
SELECT CASE WHEN final_gpa >= 3.5 THEN '3.5 and above'
            WHEN final_gpa >= 3.0 THEN '3.0-3.49'
            WHEN final_gpa >= 2.0 THEN '2.0-2.99'
            ELSE 'below 2.0' END AS gpa_band,
       COUNT(*) AS graduates
FROM graduation_records
WHERE status = 'graduated' AND final_gpa IS NOT NULL
GROUP BY CASE WHEN final_gpa >= 3.5 THEN '3.5 and above'
              WHEN final_gpa >= 3.0 THEN '3.0-3.49'
              WHEN final_gpa >= 2.0 THEN '2.0-2.99'
              ELSE 'below 2.0' END
ORDER BY graduates DESC
SQL,
            ],
        ];
    }

    /** كام قالب جاهز للتشغيل فوق الكتالوج الحي، لمتطلب "Query Templates". */
    public function templates(): array
    {
        $catalog = $this->repo->catalog();
        // القوالب المخصصة (امتحانات/دكاترة/طلاب) الأول، وبعدها قوالب المعاينة العامة.
        $templates = $this->curatedTemplates();
        $generic = [];
        foreach ($catalog as $entry) {
            $cols = array_slice($entry['columns'], 0, 5);
            if (!$cols) {
                continue;
            }
            $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
            $generic[] = [
                'name'        => 'Preview: ' . ($entry['label']['en'] ?? $entry['table']),
                'name_ar'     => 'معاينة: ' . ($entry['label']['ar'] ?? $entry['label']['en'] ?? $entry['table']),
                'description' => 'First 50 rows of ' . $entry['table'] . '.',
                'description_ar' => 'أول 50 صفًا من ' . $entry['table'] . '.',
                'sql_text'    => "SELECT {$colList}\nFROM `{$entry['table']}`\nLIMIT 50",
            ];
            if (isset($entry['default_sort'])) {
                $generic[] = [
                    'name'        => 'Latest: ' . ($entry['label']['en'] ?? $entry['table']),
                    'name_ar'     => 'الأحدث: ' . ($entry['label']['ar'] ?? $entry['label']['en'] ?? $entry['table']),
                    'description' => 'Most recent rows by ' . $entry['default_sort'] . '.',
                    'description_ar' => 'أحدث الصفوف حسب ' . $entry['default_sort'] . '.',
                    'sql_text'    => "SELECT {$colList}\nFROM `{$entry['table']}`\nORDER BY `{$entry['default_sort']}` DESC\nLIMIT 50",
                ];
            }
        }
        return array_merge($templates, $generic);
    }

    /**
     * بتشغّل طلب في وضع الـ builder. بترجع
     * ['ok'=>true,'sql','rows','columns','row_count','execution_time_ms']
     * أو ['ok'=>false,'sql','error'] — عمرها ما بترمي؛ الكولر بيقرر الـ
     * HTTP status.
     */
    public function runBuilder(int $userId, array $spec, ?int $savedQueryId = null): array
    {
        try {
            ['sql' => $sql, 'params' => $params] = $this->repo->buildSql($spec);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, json_encode($spec), 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => null, 'error' => $e->getMessage()];
        }

        return $this->runValidated($userId, $sql, $params, $savedQueryId);
    }

    /** بتشغّل نص SQL خام مكتوب يدويًا. نفس شكل رجوع runBuilder(). */
    public function runRaw(int $userId, string $sqlText, ?int $savedQueryId = null): array
    {
        try {
            $safeSql = $this->repo->validateRawSql($sqlText);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, $sqlText, 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => $sqlText, 'error' => $e->getMessage()];
        }

        return $this->runValidated($userId, $safeSql, [], $savedQueryId);
    }

    private function runValidated(int $userId, string $sql, array $params, ?int $savedQueryId): array
    {
        try {
            $result = $this->repo->execute($sql, $params);
        } catch (RuntimeException $e) {
            $this->repo->logHistory($userId, $sql, 'error', $e->getMessage(), null, null, $savedQueryId);
            return ['ok' => false, 'sql' => $sql, 'error' => $e->getMessage()];
        }

        $this->repo->logHistory($userId, $sql, 'success', null, $result['row_count'], $result['execution_time_ms'], $savedQueryId);

        return [
            'ok'                => true,
            'sql'               => $sql,
            'rows'              => $result['rows'],
            'columns'           => $result['columns'],
            'row_count'         => $result['row_count'],
            'execution_time_ms' => $result['execution_time_ms'],
        ];
    }

    public function saveQuery(int $userId, string $name, string $sqlText, ?string $description, ?array $builderState, ?string $datasetKey, bool $isShared)
    {
        $name = trim($name) !== '' ? trim($name) : 'Untitled query';
        return $this->repo->createSavedQuery($userId, $name, $sqlText, $description, $builderState, $datasetKey, $isShared);
    }

    public function savedQueries(int $userId): array
    {
        return $this->repo->savedQueriesFor($userId);
    }

    /** @return bool true لو اتحذف، false لو مش موجود أو مش ملك المستخدم ده */
    public function deleteSavedQuery(int $userId, $id): bool
    {
        $query = $this->repo->findSavedQuery($id);
        if (!$query || (string) $query->user_id !== (string) $userId) {
            return false;
        }
        return $this->repo->deleteSavedQuery($query);
    }

    public function findSavedQuery($id)
    {
        return $this->repo->findSavedQuery($id);
    }

    public function history(int $userId, int $limit = 30): array
    {
        return $this->repo->historyFor($userId, $limit);
    }
}
