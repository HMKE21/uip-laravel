<?php

namespace App\Repositories;

/**
 * منقولة من app/Repositories/SecurityLogRepository.php القديمة — بند 25
 * batch 1 (لداشبورد الأمان — recentEvents()). زي القديمة بالظبط: مفيش
 * جدول `security_logs` — الأحداث الأمنية (login success/failure،
 * blocked account، logout، password reset...) بتتكتب سطر واحد لكل
 * حدث في storage/logs/security/{Y-m-d}.log عن طريق Core\Logger::
 * security()، والريبو ده بيقرا نفس الملفات الحقيقية بدل ما يخترع جدول.
 *
 * ⚠️ فجوة موثّقة (زي UserSession/BlockedIp): AuthService الحالي في
 * اللارافيل لسه مش بيكتب على storage/logs/security/*.log عند كل حدث
 * (مفيش Logger::security() تم استدعاؤه من جواه لحد دلوقتي) — يعني
 * recent() هترجع array فاضي فعليًا لحد ما التسجيل الفعلي يتوصّل جوه
 * AuthService لاحقًا، مش قبل كده. القراءة نفسها هنا صحيحة 100% ومطابقة
 * للقديم — مفيش بيانات مختلقة.
 */
class SecurityLogRepository
{
    /**
     * أحداث الـ log الحقيقية عبر آخر $days ملف يومي، الأحدث أولًا،
     * بحد أقصى $limit.
     * @return array<int,array{time:string,message:string,context:array}>
     */
    public function recent(int $days = 14, int $limit = 300): array
    {
        return array_slice($this->readDays($days), 0, $limit);
    }

    /**
     * نفس تدفق الأحداث الخام زي recent()، بس من غير حد $limit —
     * محجوزة لبند 25 batch 4 (Logs — بحث/فلترة/pagination على النطاق
     * الكامل)، مش مستخدمة في batch 1.
     * @return array<int,array{time:string,message:string,context:array}>
     */
    public function readDays(int $days = 90): array
    {
        $events = $this->readFiles($days);

        // Database copy (survives redeploys). Same event may exist in both places -> de-duplicate.
        try {
            $rows = \Illuminate\Support\Facades\DB::table('security_logs')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$days} days")))
                ->orderByDesc('created_at')->limit(5000)->get();
            $seen = [];
            foreach ($events as $e) {
                $seen[$e['time'] . '|' . $e['message'] . '|' . ($e['context']['ip'] ?? '')] = true;
            }
            foreach ($rows as $r) {
                $meta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : [];
                $message = (string) ($meta['message'] ?? $r->event_type);
                unset($meta['message']);
                $meta += array_filter(['ip' => $r->ip_address, 'user_agent' => $r->user_agent, 'user_id' => $r->user_id]);
                $key = $r->created_at . '|' . $message . '|' . ($meta['ip'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $events[] = ['time' => (string) $r->created_at, 'message' => $message, 'context' => $meta];
            }
        } catch (\Throwable $e) {
            // security_logs table not migrated yet — file events only.
        }

        usort($events, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return $events;
    }

    /** @return array<int,array{time:string,message:string,context:array}> */
    private function readFiles(int $days): array
    {
        $dir = storage_path('logs/security');
        if (!is_dir($dir)) {
            return [];
        }

        $events = [];
        for ($i = 0; $i < $days; $i++) {
            $file = $dir . '/' . date('Y-m-d', strtotime("-{$i} days")) . '.log';
            if (!is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $parsed = $this->parseLine($line);
                if ($parsed) {
                    $events[] = $parsed;
                }
            }
        }

        return $events;
    }

    private function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^\[(?<time>[^\]]+)\]\s+\w+:\s+(?<rest>.+)$/', $line, $m)) {
            return null;
        }

        $rest = $m['rest'];
        $context = [];
        if (preg_match('/^(?<message>.*?)\s+(?<json>\{.*\})$/', $rest, $jm)) {
            $message = trim($jm['message']);
            $decoded = json_decode($jm['json'], true);
            $context = is_array($decoded) ? $decoded : [];
        } else {
            $message = trim($rest);
        }

        return ['time' => $m['time'], 'message' => $message, 'context' => $context];
    }
}
