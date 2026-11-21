<?php

namespace App\Services\Developer\Sdk;

use App\Services\Developer\WebhookSignature;

/**
 * مولّد الـ SDKs (Python / JavaScript / PHP) من OpenAPI spec بتاع الـ Developer API.
 *
 *  - الثابت (client, errors, webhooks, tests, README) قوالب في resources/sdk/<lang>/**.tpl. الملف بيتكتب من غير `.tpl` بعد استبدال {{PLACEHOLDERS}}.
 *  - المتغيّر (operations: ميثود لكل endpoint) بيتولّد هنا من الـ spec نفسه، فأي endpoint جديد بيظهر في الـ 3 مكتبات لما نشغّل `developer:sdk`.
 *  - مفيش تاريخ ولا عشوائية في الخرج، فنفس الـ spec بيطلّع نفس الملفات بالبايت. ده اللي بيخلّي `developer:sdk --check` يشتغل في الـ CI.
 *  - الـ test vector للـ webhooks مصدره WebhookSignature::describe() (نفس اللي بيتنشر في /docs/webhook-verification) فكل لغة بتتأكد إنها بتطابق الـ backend.
 *
 * generate() بتبني الخرج في الذاكرة بس (path => content). الكتابة والمقارنة في الأمر.
 */
final class SdkGenerator
{
    public const LANGS = ['python', 'js', 'php'];

    /**
     * @param string[] $langs
     * @return array<string,string> مسار نسبي (openapi.json | python/... | js/... | php/...) => المحتوى
     */
    public static function generate(array $spec, array $langs = self::LANGS, string $version = '1.0.0', ?string $templates = null): array
    {
        $templates = $templates ?? resource_path('sdk');
        $ops = self::operations($spec);
        if (!$ops) {
            throw new \RuntimeException('The spec has no operations to generate an SDK from.');
        }
        $vector = WebhookSignature::describe()['test_vector'];
        $common = [
            '{{BASE_URL}}' => (string) ($spec['servers'][0]['url'] ?? ''),
            '{{SDK_VERSION}}' => $version,
            '{{TOLERANCE}}' => (string) WebhookSignature::DEFAULT_TOLERANCE, // الثابت مش config: عشان الخرج ميتغيّرش من بيئة لبيئة
            '{{API_TITLE}}' => (string) ($spec['info']['title'] ?? 'API'),
            '{{API_VERSION}}' => (string) ($spec['info']['version'] ?? ''),
        ];

        $out = ['openapi.json' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"];

        foreach ($langs as $lang) {
            if (!in_array($lang, self::LANGS, true)) {
                throw new \InvalidArgumentException("Unknown language '{$lang}'. Use: " . implode(', ', self::LANGS));
            }
            $vars = $common + [
                '{{OPERATIONS_TABLE}}' => self::table($lang, $ops),
                '{{OPERATIONS_DTS}}' => $lang === 'js' ? self::jsTypes($ops) : '',
            ] + self::vectorVars($lang, $vector);

            $dir = rtrim($templates, '/\\') . '/' . $lang;
            if (!is_dir($dir)) {
                throw new \RuntimeException("Template folder not found: {$dir}");
            }
            foreach (self::templateFiles($dir) as $rel => $abs) {
                $content = (string) file_get_contents($abs);
                if (str_ends_with($rel, '.tpl')) {
                    $rel = substr($rel, 0, -4);
                    $content = strtr($content, $vars);
                }
                self::assertResolved($content, $lang . '/' . $rel);
                $out[$lang . '/' . $rel] = $content;
            }
            foreach (self::generatedFiles($lang, $ops) as $rel => $content) {
                self::assertResolved($content, $lang . '/' . $rel);
                $out[$lang . '/' . $rel] = $content;
            }
        }
        ksort($out);
        return $out;
    }

    /* ------------------------------------------------------------------ spec parsing */

    /**
     * عمليات الـ Developer API فقط (مسارات /oauth/* بره: الـ SDK بيشتغل بـ API key أو access token جاهز).
     * @return list<array{id:string,method:string,path:string,summary:string,pathParams:list<string>,query:list<string>,hasBody:bool,bodyRequired:bool,scope:?string,mode:string}>
     */
    public static function operations(array $spec): array
    {
        $ops = [];
        $ids = [];
        foreach ((array) ($spec['paths'] ?? []) as $path => $methods) {
            if (str_starts_with((string) $path, '/oauth/')) {
                continue;
            }
            foreach ((array) $methods as $method => $op) {
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || !is_array($op)) {
                    continue;
                }
                $id = (string) ($op['operationId'] ?? '');
                if ($id === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $id)) {
                    throw new \RuntimeException("Operation {$method} {$path} has no usable operationId.");
                }
                if (isset($ids[$id])) {
                    throw new \RuntimeException("Duplicate operationId {$id}.");
                }
                $ids[$id] = true;

                preg_match_all('/\{(\w+)\}/', (string) $path, $m);
                $query = [];
                foreach ((array) ($op['parameters'] ?? []) as $p) {
                    if (($p['in'] ?? '') === 'query' && isset($p['name'])) {
                        $query[] = (string) $p['name'];
                    }
                }
                $ops[] = [
                    'id' => $id,
                    'method' => strtoupper((string) $method),
                    'path' => (string) $path,
                    'summary' => trim((string) ($op['summary'] ?? $id)),
                    'pathParams' => $m[1],
                    'query' => $query,
                    'hasBody' => isset($op['requestBody']),
                    'bodyRequired' => !empty($op['requestBody']['required']),
                    'scope' => isset($op['x-required-scope']) ? (string) $op['x-required-scope'] : null,
                    'mode' => (string) ($op['x-uipe-mode'] ?? 'both'),
                ];
            }
        }
        return $ops;
    }

    /* ------------------------------------------------------------------ names */

    public static function snake(string $id): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $id));
    }

    /** @param array<string,mixed> $op */
    private static function name(string $lang, array $op): string
    {
        return $lang === 'python' ? self::snake($op['id']) : lcfirst($op['id']);
    }

    /** اسم باراميتر المسار في اللغة (Python snake_case). */
    private static function param(string $lang, string $name): string
    {
        return $lang === 'python' ? self::snake($name) : $name;
    }

    /** نص بيتحط جوه تعليق/docstring من غير ما يكسره. */
    private static function clean(string $s): string
    {
        return trim((string) preg_replace('/[\\\\"*\/\r\n\t]+/', ' ', $s));
    }

    /** @param array<string,mixed> $op */
    private static function doc(array $op): string
    {
        $doc = self::clean($op['summary']) . ' (' . $op['method'] . ' ' . $op['path'] . ').';
        if ($op['scope']) {
            $doc .= ' Scope: ' . $op['scope'] . '.';
        }
        if ($op['mode'] === 'live') {
            $doc .= ' Live credentials only.';
        } elseif ($op['mode'] === 'sandbox') {
            $doc .= ' Sandbox credentials only.';
        }
        return $doc;
    }

    /* ------------------------------------------------------------------ generated files */

    /**
     * @param list<array<string,mixed>> $ops
     * @return array<string,string>
     */
    private static function generatedFiles(string $lang, array $ops): array
    {
        return match ($lang) {
            'python' => ['uipe/operations.py' => self::pythonOps($ops)],
            'js' => ['src/operations.js' => self::jsOps($ops)],
            'php' => ['src/Operations.php' => self::phpOps($ops)],
        };
    }

    private static function lit(string $s): string
    {
        return (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function pythonOps(array $ops): string
    {
        $o = "\"\"\"Generated from openapi.json by `php artisan developer:sdk`. Do not edit by hand.\"\"\"\n\n\nclass Operations(object):\n    \"\"\"One method per API operation. Mixed into Client.\"\"\"\n";
        foreach ($ops as $op) {
            $args = ['self'];
            foreach ($op['pathParams'] as $p) {
                $args[] = self::param('python', $p);
            }
            if ($op['hasBody']) {
                $args[] = $op['bodyRequired'] ? 'body' : 'body=None';
            }
            if ($op['query']) {
                $args[] = 'query=None';
            }
            if ($op['pathParams']) {
                $pairs = array_map(fn ($p) => self::lit($p) . ': ' . self::param('python', $p), $op['pathParams']);
                $path = 'self._expand(' . self::lit($op['path']) . ', {' . implode(', ', $pairs) . '})';
            } else {
                $path = self::lit($op['path']);
            }
            $call = 'self.request(' . self::lit($op['method']) . ', ' . $path
                . ($op['query'] ? ', query=query' : '') . ($op['hasBody'] ? ', body=body' : '') . ')';
            $o .= "\n    def " . self::name('python', $op) . '(' . implode(', ', $args) . "):\n"
                . '        """' . self::doc($op) . "\"\"\"\n"
                . '        return ' . $call . "\n";
        }
        return $o;
    }

    private static function jsOps(array $ops): string
    {
        $o = "'use strict';\n\n// Generated from openapi.json by `php artisan developer:sdk`. Do not edit by hand.\n\n/** One method per API operation. Extended by Client. */\nclass Operations {";
        foreach ($ops as $op) {
            $args = array_merge($op['pathParams'], ['opts = {}']);
            if ($op['pathParams']) {
                $pairs = array_map(fn ($p) => $p, $op['pathParams']);
                $path = 'this._expand(' . self::lit($op['path']) . ', { ' . implode(', ', $pairs) . ' })';
            } else {
                $path = self::lit($op['path']);
            }
            $o .= "\n  /** " . self::doc($op) . " */\n  " . self::name('js', $op) . '(' . implode(', ', $args) . ") {\n"
                . '    return this.request(' . self::lit($op['method']) . ', ' . $path . ", opts);\n  }\n";
        }
        return $o . "}\n\nmodule.exports = { Operations };\n";
    }

    private static function jsTypes(array $ops): string
    {
        $lines = [];
        foreach ($ops as $op) {
            $args = array_map(fn ($p) => $p . ': string | number', $op['pathParams']);
            $args[] = 'options?: RequestOptions';
            $lines[] = '  /** ' . self::doc($op) . ' */';
            $lines[] = '  ' . self::name('js', $op) . '(' . implode(', ', $args) . '): Promise<any>;';
        }
        return implode("\n", $lines);
    }

    private static function phpOps(array $ops): string
    {
        $o = "<?php\n\ndeclare(strict_types=1);\n\n// Generated from openapi.json by `php artisan developer:sdk`. Do not edit by hand.\n\nnamespace Uipe;\n\n/** One method per API operation. Used by Client. */\ntrait Operations\n{";
        foreach ($ops as $op) {
            $args = array_map(fn ($p) => '$' . $p, $op['pathParams']);
            if ($op['hasBody']) {
                $args[] = $op['bodyRequired'] ? 'array $body' : '?array $body = null';
            }
            if ($op['query']) {
                $args[] = 'array $query = []';
            }
            if ($op['pathParams']) {
                $pairs = array_map(fn ($p) => "'" . $p . "' => $" . $p, $op['pathParams']);
                $path = '$this->expand(' . self::phpStr($op['path']) . ', [' . implode(', ', $pairs) . '])';
            } else {
                $path = self::phpStr($op['path']);
            }
            $o .= "\n    /**\n     * " . self::doc($op) . "\n     * @return mixed\n     */\n    public function " . self::name('php', $op) . '(' . implode(', ', $args) . ")\n    {\n"
                . '        return $this->request(' . self::phpStr($op['method']) . ', ' . $path . ', '
                . ($op['query'] ? '$query ?: null' : 'null') . ', ' . ($op['hasBody'] ? '$body' : 'null') . ");\n    }\n";
        }
        return $o . "}\n";
    }

    private static function phpStr(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    /* ------------------------------------------------------------------ README table */

    /** @param list<array<string,mixed>> $ops */
    private static function table(string $lang, array $ops): string
    {
        $rows = ['| Call | Request | Scope |', '|---|---|---|'];
        foreach ($ops as $op) {
            $args = array_map(fn ($p) => self::param($lang, $p), $op['pathParams']);
            if ($lang === 'js') {
                $args[] = $op['hasBody'] || $op['query'] ? '{ ' . implode(', ', array_filter([$op['query'] ? 'query' : null, $op['hasBody'] ? 'body' : null])) . ' }' : '';
            } elseif ($lang === 'python') {
                if ($op['hasBody']) {
                    $args[] = 'body';
                }
                if ($op['query']) {
                    $args[] = 'query';
                }
            } else {
                $args = array_map(fn ($a) => '$' . $a, $args);
                if ($op['hasBody']) {
                    $args[] = '$body';
                }
                if ($op['query']) {
                    $args[] = '$query';
                }
            }
            $call = ($lang === 'php' ? '$client->' : 'client.') . self::name($lang, $op) . '(' . implode(', ', array_filter($args, fn ($a) => $a !== '')) . ')';
            $rows[] = '| `' . $call . '` | `' . $op['method'] . ' ' . $op['path'] . '` | ' . ($op['scope'] ? '`' . $op['scope'] . '`' : '-') . ' |';
        }
        return implode("\n", $rows);
    }

    /* ------------------------------------------------------------------ vector + files */

    /** @return array<string,string> */
    private static function vectorVars(string $lang, array $v): array
    {
        $str = $lang === 'php' ? fn (string $s) => self::phpStr($s) : fn (string $s) => self::lit($s);
        return [
            '{{V_SECRET}}' => $str((string) $v['secret']),
            '{{V_TIMESTAMP}}' => (string) (int) $v['timestamp'],
            '{{V_BODY}}' => $str((string) $v['body']),
            '{{V_SIGNATURE}}' => $str((string) $v['signature']),
            '{{V_HEADER}}' => $str((string) $v['header']),
        ];
    }

    /** @return array<string,string> مسار نسبي => مسار كامل، مرتّب */
    private static function templateFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1))] = $f->getPathname();
            }
        }
        ksort($files);
        return $files;
    }

    private static function assertResolved(string $content, string $where): void
    {
        if (preg_match('/\{\{[A-Z_]+\}\}/', $content, $m)) {
            throw new \RuntimeException("Unresolved placeholder {$m[0]} in {$where}");
        }
    }
}
