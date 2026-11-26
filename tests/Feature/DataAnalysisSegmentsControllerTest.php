<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DataAnalysisSegmentsApiController;
use App\Models\DataSegment;
use App\Repositories\DataSegmentRepository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بوابة Data Analysis — Segments: RBAC + التحقق من الكيانات الخمسة والـ criteria
 * المسموحة. الريبوزيتوري متعمله mock (من غير DB)؛ بنستدعي الكنترولر مباشرة بـ
 * attributes الـ uip.auth زي ما الـ middleware بيحطها.
 */
class DataAnalysisSegmentsControllerTest extends TestCase
{
    private function controller(?DataSegmentRepository $repo = null): DataAnalysisSegmentsApiController
    {
        return new DataAnalysisSegmentsApiController($repo ?? $this->createMock(DataSegmentRepository::class));
    }

    private function req(string $method, array $body = [], string $role = 'data_analyst'): Request
    {
        $r = Request::create('/api/v1/data-analysis/segments', $method, $body);
        $r->attributes->set('uip_role', $role);
        $r->attributes->set('uip_user_id', 7);
        return $r;
    }

    private function saved(string $entity, array $criteria): DataSegment
    {
        return (new DataSegment())->forceFill(['id' => 1, 'user_id' => 7, 'name' => 'S', 'entity' => $entity, 'criteria' => json_encode($criteria)]);
    }

    #[Test]
    public function only_data_analyst_and_admin_are_allowed(): void
    {
        foreach (['student', 'university', 'academic_staff', ''] as $role) {
            $c = $this->controller();
            $this->assertSame(403, $c->index($this->req('GET', [], $role))->status(), "index as '$role'");
            $this->assertSame(403, $c->store($this->req('POST', ['name' => 'x', 'entity' => 'users'], $role))->status(), "store as '$role'");
            $this->assertSame(403, $c->destroy($this->req('DELETE', [], $role), 1)->status(), "destroy as '$role'");
        }
        foreach (['data_analyst', 'admin'] as $role) {
            $repo = $this->createMock(DataSegmentRepository::class);
            $repo->method('forUser')->willReturn([]);
            $this->assertSame(200, $this->controller($repo)->index($this->req('GET', [], $role))->status(), "index as '$role'");
        }
    }

    #[Test]
    public function index_exposes_all_five_entities_and_their_criteria_options(): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->method('forUser')->with(7)->willReturn([]);

        $data = $this->controller($repo)->index($this->req('GET'))->getData(true)['data'];

        $this->assertSame(['projects', 'users', 'students', 'exams', 'courses'], $data['entities']);
        $this->assertSame(['students', 'exams', 'courses'], array_keys($data['criteria_options']));
        $this->assertContains(1, $data['criteria_options']['courses']['semester']);
        $this->assertContains('graded', $data['criteria_options']['exams']['status']);
        // مفاتيح قديمة لازم تفضل (توافق مع الفرونت القديم).
        $this->assertArrayHasKey('role_options', $data);
        $this->assertArrayHasKey('user_status_options', $data);
    }

    #[Test]
    public function store_requires_name_and_entity(): void
    {
        $this->assertSame(422, $this->controller()->store($this->req('POST', ['entity' => 'users']))->status());
        $this->assertSame(422, $this->controller()->store($this->req('POST', ['name' => 'x']))->status());
    }

    #[Test]
    public function store_rejects_unknown_entity(): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->expects($this->never())->method('create');

        $this->assertSame(422, $this->controller($repo)->store($this->req('POST', ['name' => 'x', 'entity' => 'payments']))->status());
    }

    /** @return array<string,array{0:string,1:array,2:array}> entity, request filters, criteria المتوقع تتخزن */
    public static function validSegments(): array
    {
        return [
            'students'            => ['students', ['status' => 'active', 'academic_year' => '3', 'invitation_status' => 'accepted'], ['status' => 'active', 'academic_year' => 3, 'invitation_status' => 'accepted']],
            'students (no rules)' => ['students', [], []],
            'exams'               => ['exams', ['status' => 'graded', 'result_visibility' => 'manual'], ['status' => 'graded', 'result_visibility' => 'manual']],
            'courses'             => ['courses', ['status' => 'active', 'semester' => '2', 'academic_year' => '1'], ['status' => 'active', 'semester' => 2, 'academic_year' => 1]],
            'users (existing)'    => ['users', ['role' => 'student', 'status' => 'pending'], ['status' => 'pending', 'role' => 'student']],
            'projects (existing)' => ['projects', ['category' => 'AI', 'status' => 'published'], ['category' => 'AI', 'status' => 'published']],
        ];
    }

    #[Test]
    #[DataProvider('validSegments')]
    public function store_saves_allow_listed_criteria_with_numeric_values_cast_to_int(string $entity, array $filters, array $expected): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->expects($this->once())->method('create')
            ->with(7, 'My segment', $entity, $this->callback(function (array $criteria) use ($expected) {
                ksort($criteria);
                ksort($expected);
                return $criteria === $expected; // === يتحقق من النوع كمان: 3 مش "3"
            }))
            ->willReturn($this->saved($entity, $expected));

        $res = $this->controller($repo)->store($this->req('POST', ['name' => 'My segment', 'entity' => $entity] + $filters));

        $this->assertSame(201, $res->status());
    }

    /** @return array<string,array{0:string,1:array}> */
    public static function invalidCriteria(): array
    {
        return [
            'students bad status'      => ['students', ['status' => 'bogus']],
            'students year out of range' => ['students', ['academic_year' => '9']],
            'students bad invitation'  => ['students', ['invitation_status' => 'sent']],
            'exams bad status'         => ['exams', ['status' => 'nope']],
            'exams bad visibility'     => ['exams', ['result_visibility' => 'later']],
            'courses bad semester'     => ['courses', ['semester' => '3']],
            'courses bad status'       => ['courses', ['status' => 'draft']],
            'array value (injection)'  => ['courses', ['status' => ['active', 'archived']]],
            'sql-ish value'            => ['exams', ['status' => "graded' OR '1'='1"]],
        ];
    }

    #[Test]
    #[DataProvider('invalidCriteria')]
    public function store_rejects_values_outside_the_allow_list(string $entity, array $filters): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->expects($this->never())->method('create');

        $res = $this->controller($repo)->store($this->req('POST', ['name' => 'x', 'entity' => $entity] + $filters));

        $this->assertSame(422, $res->status());
    }

    #[Test]
    public function store_ignores_keys_that_do_not_belong_to_the_entity(): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->expects($this->once())->method('create')
            ->with(7, 'x', 'exams', ['status' => 'closed']) // role/foo/academic_year مش مفاتيح exams
            ->willReturn($this->saved('exams', ['status' => 'closed']));

        $res = $this->controller($repo)->store($this->req('POST', [
            'name' => 'x', 'entity' => 'exams', 'status' => 'closed', 'role' => 'admin', 'foo' => 'bar', 'academic_year' => '2',
        ]));

        $this->assertSame(201, $res->status());
    }

    #[Test]
    public function destroy_returns_404_when_segment_is_not_owned_or_missing(): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->method('delete')->with(5, 7)->willReturn(false);

        $this->assertSame(404, $this->controller($repo)->destroy($this->req('DELETE'), 5)->status());
    }

    #[Test]
    public function destroy_returns_200_for_owned_segment(): void
    {
        $repo = $this->createMock(DataSegmentRepository::class);
        $repo->method('delete')->with(5, 7)->willReturn(true);

        $this->assertSame(200, $this->controller($repo)->destroy($this->req('DELETE'), 5)->status());
    }
}
