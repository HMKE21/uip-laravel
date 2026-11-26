<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DataAnalysisSearchApiController;
use App\Repositories\ProjectRepository;
use App\Services\DataAnalysisAcademicService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بوابة Data Analysis — البحث: صفحة النتائج الكاملة بتبحث في المشاريع
 * والامتحانات والطلاب والدكاترة والمواد (مش المشاريع بس)، والـ quick palette
 * بتتجاهل أقل من حرفين. الـ repository/service متعمللهم mock.
 */
class DataAnalysisSearchControllerTest extends TestCase
{
    private function controller(ProjectRepository $projects, DataAnalysisAcademicService $academic): DataAnalysisSearchApiController
    {
        return new DataAnalysisSearchApiController($projects, $academic);
    }

    private function req(string $q, string $role = 'data_analyst'): Request
    {
        $r = Request::create('/api/v1/data-analysis/search', 'GET', ['q' => $q]);
        $r->attributes->set('uip_role', $role);
        return $r;
    }

    private function academicResult(): array
    {
        return [
            'exams'    => [['id' => 1, 'title' => 'Physics Midterm', 'subject' => 'Physics', 'exam_type' => 'midterm', 'status' => 'graded', 'start_at' => null]],
            'students' => [['id' => 2, 'full_name' => 'Physics Fan', 'student_number' => 'S-100', 'academic_year' => 2]],
            'doctors'  => [['id' => 3, 'full_name' => 'Dr Physics', 'staff_number' => 'T-9', 'status' => 'active']],
            'courses'  => [['id' => 4, 'code' => 'PHY101', 'name_en' => 'Physics 1', 'name_ar' => 'فيزياء 1', 'status' => 'active']],
        ];
    }

    #[Test]
    public function full_search_covers_projects_and_all_four_academic_groups(): void
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('searchAll')->with('physics')->willReturn([['id' => 9, 'title_en' => 'Physics Lab']]);
        $academic = $this->createMock(DataAnalysisAcademicService::class);
        $academic->method('search')->with('physics', 10)->willReturn($this->academicResult());

        $res = $this->controller($projects, $academic)->index($this->req('  physics '));
        $data = $res->getData(true)['data'];

        $this->assertSame(200, $res->status());
        $this->assertSame('physics', $data['query']);
        foreach (['projects', 'exams', 'students', 'doctors', 'courses'] as $group) {
            $this->assertNotEmpty($data[$group], "$group should have results");
        }
    }

    #[Test]
    public function empty_query_returns_empty_groups_without_hitting_the_data_layer(): void
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->expects($this->never())->method('searchAll');
        $academic = $this->createMock(DataAnalysisAcademicService::class);
        $academic->expects($this->never())->method('search');

        $data = $this->controller($projects, $academic)->index($this->req('   '))->getData(true)['data'];

        foreach (['projects', 'exams', 'students', 'doctors', 'courses'] as $group) {
            $this->assertSame([], $data[$group], $group);
        }
    }

    #[Test]
    public function search_is_forbidden_for_non_analyst_roles(): void
    {
        $c = $this->controller($this->createMock(ProjectRepository::class), $this->createMock(DataAnalysisAcademicService::class));

        $this->assertSame(403, $c->index($this->req('x', 'student'))->status());
        $this->assertSame(403, $c->quick($this->req('xx', 'university'))->status());
        $this->assertSame(200, $c->index($this->req('', 'admin'))->status());
    }

    #[Test]
    public function quick_search_ignores_queries_shorter_than_two_characters(): void
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->expects($this->never())->method('searchAll');
        $academic = $this->createMock(DataAnalysisAcademicService::class);
        $academic->expects($this->never())->method('search');

        $data = $this->controller($projects, $academic)->quick($this->req('a'))->getData(true)['data'];

        $this->assertSame(['query', 'projects', 'exams', 'students', 'doctors', 'courses'], array_keys($data));
        $this->assertSame([], $data['courses']);
    }

    #[Test]
    public function quick_search_shapes_every_group_as_title_and_meta(): void
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('searchAll')->willReturn([['title_en' => 'Physics Lab', 'title_ar' => '', 'owner_name' => 'Ali', 'category' => 'Science']]);
        $academic = $this->createMock(DataAnalysisAcademicService::class);
        $academic->method('search')->with('ph', 5)->willReturn($this->academicResult());

        $data = $this->controller($projects, $academic)->quick($this->req('ph'))->getData(true)['data'];

        $this->assertSame(['title' => 'Physics Lab', 'meta' => 'Ali · Science'], $data['projects'][0]);
        $this->assertSame(['title' => 'Physics Midterm', 'meta' => 'midterm · graded'], $data['exams'][0]);
        $this->assertSame(['title' => 'Physics Fan', 'meta' => 'S-100'], $data['students'][0]);
        $this->assertSame(['title' => 'Dr Physics', 'meta' => 'T-9'], $data['doctors'][0]);
        $this->assertSame(['title' => 'Physics 1', 'meta' => 'PHY101'], $data['courses'][0]);
    }
}
