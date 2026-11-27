<?php

namespace App\Support;

/**
 * توقيعات هجمات شائعة (قراءة بس، من غير أي تأثير على الطلب). أي match بيرجّع اسم الفئة، وإلا null.
 * مقصود إنها "ضيقة" (مش كلمة SELECT لوحدها) عشان نص طبيعي أو كود طالب مايتعدّش هجوم.
 */
class AttackSignatures
{
    private const MAX_LEN = 4000;

    /** @var array<string,string[]> category => regex[] */
    private const PATTERNS = [
        'sql_injection' => [
            '/\bunion\b(?:\s|\/\*.*?\*\/)+(?:all\s+)?select\b/is',
            '/[\'"]\s*(?:or|and)\s+[\'"]?(\d+|[a-z]+)[\'"]?\s*=\s*[\'"]?\1\b/i',
            '/\b(?:or|and)\s+\d+\s*=\s*\d+\s*(?:--|#|\/\*|$)/i',
            '/\b(?:pg_sleep|benchmark)\s*\(\s*\d/i',
            '/\bsleep\s*\(\s*\d+\s*\)/i',
            '/\bwaitfor\s+delay\s+[\'"]\d/i',
            '/;\s*(?:drop|truncate|alter)\s+(?:table|database|schema)\b/i',
            '/;\s*(?:delete\s+from|insert\s+into|update\s+\w+\s+set)\b/i',
            '/\binformation_schema\b|\bsqlite_master\b|\bpg_catalog\b|\bsys\.(?:tables|objects|columns)\b/i',
            '/\b(?:extractvalue|updatexml)\s*\(/i',
            '/\bload_file\s*\(|\binto\s+(?:out|dump)file\b/i',
            '/[\'"]\s*(?:--|#)\s*$/',
        ],
        'xss' => [
            '/<\s*script\b/i',
            '/<\s*(?:iframe|object|embed)\b/i',
            '/<\s*[a-z][a-z0-9]*\b[^>]*\son(?:error|load|click|mouseover|focus|toggle|mouseenter|animationstart)\s*=/i',
            '/<\s*svg\b[^>]*\bon\w+\s*=/i',
            '/(?:href|src|action|formaction)\s*=\s*[\'"]?\s*javascript\s*:/i',
            '/^\s*javascript\s*:/i',
        ],
        'path_traversal' => [
            '/(?:\.\.[\/\\\\]){2,}/',
            '/(?:%2e|\.){2}(?:%2f|%5c)/i',
            '/\/etc\/(?:passwd|shadow|hosts|issue)\b/i',
            '/\b(?:c:)?[\\\\\/]windows[\\\\\/]system32\b|\bboot\.ini\b/i',
            '/\b(?:php|phar|expect|zip|glob):\/\/|\bfile:\/\/\//i',
            '/%00|\x00/',
        ],
        'command_injection' => [
            '/[;|&`]{1,2}\s*(?:cat\s+[\/~.]|ls\s+-|wget\s+https?:|curl\s+https?:|nc\s+-|bash\s+-|sh\s+-c|whoami\b|uname\s+-a|id\s*(?:;|$)|powershell\b|cmd(?:\.exe)?\s+\/c)/i',
            '/\$\(\s*(?:cat|ls|id|whoami|curl|wget|bash|sh)\b[^)]*\)/i',
            '/\$\{\s*jndi\s*:/i',
            '/\{\{\s*\d+\s*\*\s*\d+\s*\}\}|\$\{\s*\d+\s*\*\s*\d+\s*\}/',
        ],
        'ssrf' => [
            '/\b(?:169\.254\.169\.254|metadata\.google\.internal|100\.100\.100\.200)\b/i',
            '/\b(?:https?|gopher|dict|ftp):\/\/(?:localhost|127\.\d+\.\d+\.\d+|0\.0\.0\.0|\[::1\])(?::\d+)?\//i',
        ],
    ];

    private const SCANNER_UA = '/(?:sqlmap|nikto|nmap|masscan|acunetix|nessus|openvas|wpscan|dirbuster|dirb\b|gobuster|ffuf|feroxbuster|nuclei|zgrab|havij|w3af|netsparker|appscan|arachni|metasploit|hydra|medusa|burp\s?collaborator|jaeles|whatweb|xsstrike|commix)/i';

    private const SENSITIVE_PATH = '#(?:^|/)\.(?:env|git|svn|hg|aws|ssh|htaccess|htpasswd|ds_store|npmrc|dockerenv)(?:/|$|\.)'
        . '|wp-(?:admin|login|content|includes|config)|xmlrpc\.php|phpmyadmin|/pma/|adminer'
        . '|vendor/phpunit|eval-stdin|/(?:cgi-bin|boaform|actuator|server-status|server-info|solr/admin|manager/html|jmx-console|console/login|telescope|horizon|_ignition|_profiler)(?:/|$)'
        . '|\.(?:php\d?|phtml|asp|aspx|jsp|cgi|pl)$'
        . '|\.(?:sql|bak|old|backup|swp|ini|log|ya?ml|pem|key|zip|tar|tgz|gz|rar|7z)$'
        . '|(?:id_rsa|id_dsa|\.bash_history|composer\.(?:json|lock)|package\.json|web\.config)$#i';

    /** @return string|null category */
    public static function inspect(string $value): ?string
    {
        if ($value === '' || strlen($value) < 4) {
            return null;
        }
        $value = substr($value, 0, self::MAX_LEN);
        $candidates = [$value];
        if (str_contains($value, '%')) {
            $d1 = rawurldecode($value);
            $candidates[] = $d1;
            if (str_contains($d1, '%')) {
                $candidates[] = rawurldecode($d1);
            }
        }
        foreach (array_unique($candidates) as $v) {
            foreach (self::PATTERNS as $category => $list) {
                foreach ($list as $re) {
                    if (@preg_match($re, $v) === 1) {
                        return $category;
                    }
                }
            }
        }
        return null;
    }

    public static function scannerUserAgent(?string $ua): ?string
    {
        if (!$ua) {
            return null;
        }
        return preg_match(self::SCANNER_UA, substr($ua, 0, 300), $m) === 1 ? strtolower($m[0]) : null;
    }

    public static function sensitivePath(string $path): bool
    {
        return preg_match(self::SENSITIVE_PATH, '/' . ltrim(strtolower($path), '/')) === 1;
    }
}
