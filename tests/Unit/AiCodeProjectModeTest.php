<?php

namespace Tests\Unit;

use App\Services\AiCodeAssistService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** الوضع اللي الدكتور ضابطه على المشروع بيغلب اختيار الطالب وإعدادات الأدمن؛ off = 403. (pure: من غير DB.) */
class AiCodeProjectModeTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $r = new ReflectionClass(AiCodeAssistService::class);
        $svc = $r->newInstanceWithoutConstructor();
        $m = $r->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($svc, ...$args);
    }

    public function test_student_choice_is_used_when_no_project_override(): void
    {
        $cfg = ['allow_mode_switch' => true, 'default_mode' => 'tutor'];
        $this->assertSame('assistant', $this->call('resolveMode', 'assistant', $cfg, null));
        $this->assertSame('tutor', $this->call('resolveMode', 'junk', $cfg, null));
    }

    public function test_project_override_beats_student_choice_and_admin_lock(): void
    {
        $this->assertSame('tutor', $this->call('resolveMode', 'assistant', ['allow_mode_switch' => true, 'default_mode' => 'assistant'], 'tutor'));
        $this->assertSame('assistant', $this->call('resolveMode', 'tutor', ['allow_mode_switch' => false, 'default_mode' => 'tutor'], 'assistant'));
    }

    public function test_off_is_refused_with_403(): void
    {
        try {
            $this->call('assertProjectAllows', 'off');
            $this->fail('off must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->call('assertProjectAllows', null);
        $this->call('assertProjectAllows', 'tutor');
        $this->assertTrue(true);
    }
}
