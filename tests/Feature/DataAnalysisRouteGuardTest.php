<?php

namespace Tests\Feature;

use App\Repositories\DataSegmentRepository;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * حراسة بوابة Data Analysis على مستوى الـ routes + اتساق الـ constants مع الـ
 * migrations. من غير DB: بيقرا جدول الـ routes المسجّل وملفات الـ migrations.
 */
class DataAnalysisRouteGuardTest extends TestCase
{
    /** @return array<int,\Illuminate\Routing\Route> */
    private function portalRoutes(): array
    {
        return array_values(array_filter(
            iterator_to_array(Route::getRoutes()),
            fn ($r) => str_starts_with($r->uri(), 'api/v1/data-analysis/')
        ));
    }

    #[Test]
    public function every_data_analysis_route_requires_login_and_a_permission(): void
    {
        $routes = $this->portalRoutes();
        $this->assertNotEmpty($routes, 'no data-analysis routes registered');

        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            $label = implode('|', $r->methods()) . ' ' . $r->uri();
            $this->assertContains('uip.auth', $mw, "$label has no uip.auth");
            $this->assertNotEmpty(
                array_filter($mw, fn ($m) => is_string($m) && str_starts_with($m, 'uip.can:')),
                "$label has no uip.can: permission"
            );
        }
    }

    #[Test]
    public function segments_and_forecasting_use_their_own_permissions(): void
    {
        $expected = [
            'api/v1/data-analysis/segments'    => 'uip.can:data_analysis.segments.manage',
            'api/v1/data-analysis/forecasting' => 'uip.can:data_analysis.trends.view',
        ];
        foreach ($expected as $uri => $permission) {
            $route = collect($this->portalRoutes())->first(fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true));
            $this->assertNotNull($route, "GET $uri not registered");
            $this->assertContains($permission, $route->gatherMiddleware(), $uri);
        }
    }

    #[Test]
    public function forecasting_and_search_endpoints_are_registered(): void
    {
        $have = collect($this->portalRoutes())->map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri())->all();

        foreach ([
            'GET|HEAD api/v1/data-analysis/forecasting',
            'GET|HEAD api/v1/data-analysis/forecasting/{key}',
            'POST api/v1/data-analysis/forecasting/{key}/explain',
            'GET|HEAD api/v1/data-analysis/search',
            'GET|HEAD api/v1/data-analysis/search/quick',
            'POST api/v1/data-analysis/segments',
            'DELETE api/v1/data-analysis/segments/{id}',
        ] as $needle) {
            $this->assertContains($needle, $have);
        }
    }

    #[Test]
    public function segment_option_lists_match_the_real_schema_enums(): void
    {
        $migrations = dirname(__DIR__, 2) . '/database/migrations/';
        $exams = file_get_contents($migrations . '2026_08_28_150000_create_exam_system_foundation_tables.php');
        $students = file_get_contents($migrations . '2026_01_01_000005_create_students_table.php');

        foreach (DataSegmentRepository::EXAM_STATUS_VALUES as $v) {
            $this->assertStringContainsString("'$v'", $exams, "exam status '$v' not in exams migration");
        }
        foreach (DataSegmentRepository::EXAM_RESULT_VISIBILITY_VALUES as $v) {
            $this->assertStringContainsString("'$v'", $exams, "result_visibility '$v' not in exams migration");
        }
        foreach (DataSegmentRepository::STUDENT_INVITATION_VALUES as $v) {
            $this->assertStringContainsString("'$v'", $students, "invitation_status '$v' not in students migration");
        }
    }

    #[Test]
    public function every_segment_entity_is_storable_after_the_entity_column_migration(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2028_01_20_100000_extend_data_segments_entities.php');

        $this->assertStringContainsString("string('entity'", $migration, 'entity must become a string column, not an enum');
        $this->assertSame(['projects', 'users', 'students', 'exams', 'courses'], DataSegmentRepository::ENTITIES);
        // كل كيان جديد لازم له criteria options، وكل مفتاح فيها بقيم مش فاضية.
        foreach (['students', 'exams', 'courses'] as $entity) {
            foreach (DataSegmentRepository::criteriaOptions()[$entity] as $key => $values) {
                $this->assertNotEmpty($values, "$entity.$key options empty");
            }
        }
    }
}
