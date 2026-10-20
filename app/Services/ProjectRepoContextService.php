<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * يجمع "لقطة" حقيقية من مستودع GitHub الأساسي للمشروع (README، اللغات، هيكل
 * الملفات، ملفات الإعداد، عينة من الكود، نشاط الـ commits) عشان تحليل الـ AI
 * (الجاهزية/التصنيف/الاقتراحات/الملخص) يبني حكمه على المشروع الفعلي مش على
 * العنوان والوصف بس. نتيجة اللقطة بتتخزن في الكاش عشان الخمس نداءات المتتالية
 * في runFullAnalysis تعمل جلب واحد بس. أي فشل بيرجّع نص فاضي (مفيش اختلاق).
 */
class ProjectRepoContextService
{
    private const MAX_SAMPLE_FILES = 6;
    private const MAX_CHARS_PER_FILE = 3500;
    private const README_CHARS = 3500;
    private const CACHE_TTL = 1800; // 30 min

    private const CODE_EXT = ['php','js','jsx','ts','tsx','py','java','go','rb','rs','c','cc','cpp','h','hpp','cs','kt','swift','vue','dart','sql'];
    private const SKIP = ['vendor/','node_modules/','dist/','build/','.git/','storage/','cache/','.min.js','.map','bin/','obj/','migrations/','__pycache__/'];
    private const MANIFESTS = ['package.json','composer.json','requirements.txt','pyproject.toml','pom.xml','build.gradle','go.mod','Cargo.toml','pubspec.yaml'];

    public function snapshot(Project $project): string
    {
        $link = DB::table('project_links')->where('project_id', $project->id)->where('type', 'github')
            ->orderByDesc('is_primary')->orderByDesc('created_at')->first();
        if (!$link || !preg_match('#github\.com[/:]([\w.\-]+)/([\w.\-]+?)(?:\.git)?/?$#i', trim((string) $link->url), $m)) {
            return "Repository: NO GitHub repository linked. Judge only from the written description and say so where evidence is missing; do not penalise code-quality for code you cannot see.";
        }
        [$owner, $repo] = [$m[1], $m[2]];

        return Cache::remember("ai_repo_ctx:{$project->id}:" . md5("{$owner}/{$repo}"), self::CACHE_TTL, function () use ($project, $owner, $repo) {
            try {
                return $this->build($project, $owner, $repo);
            } catch (\Throwable $e) {
                Log::info('AI repo context unavailable', ['project_id' => $project->id, 'error' => $e->getMessage()]);
                return "Repository: {$owner}/{$repo} (could not be read right now — judge from the description only and say so).";
            }
        });
    }

    private function build(Project $project, string $owner, string $repo): string
    {
        $token = null;
        try { $token = app(GithubOAuthService::class)->tokenFor($project->owner_id); } catch (\Throwable) {}
        $token = $token ?: GithubHttp::platformToken();

        $get = function (string $path, array $q = []) use ($token, $owner, $repo) {
            try {
                return GithubHttp::json("https://api.github.com/repos/{$owner}/{$repo}{$path}" . ($q ? '?' . http_build_query($q) : ''), $token);
            } catch (\Throwable) {
                return null;
            }
        };

        $meta = $get('');
        if (!is_array($meta)) {
            return "Repository: {$owner}/{$repo} (not reachable — private or missing; judge from the description only and say so).";
        }
        $branch = (string) ($meta['default_branch'] ?? 'main');
        $langs = $get('/languages') ?: [];
        $tree = $get('/git/trees/' . rawurlencode($branch), ['recursive' => 1]);
        $entries = is_array($tree['tree'] ?? null) ? $tree['tree'] : [];
        $commits = $get('/commits', ['per_page' => 30]);

        $paths = [];
        foreach ($entries as $e) { if (($e['type'] ?? '') === 'blob') { $paths[] = ['path' => (string) $e['path'], 'size' => (int) ($e['size'] ?? 0)]; } }
        $lower = array_map(fn ($p) => strtolower($p['path']), $paths);
        $has = fn (array $needles) => (bool) array_filter($lower, fn ($p) => (bool) array_filter($needles, fn ($n) => str_contains($p, $n)));

        $total = array_sum($langs) ?: 1;
        $langStr = implode(', ', array_map(fn ($k, $v) => $k . ' ' . round($v / $total * 100) . '%', array_keys(array_slice($langs, 0, 6, true)), array_slice($langs, 0, 6, true)));

        $out = [];
        $out[] = "Repository: {$owner}/{$repo} (branch {$branch})";
        if (!empty($meta['description'])) { $out[] = 'Repo description: ' . $meta['description']; }
        $out[] = 'Languages: ' . ($langStr ?: 'n/a');
        $out[] = 'Files: ' . count($paths) . ' total; source files: ' . count(array_filter($paths, fn ($p) => in_array(strtolower(pathinfo($p['path'], PATHINFO_EXTENSION)), self::CODE_EXT, true)));
        $out[] = 'Signals: tests=' . ($has(['test', 'spec/', '__tests__']) ? 'yes' : 'no')
            . ', CI=' . ($has(['.github/workflows', '.gitlab-ci', 'azure-pipelines']) ? 'yes' : 'no')
            . ', Docker=' . ($has(['dockerfile', 'docker-compose']) ? 'yes' : 'no')
            . ', license=' . (!empty($meta['license']) || $has(['license']) ? 'yes' : 'no')
            . ', README=' . ($has(['readme']) ? 'yes' : 'no')
            . ', .env.example=' . ($has(['.env.example']) ? 'yes' : 'no')
            . ', stars=' . (int) ($meta['stargazers_count'] ?? 0)
            . ', last_push=' . substr((string) ($meta['pushed_at'] ?? ''), 0, 10);
        if (is_array($commits)) {
            $authors = array_unique(array_map(fn ($c) => (string) ($c['commit']['author']['name'] ?? ''), $commits));
            $msgs = array_slice(array_map(fn ($c) => mb_substr(strtok((string) ($c['commit']['message'] ?? ''), "\n"), 0, 80), $commits), 0, 8);
            $out[] = 'Recent commits: ' . count($commits) . '+ (latest 30 checked), contributors in them: ' . count($authors) . '. Sample messages: ' . implode(' | ', $msgs);
        }
        // directory outline (top two levels)
        $dirs = [];
        foreach ($paths as $p) { $parts = explode('/', $p['path']); if (count($parts) > 1) { $dirs[$parts[0] . (count($parts) > 2 ? '/' . $parts[1] : '')] = true; } }
        $out[] = 'Structure (dirs): ' . implode(', ', array_slice(array_keys($dirs), 0, 30));

        // README
        $readme = $get('/readme');
        if (is_array($readme) && isset($readme['content'])) {
            $txt = (string) base64_decode(str_replace("\n", '', (string) $readme['content']), true);
            $out[] = "README (first " . self::README_CHARS . " chars):\n" . mb_substr($txt, 0, self::README_CHARS);
        } else {
            $out[] = 'README: none found';
        }

        $fetch = fn (string $path) => GithubHttp::file($owner, $repo, $branch, $path, $token);

        // manifest (dependencies reveal the real stack)
        foreach ($paths as $p) {
            if (substr_count($p['path'], '/') <= 1 && in_array(basename($p['path']), self::MANIFESTS, true)) {
                $c = $fetch($p['path']);
                if ($c !== null) { $out[] = "Manifest {$p['path']}:\n" . mb_substr($c, 0, 1500); break; }
            }
        }

        // representative source sample: prefer entry points / controllers / services over tiny helpers
        $cands = array_values(array_filter($paths, function ($p) {
            $ext = strtolower(pathinfo($p['path'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::CODE_EXT, true) || $p['size'] < 400) { return false; }
            foreach (self::SKIP as $s) { if (str_contains($p['path'], $s)) { return false; } }
            return true;
        }));
        $score = function ($p) {
            $l = strtolower($p['path']); $s = 0;
            foreach (['program.cs','startup','main.','app.','index.','server.','controller','service','route','model','repository'] as $k) { if (str_contains($l, $k)) { $s += 2; } }
            return $s * 10000 + min($p['size'], 9000);
        };
        usort($cands, fn ($a, $b) => $score($b) <=> $score($a));
        $n = 0;
        foreach ($cands as $p) {
            if ($n >= self::MAX_SAMPLE_FILES) { break; }
            $c = $fetch($p['path']);
            if ($c === null) { continue; }
            $out[] = "=== Code sample: {$p['path']} ===\n" . mb_substr($c, 0, self::MAX_CHARS_PER_FILE);
            $n++;
        }

        return implode("\n", $out);
    }

    /** أحدث مراجعة كود مكتملة (درجات + أهم الملاحظات بملف/سطر + نقاط القوة) كدليل إضافي للـ AI. */
    public function codeReviewEvidence(Project $project): string
    {
        try {
            $r = DB::table('ai_code_review_results')->where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first();
            if (!$r) {
                return '';
            }
            $res = json_decode((string) ($r->raw_result ?? ''), true) ?: [];
            $out = ["--- LATEST CODE REVIEW (v{$r->version}; scores 0-100) ---"];
            $out[] = 'Scores: overall=' . ($r->overall_score ?? 'n/a') . ', security=' . ($r->security_score ?? 'n/a') . ', performance=' . ($r->performance_score ?? 'n/a')
                . ', maintainability=' . ($r->maintainability_score ?? 'n/a') . ', architecture=' . ($r->architecture_score ?? 'n/a') . ', quality=' . ($r->quality_score ?? 'n/a');
            if (!empty($r->summary)) {
                $out[] = 'Review summary: ' . mb_substr((string) $r->summary, 0, 600);
            }
            $issues = DB::table('ai_code_review_issues')->where('review_id', $r->id)
                ->orderByRaw("FIELD(severity, 'critical','high','medium','low','info')")->limit(12)->get();
            foreach ($issues as $i) {
                $out[] = "- [{$i->severity}/{$i->category}] {$i->file_name}:{$i->line_number} " . mb_substr((string) $i->description, 0, 220);
            }
            foreach (array_slice((array) ($res['strengths'] ?? []), 0, 5) as $st) {
                $out[] = '+ Strength: ' . mb_substr((string) ($st['text'] ?? ''), 0, 200);
            }
            if (!empty($res['structure']['summary'])) {
                $out[] = 'Structure: ' . mb_substr((string) $res['structure']['summary'], 0, 300);
            }
            $out[] = 'Use these verified findings as ground truth; do not contradict them.';
            return implode("\n", $out);
        } catch (\Throwable) {
            return '';
        }
    }
}
