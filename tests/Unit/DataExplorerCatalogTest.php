<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * حماية كتالوج Data Explorer (config/data_explorer_datasets.php) من الأخطاء
 * البنيوية: مفاتيح ناقصة، جداول مكررة، علاقات بتشاور على dataset مش موجود،
 * وأعمدة حساسة المفروض تفضل مخفية (أسرار الحضور، الإحداثيات، بصمات الأجهزة،
 * مسارات الملفات، كلمات سر الفيديو، مراجع التحويل البنكي، ملاحظات الإعفاء،
 * أرقام شهادات التخرج، إحداثيات الحرم). بدون DB — بيقرا الـ config مباشرة.
 */
class DataExplorerCatalogTest extends TestCase
{
    private function catalog(): array
    {
        return require dirname(__DIR__, 2) . '/config/data_explorer_datasets.php';
    }

    public function test_every_entry_has_the_required_shape(): void
    {
        foreach ($this->catalog() as $key => $e) {
            $this->assertNotEmpty($e['table'] ?? null, "$key: table");
            $this->assertArrayHasKey('en', $e['label'] ?? [], "$key: label.en");
            $this->assertArrayHasKey('ar', $e['label'] ?? [], "$key: label.ar");
            $this->assertArrayHasKey('en', $e['description'] ?? [], "$key: description.en");
            $this->assertArrayHasKey('ar', $e['description'] ?? [], "$key: description.ar");
            $this->assertNotEmpty($e['icon'] ?? null, "$key: icon");
            $this->assertNotEmpty($e['primary_key'] ?? null, "$key: primary_key");
            $this->assertNotEmpty($e['default_sort'] ?? null, "$key: default_sort");
            $this->assertContains($e['default_sort_dir'] ?? null, ['asc', 'desc'], "$key: default_sort_dir");
            $this->assertIsArray($e['sensitive_columns'] ?? null, "$key: sensitive_columns");
            $this->assertIsArray($e['relationships'] ?? null, "$key: relationships");
        }
    }

    public function test_tables_are_unique_and_valid_identifiers(): void
    {
        $tables = array_column($this->catalog(), 'table');
        $this->assertSame(count($tables), count(array_unique($tables)), 'duplicate table in catalog');
        foreach ($tables as $t) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $t);
        }
    }

    public function test_relationships_point_at_catalog_tables(): void
    {
        $catalog = $this->catalog();
        $tables = array_column($catalog, 'table');
        foreach ($catalog as $key => $e) {
            foreach ($e['relationships'] as $r) {
                foreach (['column', 'references', 'references_column', 'label'] as $field) {
                    $this->assertArrayHasKey($field, $r, "$key: relationship missing $field");
                }
                $this->assertContains($r['references'], $tables, "$key -> {$r['references']} is not a catalog dataset");
            }
        }
    }

    public function test_course_domain_datasets_are_present(): void
    {
        $tables = array_column($this->catalog(), 'table');
        foreach ([
            'course_enrollments', 'course_staff', 'course_sessions', 'attendance_windows', 'attendance_records',
            'course_assignments', 'assignment_submissions', 'course_final_grades', 'course_grade_components',
            'course_grade_component_scores', 'course_grade_items', 'grade_scales', 'grade_scale_bands',
            'course_materials', 'course_material_saves', 'course_videos', 'course_video_views',
        ] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function test_sensitive_columns_stay_hidden(): void
    {
        $c = $this->catalog();
        $this->assertContains('secret', $c['attendance_windows']['sensitive_columns']);
        foreach (['lat', 'lng'] as $col) {
            $this->assertContains($col, $c['attendance_windows']['sensitive_columns']);
            $this->assertContains($col, $c['attendance_records']['sensitive_columns']);
        }
        $this->assertContains('device_hash', $c['attendance_records']['sensitive_columns']);
        foreach (['course_assignments', 'assignment_submissions', 'course_materials', 'course_videos'] as $k) {
            $this->assertContains('file_path', $c[$k]['sensitive_columns'], "$k: file_path must stay hidden");
        }
        $this->assertContains('password_hash', $c['course_videos']['sensitive_columns']);
    }

    public function test_registration_fees_and_summer_datasets_are_present(): void
    {
        $tables = array_column($this->catalog(), 'table');
        foreach ([
            'registration_periods', 'registration_policies',
            'fee_items', 'student_fees', 'fee_payments',
            'summer_terms', 'summer_offerings', 'summer_registrations',
        ] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function test_academic_structure_and_graduation_datasets_are_present(): void
    {
        $tables = array_column($this->catalog(), 'table');
        foreach (['universities', 'faculties', 'departments', 'student_groups', 'graduation_records'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function test_fee_structure_and_graduation_sensitive_columns_stay_hidden(): void
    {
        $c = $this->catalog();
        $this->assertContains('waive_note', $c['student_fees']['sensitive_columns']);
        $this->assertContains('reference', $c['fee_payments']['sensitive_columns']);
        $this->assertContains('certificate_number', $c['graduation_records']['sensitive_columns']);
        foreach (['logo_path', 'signature_path', 'stamp_path', 'dean_signature_path'] as $col) {
            $this->assertContains($col, $c['universities']['sensitive_columns'], "universities: $col must stay hidden");
        }
        foreach (['logo_path', 'cover_path', 'contact_email', 'contact_phone', 'campus_lat', 'campus_lng'] as $col) {
            $this->assertContains($col, $c['faculties']['sensitive_columns'], "faculties: $col must stay hidden");
        }
    }

    public function test_soft_delete_flag_matches_the_real_schema(): void
    {
        // universities وdepartments عندهم deleted_at (migrations 2026_10_30 و2026_11_08)؛ الباقي لأ —
        // تفعيل soft_deletes على جدول مالوش deleted_at هيكسر المعاينة والعدّادات والـ Query Builder.
        $c = $this->catalog();
        $this->assertTrue($c['universities']['soft_deletes'] ?? false);
        $this->assertTrue($c['departments']['soft_deletes'] ?? false);
        foreach (['faculties', 'student_groups', 'graduation_records', 'registration_periods', 'registration_policies',
                  'fee_items', 'student_fees', 'fee_payments', 'summer_terms', 'summer_offerings', 'summer_registrations'] as $k) {
            $this->assertEmpty($c[$k]['soft_deletes'] ?? false, "$k has no deleted_at column");
        }
    }

    public function test_sensitive_column_names_are_unique_enough_for_the_raw_sql_guard(): void
    {
        // الـ Query Builder بيرفض أي SQL خام بيذكر اسم عمود حساس من أي dataset، فأي اسم حساس عام زيادة
        // (id/name/status/...) كان هيقفل الاستعلامات كلها. ده حارس ضد إضافة اسم عام بالغلط.
        $generic = ['id', 'name', 'status', 'type', 'kind', 'title', 'created_at', 'updated_at', 'user_id', 'student_id', 'amount'];
        foreach ($this->catalog() as $key => $e) {
            foreach ($e['sensitive_columns'] as $col) {
                $this->assertNotContains($col, $generic, "$key: sensitive column '$col' is too generic");
            }
        }
    }
}
