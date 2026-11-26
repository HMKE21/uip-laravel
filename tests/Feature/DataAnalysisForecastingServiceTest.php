<?php

namespace Tests\Feature;

use App\Repositories\ForecastRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\ForecastingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بوابة Data Analysis — Forecasting: المنطق الإحصائي (انحدار خطي + نطاق ثقة +
 * clamp) وسجل المقاييس التسعة. من غير DB: الريبوزيتوري متعمله mock، فالـ SQL
 * نفسه (DATE_FORMAT على MySQL) مش متغطي هنا — بيتجرّب على MySQL يدويًا.
 */
class DataAnalysisForecastingServiceTest extends TestCase
{
    private const EXPECTED_KEYS = [
        'student_growth', 'project_growth', 'university_performance', 'innovation_trends', 'ai_readiness_trends',
        'attendance_rate', 'enrollment_trends', 'fee_collection', 'user_activity',
    ];

    /** @return array<int,array{month:string,value:float}> */
    private function series(array $values, string $startMonth = '2026-01'): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[] = ['month' => date('Y-m', strtotime($startMonth . '-01 +' . $i . ' months')), 'value' => (float) $v];
        }
        return $out;
    }

    /** ريبوزيتوري كل ميثودات المقاييس فيه بترجّع نفس السلسلة. */
    private function service(array $history, ?AIClient $client = null): ForecastingService
    {
        $repo = $this->createMock(ForecastRepository::class);
        foreach (['studentGrowth', 'projectGrowth', 'universityPerformance', 'innovationTrends', 'aiReadinessTrends',
                  'attendanceRate', 'enrollmentTrends', 'feeCollection', 'activeUsers'] as $method) {
            $repo->method($method)->willReturn($history);
        }
        return new ForecastingService($repo, $client ?? $this->createMock(AIClient::class), $this->createMock(SettingRepository::class));
    }

    #[Test]
    public function registry_has_all_nine_metrics_each_backed_by_a_repository_method(): void
    {
        $metrics = $this->service([])->metrics();

        $this->assertEqualsCanonicalizing(self::EXPECTED_KEYS, array_keys($metrics));
        foreach ($metrics as $key => $meta) {
            $this->assertTrue(method_exists(ForecastRepository::class, $meta['method']), "$key → {$meta['method']}() missing on ForecastRepository");
            $this->assertContains($meta['unit'], ['count', 'percent', 'score', 'amount'], "$key unit");
            foreach (['label', 'description'] as $field) {
                $this->assertNotEmpty($meta[$field]['en'] ?? null, "$key $field.en");
                $this->assertNotEmpty($meta[$field]['ar'] ?? null, "$key $field.ar");
            }
        }
        $this->assertSame('percent', $metrics['attendance_rate']['unit']);
        $this->assertSame('amount', $metrics['fee_collection']['unit']);
    }

    #[Test]
    public function unknown_metric_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->forecast('nope');
    }

    #[Test]
    public function perfectly_linear_history_is_extrapolated_exactly_with_a_95_percent_band(): void
    {
        $f = $this->service($this->series([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]))->forecast('enrollment_trends');

        $this->assertSame('up', $f['trend_direction']);
        $this->assertSame(95, $f['confidence_level']);
        $this->assertCount(3, $f['forecast']);
        $this->assertSame([13.0, 14.0, 15.0], array_column($f['forecast'], 'value'));
        $this->assertSame(['2027-01', '2027-02', '2027-03'], array_column($f['forecast'], 'month'));
        $this->assertEqualsWithDelta(1.0, $f['accuracy']['r2'], 0.001);
        foreach ($f['forecast'] as $p) {
            $this->assertLessThanOrEqual($p['value'], $p['lower']);
            $this->assertGreaterThanOrEqual($p['value'], $p['upper']);
        }
    }

    #[Test]
    public function percent_metric_is_clamped_to_100(): void
    {
        $f = $this->service($this->series([90, 92, 94, 96, 98, 100]))->forecast('attendance_rate');

        foreach ($f['forecast'] as $p) {
            $this->assertLessThanOrEqual(100.0, $p['value']);
            $this->assertLessThanOrEqual(100.0, $p['upper']);
            $this->assertGreaterThanOrEqual(0.0, $p['lower']);
        }
    }

    #[Test]
    public function declining_amount_metric_never_forecasts_negative_money(): void
    {
        $f = $this->service($this->series([500, 400, 300, 200, 100]))->forecast('fee_collection');

        $this->assertSame('down', $f['trend_direction']);
        foreach ($f['forecast'] as $p) {
            $this->assertGreaterThanOrEqual(0.0, $p['value']);
            $this->assertGreaterThanOrEqual(0.0, $p['lower']);
        }
    }

    #[Test]
    public function empty_history_does_not_throw_and_is_flat_zero(): void
    {
        // مثال: attendance_rate على بيئة مفيهاش أي تحضير.
        $f = $this->service([])->forecast('attendance_rate');

        $this->assertSame([], $f['history']);
        $this->assertSame('flat', $f['trend_direction']);
        $this->assertSame([0.0, 0.0, 0.0], array_column($f['forecast'], 'value'));
        $this->assertNull($f['historical_comparison']['change_pct']);
    }

    #[Test]
    public function forecast_all_returns_one_forecast_per_registered_metric(): void
    {
        $all = $this->service($this->series([3, 3, 4, 4, 5, 5]))->forecastAll();

        $this->assertEqualsCanonicalizing(self::EXPECTED_KEYS, array_keys($all));
        foreach ($all as $key => $f) {
            $this->assertSame($key, $f['key']);
        }
    }

    #[Test]
    public function explain_refuses_when_ai_is_not_configured(): void
    {
        $client = $this->createMock(AIClient::class);
        $client->method('isConfigured')->willReturn(false);
        $svc = $this->service($this->series([1, 2, 3]), $client);

        $this->assertFalse($svc->isAiAvailable());
        $this->expectException(\RuntimeException::class);
        $svc->explain($svc->forecast('user_activity'));
    }

    #[Test]
    public function explain_strips_reasoning_blocks_from_the_model_reply(): void
    {
        $client = $this->createMock(AIClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('complete')->willReturn("<think>internal notes</think>\nEnrollment is rising steadily.");
        $svc = $this->service($this->series([1, 2, 3]), $client);

        $this->assertSame('Enrollment is rising steadily.', $svc->explain($svc->forecast('enrollment_trends')));
    }
}
