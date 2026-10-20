<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Repositories\BlockedIpRepository;
use App\Repositories\RiskScoreRepository;
use App\Repositories\SecurityAlertRepository;
use App\Repositories\SecurityIncidentRepository;
use App\Repositories\SecurityLogRepository;
use App\Repositories\SecurityNotificationRepository;
use App\Repositories\UserSessionRepository;
use App\Repositories\VulnerabilityRepository;

/**
 * منقولة من app/Services/SecurityDashboardService.php القديمة — بند 25
 * batch 1 (Security Portal — الداشبورد الرئيسية). نفس الميثودز الست
 * بالظبط (overview/recentIncidents/topRisks/notificationsFeed/
 * recentEvents/incidentsBySeverity)، بتجمّع من نفس الريبوهات الحقيقية
 * بس — مفيش أرقام مختلقة، تمامًا زي القديمة.
 */
class SecurityDashboardService
{
    public function __construct(
        private SecurityIncidentRepository $incidents,
        private VulnerabilityRepository $vulnerabilities,
        private UserSessionRepository $sessions,
        private RiskScoreRepository $riskScores,
        private SecurityNotificationRepository $notifications,
        private SecurityLogRepository $securityLogs,
        private BlockedIpRepository $blockedIps,
        private SecurityAlertRepository $alerts
    ) {
    }

    /** @return array<string,int|float> قيم كروت الإحصائيات */
    public function overview(): array
    {
        return [
            'active_alerts'         => $this->alerts->countOpen(),
            'open_incidents'        => $this->incidents->countOpen(),
            'critical_incidents'    => $this->incidents->countBySeverity('critical'),
            'open_vulnerabilities'  => $this->vulnerabilities->countOpen(),
            'active_sessions'       => $this->sessions->countActive(),
            'active_users'          => $this->sessions->countDistinctActiveUsers(),
            'blocked_ips'           => count($this->blockedIps->all()),
            'average_risk_score'    => round($this->riskScores->averageScore(), 1),
        ];
    }

    /** @return array<int,array<string,mixed>> أحدث الحوادث لفيد الداشبورد */
    public function recentIncidents(int $limit = 6): array
    {
        return $this->incidents->allWithAssignee($limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function topRisks(int $limit = 5): array
    {
        return array_map(function ($row) {
            $row['factors'] = json_decode($row['factors'] ?? '{}', true) ?: [];
            return $row;
        }, $this->riskScores->topRisks($limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function notificationsFeed(string $role, int $limit = 10): array
    {
        return $this->notifications->forRole($role, $limit);
    }

    /** @return array<int,array<string,mixed>> أحدث أحداث log الأمان الخام (ملف-محور) */
    public function recentEvents(int $limit = 8): array
    {
        return array_slice($this->securityLogs->recent(7, 200), 0, $limit);
    }

    /**
     * سلسلة زمنية يومية حقيقية (الأقدم أولًا، بطول $days بالظبط) للرسم "النشاط الأمني":
     *  - incidents: الحوادث حسب detected_at من جدول security_incidents.
     *  - events: تسجيلات الدخول (user_sessions.created_at) + صفوف security_logs لو الجدول موجود
     *    + أحداث ملفات storage/logs/security. كل مصدر معزول بـ try/catch فلو واحد وقع الباقي يشتغل.
     * @return array<int,array{date:string,incidents:int,events:int}>
     */
    public function activity(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $start = now()->startOfDay()->subDays($days - 1);

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $buckets[$start->copy()->addDays($i)->format('Y-m-d')] = ['incidents' => 0, 'events' => 0];
        }

        $tally = function (string $key, $rows, string $col) use (&$buckets) {
            foreach ($rows as $v) {
                $d = substr((string) (is_object($v) ? $v->{$col} : $v), 0, 10);
                if (isset($buckets[$d])) {
                    $buckets[$d][$key]++;
                }
            }
        };

        try {
            $tally('incidents', DB::table('security_incidents')->where('detected_at', '>=', $start)->get(['detected_at']), 'detected_at');
        } catch (\Throwable $e) {
        }
        try {
            $tally('events', DB::table('user_sessions')->where('created_at', '>=', $start)->get(['created_at']), 'created_at');
        } catch (\Throwable $e) {
        }
        try {
            if (Schema::hasTable('security_logs')) {
                $tally('events', DB::table('security_logs')->where('created_at', '>=', $start)->get(['created_at']), 'created_at');
            }
        } catch (\Throwable $e) {
        }
        try {
            foreach ($this->securityLogs->readDays($days) as $ev) {
                $d = substr((string) ($ev['time'] ?? ''), 0, 10);
                if (isset($buckets[$d])) {
                    $buckets[$d]['events']++;
                }
            }
        } catch (\Throwable $e) {
        }

        $out = [];
        foreach ($buckets as $date => $v) {
            $out[] = ['date' => $date, 'incidents' => $v['incidents'], 'events' => $v['events']];
        }
        return $out;
    }

    /** @return array{low:int,medium:int,high:int,critical:int} توزيع الحوادث حسب الخطورة */
    public function incidentsBySeverity(): array
    {
        return [
            'low'      => $this->incidents->countBySeverity('low'),
            'medium'   => $this->incidents->countBySeverity('medium'),
            'high'     => $this->incidents->countBySeverity('high'),
            'critical' => $this->incidents->countBySeverity('critical'),
        ];
    }
}
