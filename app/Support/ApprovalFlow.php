<?php

namespace App\Support;

/**
 * قواعد مسار الاعتماد (دالّة نقية، من غير DB): الترتيب الإجباري، والمرحلة المنتظرة، وهل المرحلة دي دورها.
 */
final class ApprovalFlow
{
    public const SUPERVISOR = 'supervisor_review';
    public const FACULTY = 'faculty_review';
    public const UNIVERSITY = 'university_review';
    public const ADMIN = 'admin_review';

    /** أدوار ربط الدكتور بالمشروع اللي ليها حق قرار مرحلة المشرف (teaching_assistant استشاري: لا). */
    public const OFFICIAL_ROLES = ['supervisor', 'professor', 'principal_investigator'];

    /** الترتيب الإجباري. */
    public const ORDER = [self::SUPERVISOR, self::FACULTY, self::UNIVERSITY];

    /**
     * @param string[] $required المراحل المطلوبة لهذا المشروع (بعد التخطي)
     * @param string[] $approved المراحل اللي اتعمدت في الدورة الحالية
     */
    public static function awaiting(array $required, array $approved): ?string
    {
        foreach (self::ORDER as $stage) {
            if (in_array($stage, $required, true) && !in_array($stage, $approved, true)) {
                return $stage;
            }
        }
        return null;
    }

    public static function isFinal(string $stage, array $required): bool
    {
        $last = null;
        foreach (self::ORDER as $s) {
            if (in_array($s, $required, true)) {
                $last = $s;
            }
        }
        return $last === $stage;
    }
}
