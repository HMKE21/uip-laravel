<?php

namespace App\Support;

/**
 * منقولة من Core\Logger::security() القديمة — بند 25 batch 4 (سد الفجوة
 * الموثّقة في SecurityLogRepository/AccountLockoutService: "Logger::
 * security() -> Log:: (زي باقي الخدمات)" كانت بديل مؤقت لحد ما بورتال
 * الأمان يوصل؛ دلوقتي وصل). بتكتب سطر واحد بالظبط بنفس الشكل القديم —
 * `[Y-m-d H:i:s] SECURITY: message {json}` — في
 * storage/logs/security/{Y-m-d}.log، وهو الشكل اللي
 * SecurityLogRepository::parseLine() بيتوقعه حرفيًا. اتعمل كـ static
 * helper منفصل عن Illuminate\Support\Facades\Log عن قصد: قناة Laravel
 * العادية بتنسّق بشكل مختلف (Monolog)، ومفيش داعي نضيف Monolog formatter
 * مخصص لسطر واحد بسيط زي ده — القديمة أصلًا "vanilla PHP only".
 */
class SecurityLog
{
    public static function write(string $message, array $context = []): void
    {
        if (!isset($context['ip'])) {
            $ip = request()?->header('X-Forwarded-For') ?: request()?->ip();
            if ($ip) {
                $context['ip'] = $ip;
            }
        }
        if (!isset($context['user_agent'])) {
            $ua = request()?->userAgent();
            if ($ua) {
                $context['user_agent'] = $ua;
            }
        }

        // Durable copy in the database: hosted/ephemeral filesystems lose (or can't write) the
        // daily files, which left the admin Security Logs page empty. Never breaks the request.
        try {
            $uid = isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : null;
            if ($uid !== null && !\Illuminate\Support\Facades\DB::table('users')->where('id', $uid)->exists()) {
                $uid = null;
            }
            \Illuminate\Support\Facades\DB::table('security_logs')->insert([
                'user_id'    => $uid,
                'event_type' => mb_substr($message, 0, 80),
                'ip_address' => isset($context['ip']) ? mb_substr((string) $context['ip'], 0, 45) : null,
                'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 255) : null,
                'severity'   => 'info',
                'meta'       => json_encode(['message' => $message] + $context, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // table missing / DB down — the file copy below still tries.
        }

        $dir = storage_path('logs/security');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . date('Y-m-d') . '.log';
        $line = sprintf(
            '[%s] %s: %s %s%s',
            date('Y-m-d H:i:s'),
            'SECURITY',
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '',
            PHP_EOL
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
