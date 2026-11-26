<?php

namespace App\Services\Export;

/**
 * يترجم عناوين التقارير وأسماء الأعمدة للعربي لما العميل يطلب lang=ar
 * (الفرونت بيبعتها من قائمة التصدير). بيترجم الـ labels بس — القيم
 * نفسها (أسماء الامتحانات والطلاب والجامعات) بتفضل زي ما هي في الداتا.
 * أي نص مالوش ترجمة بيرجع زي ما هو، فمفيش تصدير ممكن يفشل بسببه.
 */
final class ExportLocalizer
{
    private const AR = [
        // عناوين التقارير
        'Platform KPIs' => 'مؤشرات أداء المنصة',
        'User Growth (12 months)' => 'نمو المستخدمين (١٢ شهرًا)',
        'Category Distribution' => 'توزيع الفئات',
        'University Leaderboard' => 'ترتيب الجامعات',
        'Everything (Projects + Universities)' => 'كل البيانات (المشاريع + الجامعات)',
        'Exam Overview (KPIs)' => 'نظرة عامة على الامتحانات (المؤشرات)',
        'Exam Scores (every graded attempt)' => 'درجات الامتحانات (كل محاولة مصححة)',
        'Student Exam Performance' => 'أداء الطلاب في الامتحانات',
        'Exams Summary (per exam)' => 'ملخص كل امتحان',
        'Doctors Exam Performance' => 'أداء الدكاترة في الامتحانات',
        // أعمدة
        'metric' => 'المؤشر', 'value' => 'القيمة', 'month' => 'الشهر', 'new_users' => 'مستخدمون جدد',
        'category' => 'الفئة', 'projects' => 'المشاريع', 'university' => 'الجامعة',
        'Type' => 'النوع', 'Name' => 'الاسم', 'Category / Country' => 'الفئة / الدولة',
        'Owner / Stats' => 'المالك / الإحصائيات', 'Status' => 'الحالة', 'Created At' => 'تاريخ الإنشاء',
        'Exam' => 'الامتحان', 'Exam Type' => 'نوع الامتحان', 'University' => 'الجامعة', 'Faculty' => 'الكلية',
        'Course' => 'المقرر', 'Student' => 'الطالب', 'Student Number' => 'الرقم الجامعي',
        'Email' => 'البريد الإلكتروني', 'Percentage' => 'النسبة %', 'Result' => 'النتيجة', 'Late' => 'متأخر',
        'Violations' => 'المخالفات', 'Submitted At' => 'وقت التسليم', 'Exams Taken' => 'الامتحانات المؤداة',
        'Average %' => 'المتوسط %', 'Best %' => 'الأعلى %', 'Lowest %' => 'الأدنى %', 'Highest %' => 'الأعلى %',
        'Pass Rate %' => 'نسبة النجاح %', 'Trend (pts)' => 'الاتجاه (نقاط)', 'Flagged Attempts' => 'محاولات مُبلَّغ عنها',
        'Level' => 'المستوى', 'Exam ID' => 'رقم الامتحان', 'Graded Attempts' => 'المحاولات المصححة',
        'Students' => 'الطلاب', 'Last Submission' => 'آخر تسليم', 'Doctor' => 'الدكتور',
        'Exams Created' => 'الامتحانات المنشأة', 'Exams With Graded Attempts' => 'امتحانات بها محاولات مصححة',
        // عناوين تقارير النتائج و AI Code Review
        'Students Results' => 'نتائج الطلاب', 'Exam Results' => 'نتائج الامتحان',
        'Score Comparison' => 'مقارنة الدرجات', 'Scores' => 'الدرجات', 'Average Scores' => 'متوسط الدرجات',
        'Issues by Severity' => 'المشكلات حسب الخطورة', 'Administrator Decision' => 'قرار المسؤول',
        'Historical Comparison (All Versions)' => 'المقارنة التاريخية (كل النسخ)', 'Historical Comparison (Completed Versions)' => 'المقارنة التاريخية (النسخ المكتملة)', 'Findings' => 'الملاحظات',
        'Version A Findings' => 'ملاحظات النسخة A', 'Version B Findings' => 'ملاحظات النسخة B',
        'AI Code Review' => 'مراجعة الكود بالذكاء الاصطناعي', 'Project Report' => 'تقرير المشروع',
        'University Report' => 'تقرير الجامعة', 'Faculty Report' => 'تقرير الكلية', 'Version Comparison' => 'مقارنة النسخ',
        // meta
        'Generated At' => 'تاريخ الإنشاء', 'Exams' => 'الامتحانات', 'Total Attempts' => 'إجمالي المحاولات',
        'Graded' => 'المصحّحة', 'Average Score' => 'متوسط الدرجات', 'Project ID' => 'رقم المشروع',
        'Latest Version' => 'آخر نسخة', 'Reviewed Projects' => 'المشاريع المراجَعة',
        'Completed / Failed' => 'المكتملة / الفاشلة', 'Total Issues' => 'إجمالي المشكلات', 'Version A' => 'النسخة A',
        'Version B' => 'النسخة B',
        // أعمدة
        'Attempts' => 'المحاولات', 'Best Score' => 'أعلى درجة', 'Max Marks' => 'الدرجة العظمى', 'Attempt #' => 'رقم المحاولة',
        'Score' => 'الدرجة', 'Submission Time' => 'وقت التسليم', 'Metric' => 'المؤشر', 'Average' => 'المتوسط',
        'Change' => 'التغيير', 'Severity' => 'الخطورة', 'Count' => 'العدد', 'File' => 'الملف', 'Line' => 'السطر',
        'Description' => 'الوصف', 'Recommendation' => 'التوصية', 'Field' => 'الحقل', 'Version' => 'النسخة',
        'Overall Score' => 'الدرجة الإجمالية', 'Issues' => 'المشكلات', 'Date' => 'التاريخ',
        // صفوف وقيم ثابتة
        'Overall' => 'الإجمالي', 'Security' => 'الأمان', 'Performance' => 'الأداء', 'Maintainability' => 'سهولة الصيانة',
        'Architecture' => 'البنية', 'Quality' => 'الجودة', 'Reviewed By (User ID)' => 'تمت المراجعة بواسطة (رقم المستخدم)',
        'Approved At' => 'تاريخ الاعتماد', 'Not yet approved' => 'لم يُعتمد بعد', 'Score Overridden' => 'تم تعديل الدرجة',
        'Override Reason' => 'سبب التعديل', 'Admin Notes' => 'ملاحظات المسؤول', 'Yes' => 'نعم', 'No' => 'لا',
        'Critical' => 'حرجة', 'High' => 'عالية', 'Medium' => 'متوسطة', 'Low' => 'منخفضة', 'Info' => 'معلومة',
        // تقرير مراجعة الكود للمشروع (CodeReviewReportService)
        'Code Review Report' => 'تقرير مراجعة الكود', 'Summary' => 'الملخص', 'Project' => 'المشروع',
        'Department' => 'القسم',
        'Doctor / Supervisor' => 'الدكتور / المشرف', 'Teaching Assistant' => 'المعيد', 'Repository' => 'المستودع',
        'Analysis Method' => 'طريقة التحليل', 'Review Version' => 'نسخة المراجعة', 'Review Date' => 'تاريخ المراجعة',
        'Suggested Fix' => 'الحل المقترح', 'Language Mix' => 'توزيع اللغات', 'Language' => 'اللغة', 'Share %' => 'النسبة %',
        'Files Analyzed' => 'الملفات التي تم تحليلها',
        // تقارير المحلل (Reports) — العناوين والأعمدة والصفوف الثابتة
        'Platform Analytics Export' => 'تصدير تحليلات المنصة', 'Platform Usage Summary' => 'ملخص استخدام المنصة',
        'Platform Growth Report' => 'تقرير نمو المنصة', 'University Onboarding Digest' => 'ملخص انضمام الجامعات',
        'Audit Log Export' => 'تصدير سجل التدقيق', 'Security Incident Summary' => 'ملخص الحوادث الأمنية',
        'Vulnerability Summary' => 'ملخص الثغرات', 'University Student Roster' => 'قائمة طلاب الجامعة',
        'University Project Log' => 'سجل مشاريع الجامعة',
        'Value' => 'القيمة', 'Month' => 'الشهر', 'New Users' => 'مستخدمون جدد', 'Time' => 'الوقت', 'Actor' => 'المنفّذ',
        'Action' => 'الإجراء', 'Target' => 'الهدف', 'Title' => 'العنوان', 'Reference' => 'المرجع', 'Component' => 'المكوّن',
        'Joined' => 'تاريخ الانضمام', 'City' => 'المدينة', 'Country' => 'الدولة', 'Projects' => 'المشاريع',
        'Published' => 'المنشورة', 'Year' => 'السنة', 'Account Status' => 'حالة الحساب', 'Category' => 'الفئة',
        'Submitted' => 'تاريخ التقديم', 'Assigned To' => 'المسؤول', 'Detected' => 'تاريخ الاكتشاف',
        'Resolved' => 'تاريخ الحل', 'CVSS' => 'CVSS', 'Discovered' => 'تاريخ الرصد', 'Student No.' => 'الرقم الجامعي',
        'Total Users' => 'إجمالي المستخدمين', 'Suspended Users' => 'المستخدمون الموقوفون',
        'Total Universities' => 'إجمالي الجامعات', 'Verified Universities' => 'الجامعات الموثّقة',
        'Pending Universities' => 'الجامعات قيد المراجعة', 'Published Projects' => 'المشاريع المنشورة',
        'Pending Approvals' => 'طلبات قيد الموافقة', 'Rejected Projects' => 'المشاريع المرفوضة',
        // بند 2 — تصديرات أقسام المشاريع والامتحانات العميقة والتفاعل
        'Group' => 'المجموعة', 'Item' => 'العنصر',
        'Project Approvals' => 'اعتمادات المشاريع', 'Project Grading' => 'تقييم المشاريع', 'Project Teams' => 'فرق المشاريع',
        'Project Deadlines' => 'مواعيد المشاريع', 'Project Supervisors' => 'مشرفو المشاريع', 'Project Readiness' => 'جاهزية المشاريع',
        'Project IP & Ownership' => 'الملكية الفكرية للمشاريع',
        'Exam Targeting' => 'استهداف الامتحانات', 'Exam Grading (deep)' => 'تصحيح الامتحانات (تفصيلي)',
        'Exam AI Grading' => 'التصحيح بالذكاء الاصطناعي', 'Exam Proctoring' => 'مراقبة الامتحانات',
        'Question Pools & Banks' => 'مجموعات وبنوك الأسئلة', 'Exam Grade Overrides' => 'تعديلات درجات الامتحانات',
        'Engagement — Feed' => 'التفاعل — الفيد', 'Engagement — Announcements' => 'التفاعل — الإعلانات',
        'Engagement — Notifications' => 'التفاعل — الإشعارات', 'Engagement — User Activity' => 'التفاعل — نشاط المستخدمين',
        'Engagement — Logins' => 'التفاعل — تسجيلات الدخول', 'Engagement — Visits' => 'التفاعل — الزيارات',
        'Engagement — Meetings' => 'التفاعل — الاجتماعات', 'Engagement — Calls' => 'التفاعل — المكالمات',
        'Engagement — Forms' => 'التفاعل — الفورمات', 'Engagement — AI Usage' => 'التفاعل — استخدام الذكاء الاصطناعي',
        'KPIs' => 'المؤشرات',
        'Users - ' => 'المستخدمون - ', 'Projects - ' => 'المشاريع - ', 'Overview - ' => 'نظرة عامة - ',
    ];

    public static function isArabic(): bool
    {
        return app()->getLocale() === 'ar';
    }

    public static function text(string $en): string
    {
        return self::isArabic() ? (self::AR[$en] ?? $en) : $en;
    }

    /** @param array<int|string,mixed> $header */
    public static function headers(array $header): array
    {
        return self::isArabic()
            ? array_map(fn ($h) => is_string($h) ? (self::AR[$h] ?? $h) : $h, $header)
            : $header;
    }

    /** يترجم عنوان فيه بادئة زي "AI Code Review - University Report: X" ويسيب الاسم الحر زي ما هو. */
    public static function title(string $title): string
    {
        if (!self::isArabic()) {
            return $title;
        }
        if (isset(self::AR[$title])) {
            return self::AR[$title];
        }
        $parts = explode(' - ', $title, 2);
        if (count($parts) === 2) {
            $tail = $parts[1];
            if (str_contains($tail, ': ')) {
                [$label, $name] = explode(': ', $tail, 2);
                $tail = self::text($label) . ': ' . $name;
            } else {
                $tail = self::text($tail);
            }
            return self::text($parts[0]) . ' - ' . $tail;
        }
        return $title;
    }

    /** يترجم عناوين الأقسام وأسماء الأعمدة ومفاتيح الـ meta في تقرير كامل (القيم نفسها بتفضل زي ما هي). */
    public static function report(string $title, array $meta, array $sections): array
    {
        if (!self::isArabic()) {
            return [$title, $meta, $sections];
        }
        $m = [];
        foreach ($meta as $k => $v) {
            $m[self::text((string) $k)] = $v;
        }
        $sections = array_map(function ($s) {
            $s['title'] = self::text((string) ($s['title'] ?? ''));
            $s['header'] = self::headers((array) ($s['header'] ?? []));
            return $s;
        }, $sections);

        return [self::title($title), $m, $sections];
    }

    public static function recordsLabel(int $n): string
    {
        if (!self::isArabic()) {
            return number_format($n) . ($n === 1 ? ' record' : ' records');
        }
        return number_format($n) . ($n === 1 ? ' سجل' : ' سجلات');
    }
}
