<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\AiCodeReviewIssueRepository;
use App\Repositories\AiCodeReviewRepository;
use App\Repositories\GithubRepositoryRepository;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/GithubCodeReviewService.php القديمة — بند 13
 * (GitHub Integration)، ومدّت Phase 45 (Admin Dashboard rebuild).
 *
 * تكامل حقيقي مع GitHub REST API العام (من غير توكن للريبوز العامة؛
 * GITHUB_TOKEN اختياري لو موجود بيرفع حد المعدل) — بيجيب meta حية للريبو
 * + محتوى ملفات حقيقي من شجرة الريبو، وبيبعتها لـ AIClient لمراجعة كود
 * حقيقية.
 *
 * ترتيب fail-safe، الأفضل أولاً:
 *   1. AIClient معدّ + النداء نجح -> تحليل AI كامل بالستة سكورات، issues
 *      مفصّلة تتخزن في ai_code_review_issues.
 *   2. AIClient مش معدّ، أو النداء فشل -> heuristicReview() لسه بتشتغل
 *      (heuristic حقيقي على بيانات الريبو، أبدًا مش مختلَق)، بتملى
 *      overall_score بس (باقي الخمس أعمدة تفضل NULL — مالهاش مصدر أمين
 *      من غير خطوة الـ AI)، و raw_result بيسجل أي مسار اتستخدم عشان
 *      الواجهة تقدر تفصح عنه.
 * في الحالتين ده أبدًا مايختلقش سكور 6-أبعاد من بيانات ملهاش القدرة
 * فعلًا تدعمه.
 */
class GithubCodeReviewService
{
    use ConfiguresAIClient;

    /** ميزانية اختيار الملفات لخطوة تحليل الـ AI — مفصح عنها في الواجهة، مش حد مخفي. */
    private const MAX_FILES = 20;
    private const MAX_TOTAL_BYTES = 70_000;
    private const MAX_CHARS_PER_FILE = 8_000;
    private const MAX_ISSUES_STORED = 60;

    private const CODE_EXTENSIONS = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'py', 'java', 'go', 'rb', 'rs',
        'c', 'cc', 'cpp', 'h', 'hpp', 'cs', 'kt', 'swift', 'vue',
    ];

    private const EXCLUDED_PATH_PARTS = [
        'vendor/', 'node_modules/', 'dist/', 'build/', '.git/', 'storage/',
        'cache/', 'public/assets/', '.min.js', '.lock', 'package-lock.json',
        'composer.lock', '.map',
    ];

    private AIClient $aiClient;
    private ?string $githubToken;

    public function __construct(
        private GithubRepositoryRepository $repos,
        private AiCodeReviewRepository $reviews,
        private AiCodeReviewIssueRepository $issuesRepo,
        private ProjectRepository $projects,
        private ProjectLinkRepository $links,
        AIClient $aiClient,
        SettingRepository $settings
    ) {
        $this->aiClient = $aiClient;
        $this->applySettingsOverrides($this->aiClient, $settings);
        $this->githubToken = GithubHttp::platformToken();
    }

    public function latestReview($projectId): ?array
    {
        $review = $this->reviews->latestForProject($projectId);
        if (!$review) {
            return null;
        }
        return $this->reviewToArray($review);
    }

    /** @return array<string,mixed> */
    private function reviewToArray(\App\Models\AiCodeReviewResult $review): array
    {
        return [
            'id'                 => (int) $review->id,
            'status'             => $review->status,
            'issues_found'       => (int) $review->issues_found,
            'summary'            => $review->summary,
            'result'             => $review->decodedResult(),
            'created_at'         => $review->created_at,
            'scores'             => $review->scores(),
            'version'            => (int) $review->version,
            'previous_review_id' => $review->previous_review_id !== null ? (int) $review->previous_review_id : null,
            'reviewed_by'        => $review->reviewed_by !== null ? (int) $review->reviewed_by : null,
            'approved_at'        => $review->approved_at,
            'admin_notes'        => $review->admin_notes,
            'score_overridden'   => (bool) $review->score_overridden,
            'override_reason'    => $review->override_reason,
            'issues'             => array_map(
                fn ($i) => $i->toArray(),
                $this->issuesRepo->forReview((int) $review->id)
            ),
        ];
    }

    /**
     * تشغيل (أو إعادة تشغيل) مراجعة على لينك GitHub المربوط بمشروع
     * (project_links, type=github)، مقصورة على مالك معين (مسار الطالب/
     * الباحث الذاتي). دايمًا بتكتب صف (status completed|failed) — أبدًا
     * ماتسكتش بصمت.
     * @throws \RuntimeException لو المشروع مالوش لينك GitHub، أو مش مملوك لـ $ownerId
     */
    public function run(string $projectUuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        return $this->executeReview($project);
    }

    /**
     * إعادة تشغيل بسياق أدمن: نفس pipeline التحليل بتاع run()، لكن بتحل
     * المشروع من غير فحص ملكية (findByUuid، مش findOwnedByUuid) لأن
     * الأدمن اللي بيعيد تشغيل مراجعة من Admin > AI Code Review مش لازم
     * يكون مالك المشروع. run() نفسها مقصود تفضل من غير تعديل، عشان فحص
     * الملكية بتاع الطالب أبدًا مايضعفش.
     * @throws \RuntimeException لو المشروع مش موجود أو مالوش لينك GitHub
     */
    public function runAsAdmin(string $projectUuid, $adminId): array
    {
        $project = $this->projects->findByUuid($projectUuid);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }
        Log::info('Admin triggered AI code review re-run', ['project_id' => $project->id, 'admin_id' => $adminId]);
        return $this->executeReview($project);
    }

    /** Pipeline مشترك لـ run()/runAsAdmin() بعد ما مسألة المشروع/الملكية اتحلت. */
    private function executeReview(Project $project): array
    {
        // لو صاحب المشروع ربط GitHub بتاعه → المراجعة بتشتغل بتوكنه (مستودعات خاصة + حد معدل أعلى)،
        // وبنرجّع التوكن الأصلي بعد المراجعة عشان مايتسربش لمراجعة مشروع تاني.
        $previousToken = $this->githubToken;
        $ownerToken = app(\App\Services\GithubOAuthService::class)->tokenFor($project->owner_id);
        if ($ownerToken) {
            $this->githubToken = $ownerToken;
        }
        try {
            return $this->executeReviewInner($project);
        } finally {
            $this->githubToken = $previousToken;
        }
    }

    private function executeReviewInner(Project $project): array
    {
        $link = $this->links->primaryByType($project->id, 'github');
        if (!$link) {
            throw new \RuntimeException('Add a GitHub link to this project first (Project Links section).');
        }
        $repositoryUrl = $link->url;

        $parsed = $this->parseRepoUrl($repositoryUrl);
        if (!$parsed) {
            throw new \RuntimeException('That does not look like a valid GitHub repository URL.');
        }
        [$owner, $repo] = $parsed;

        $repoRow = $this->repos->upsertForProject($project->id, [
            'repo_url'    => $repositoryUrl,
            'sync_status' => 'connected',
        ]);

        $previous = $this->reviews->latestForProject($project->id);
        $nextVersion = $previous ? ((int) $previous->version + 1) : 1;

        try {
            $meta = $this->fetchJson("https://api.github.com/repos/{$owner}/{$repo}");
            $languages = $this->fetchJson("https://api.github.com/repos/{$owner}/{$repo}/languages") ?? [];
            $rootFiles = $this->fetchJson("https://api.github.com/repos/{$owner}/{$repo}/contents") ?? [];
        } catch (\RuntimeException $e) {
            $this->repos->upsertForProject($project->id, ['sync_status' => 'error']);

            $this->reviews->create([
                'project_id'         => $project->id,
                'repository_id'      => $repoRow->id,
                'status'             => 'failed',
                'issues_found'       => 0,
                'summary'            => $e->getMessage(),
                'raw_result'         => json_encode(['error' => $e->getMessage()]),
                'is_demo_data'       => 0,
                'version'            => $nextVersion,
                'previous_review_id' => $previous?->id,
            ]);

            Log::warning('GitHub code review failed', ['project_id' => $project->id, 'error' => $e->getMessage()]);

            return $this->latestReview($project->id);
        }

        $this->repos->upsertForProject($project->id, [
            'default_branch' => $meta['default_branch'] ?? null,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'sync_status'    => 'connected',
        ]);

        $branch = (string) ($meta['default_branch'] ?? 'main');
        $heuristic = $this->heuristicReview($meta, $languages, $rootFiles);

        $aiResult = null;
        $analysisMethod = 'heuristic';
        if ($this->aiClient->isConfigured()) {
            try {
                $aiResult = $this->runAiCodeAnalysis($owner, $repo, $branch);
                $analysisMethod = 'ai';
            } catch (\RuntimeException $e) {
                Log::warning('AI code analysis failed, falling back to heuristic', [
                    'project_id' => $project->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $scores = [
            'overall_score'         => $aiResult['overall_score'] ?? $heuristic['score'],
            'security_score'        => $aiResult['security_score'] ?? null,
            'performance_score'     => $aiResult['performance_score'] ?? null,
            'maintainability_score' => $aiResult['maintainability_score'] ?? null,
            'architecture_score'    => $aiResult['architecture_score'] ?? null,
            'quality_score'         => $aiResult['quality_score'] ?? null,
        ];

        $issues = $aiResult['issues'] ?? [];
        $issuesCount = $aiResult ? count($issues) : count($heuristic['issues']);

        $summary = $aiResult['summary']
            ?? ('[Heuristic fallback — AI analysis unavailable] ' . $heuristic['summary']);

        $rawResult = [
            'method'           => $analysisMethod,
            'language_mix'     => $heuristic['language_mix'],
            'stars'            => $heuristic['stars'],
            'forks'            => $heuristic['forks'],
            'open_issues'      => $heuristic['open_issues'],
            'size_kb'          => $heuristic['size_kb'],
            'default_branch'   => $heuristic['default_branch'],
            'files_analyzed'   => $aiResult['files_analyzed'] ?? [],
            'strengths'        => $aiResult['strengths'] ?? [],
            'structure'        => $aiResult['structure'] ?? null,
            'snippets'         => $aiResult['snippets'] ?? [],
            'heuristic_issues' => $analysisMethod === 'heuristic' ? $heuristic['issues'] : [],
        ];

        $created = $this->reviews->create([
            'project_id'            => $project->id,
            'repository_id'         => $repoRow->id,
            'status'                => 'completed',
            'issues_found'          => $issuesCount,
            'summary'               => $summary,
            'raw_result'            => json_encode($rawResult, JSON_UNESCAPED_UNICODE),
            'is_demo_data'          => 0,
            'overall_score'         => $scores['overall_score'],
            'security_score'        => $scores['security_score'],
            'performance_score'     => $scores['performance_score'],
            'maintainability_score' => $scores['maintainability_score'],
            'architecture_score'    => $scores['architecture_score'],
            'quality_score'         => $scores['quality_score'],
            'version'               => $nextVersion,
            'previous_review_id'    => $previous?->id,
        ]);

        if ($analysisMethod === 'ai' && !empty($issues)) {
            $this->issuesRepo->createMany((int) $created->id, array_slice($issues, 0, self::MAX_ISSUES_STORED));
        }

        Log::info('GitHub code review completed', [
            'project_id' => $project->id,
            'repo'       => "{$owner}/{$repo}",
            'method'     => $analysisMethod,
        ]);

        return $this->latestReview($project->id);
    }

    /**
     * كل مشاريع الطالب/الباحث الخاصة مع لينك GitHub + حالة آخر مراجعة،
     * صف واحد لكل مشروع — تخدم صفحة GitHub Integration hub
     * (/student/github). بيانات حقيقية بس: مشروع من غير صف project_links
     * من نوع github بيظهر ببساطة كـ not-connected، أبدًا مش صف مختلَق.
     * repository_url بيتحل بواسطة GithubRepositoryRepository::forOwner()
     * من project_links، مش عمود projects.repository_url القديم.
     * @return array<int,array<string,mixed>>
     */
    public function forOwner($ownerId): array
    {
        return array_map(function ($row) {
            return [
                'project_id'         => $row['project_id'],
                'project_uuid'       => $row['project_uuid'],
                'title_en'           => $row['title_en'],
                'title_ar'           => $row['title_ar'],
                'cover_image_path'   => $row['cover_image_path'] ?? null,
                'live_demo_url'      => $row['live_demo_url'] ?? null,
                'repository_url'     => $row['repository_url'],
                'is_connected'       => !empty($row['repository_url']),
                'sync_status'        => $row['sync_status'] ?? ($row['repository_url'] ? 'not_connected' : null),
                'default_branch'     => $row['default_branch'],
                'last_synced_at'     => $row['last_synced_at'],
                'review_status'      => $row['review_status'],
                'issues_found'       => $row['issues_found'] !== null ? (int) $row['issues_found'] : null,
                'review_summary'     => $row['review_summary'],
                'review_created_at'  => $row['review_created_at'],
            ];
        }, $this->repos->forOwner($ownerId));
    }

    // -- تحليل AI حقيقي للكود (Phase 45) --------------------------------

    /**
     * خطوة "اقرأ كود حقيقي واحكم عليه" الفعلية: بتسحب شجرة ملفات الريبو،
     * بتختار مجموعة محدودة ومفصح عنها من ملفات المصدر الحقيقية، بتبعت
     * محتواها الحقيقي للموديل المُعد، وبتفكّ verdict مُهيكل بستة سكورات +
     * issue-بـ-issue.
     * @return array{overall_score:int,security_score:int,performance_score:int,
     *               maintainability_score:int,architecture_score:int,quality_score:int,
     *               summary:string,issues:array<int,array<string,mixed>>,files_analyzed:array<int,string>}
     * @throws \RuntimeException لو فشل سحب الشجرة/المحتوى، مافيش ملفات قابلة للتحليل، أو فشل نداء/تفكيك الـ AI
     */
    private function runAiCodeAnalysis(string $owner, string $repo, string $branch): array
    {
        $tree = $this->fetchJson("https://api.github.com/repos/{$owner}/{$repo}/git/trees/" . rawurlencode($branch) . '?recursive=1');
        $entries = is_array($tree['tree'] ?? null) ? $tree['tree'] : [];

        $selected = $this->selectFilesForAnalysis($entries);
        if (empty($selected)) {
            throw new \RuntimeException('No analyzable source files found in this repository (checked extensions: ' . implode(', ', self::CODE_EXTENSIONS) . ').');
        }

        $files = [];
        foreach ($selected as $entry) {
            $content = $this->fetchFileContent($owner, $repo, $entry['path'], $branch);
            if ($content === null) {
                continue;
            }
            $files[$entry['path']] = mb_substr($content, 0, self::MAX_CHARS_PER_FILE);
        }
        if (empty($files)) {
            throw new \RuntimeException('Could not fetch content for any selected file.');
        }

        [$system, $user] = $this->buildAnalysisPrompt($files);
        $data = $this->completeJson($this->aiClient, $system, $user);

        $clamp = fn ($v) => max(0, min(100, (int) round((float) ($v ?? 0))));

        $issues = is_array($data['issues'] ?? null) ? $data['issues'] : [];
        $normalizedIssues = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || empty($issue['description'] ?? $issue['message'] ?? null)) {
                continue;
            }
            $normalizedIssues[] = $issue;
        }

        // مقتطفات الكود الحقيقية (±3 سطور حوالين السطر المُبلَّغ عنه) من الملف اللي اتجاب فعلًا — مش من تأليف الـ AI.
        $snippets = [];
        foreach ($normalizedIssues as $issue) {
            $file = (string) ($issue['file'] ?? '');
            $line = isset($issue['line']) && is_numeric($issue['line']) ? (int) $issue['line'] : 0;
            if ($file === '' || $line < 1 || !isset($files[$file])) {
                continue;
            }
            $all = preg_split('/\R/', $files[$file]);
            if ($line > count($all)) {
                continue;
            }
            $start = max(1, $line - 3);
            $end = min(count($all), $line + 3);
            $out = [];
            for ($i = $start; $i <= $end; $i++) {
                $out[] = str_pad((string) $i, 4, ' ', STR_PAD_LEFT) . '| ' . mb_substr($all[$i - 1], 0, 220);
            }
            $snippets[$file . '|' . $line] = ['start' => $start, 'focus' => $line, 'code' => implode("\n", $out)];
        }

        $strengths = [];
        foreach ((array) ($data['strengths'] ?? []) as $st) {
            if (is_array($st) && !empty($st['text'])) {
                $strengths[] = ['file' => (string) ($st['file'] ?? ''), 'text' => mb_substr((string) $st['text'], 0, 400)];
            }
        }
        $layers = [];
        foreach ((array) ($data['structure']['layers'] ?? []) as $ly) {
            if (is_array($ly) && !empty($ly['name'])) {
                $assess = strtolower((string) ($ly['assessment'] ?? 'ok'));
                $layers[] = [
                    'name'       => mb_substr((string) $ly['name'], 0, 80),
                    'files'      => array_values(array_filter(array_map('strval', (array) ($ly['files'] ?? [])), fn ($f) => isset($files[$f]))),
                    'purpose'    => mb_substr((string) ($ly['purpose'] ?? ''), 0, 200),
                    'assessment' => in_array($assess, ['good', 'ok', 'weak'], true) ? $assess : 'ok',
                    'note'       => mb_substr((string) ($ly['note'] ?? ''), 0, 250),
                ];
            }
        }

        return [
            'strengths'             => array_slice($strengths, 0, 8),
            'structure'             => ['summary' => mb_substr((string) ($data['structure']['summary'] ?? ''), 0, 500), 'layers' => array_slice($layers, 0, 10)],
            'snippets'              => $snippets,
            'overall_score'         => $clamp($data['overall_score'] ?? null),
            'security_score'        => $clamp($data['security_score'] ?? null),
            'performance_score'     => $clamp($data['performance_score'] ?? null),
            'maintainability_score' => $clamp($data['maintainability_score'] ?? null),
            'architecture_score'    => $clamp($data['architecture_score'] ?? null),
            'quality_score'         => $clamp($data['quality_score'] ?? null),
            'summary'               => is_string($data['summary'] ?? null) && trim($data['summary']) !== ''
                ? trim($data['summary'])
                : ('AI analysis of ' . count($files) . ' file(s) from ' . "{$owner}/{$repo}."),
            'issues'                => $normalizedIssues,
            'files_analyzed'        => array_keys($files),
        ];
    }

    /**
     * heuristic اختيار الملفات المفصح عنه: ملفات مصدر حقيقية بس (امتدادات
     * مسموحة)، مجلدات مش-مصدر شائعة مستبعدة (vendor/ node_modules/ مخرجات
     * build/ lockfiles/ إلخ)، مرتبة الأصغر-أولاً عشان ميزانية البايت/
     * الملف الثابتة تغطي أكبر عدد ملفات مميزة بدل ما تتستهلك في ملف أو
     * اتنين كبار. ده بيختار أنهي ملفات هتتحلل — أبدًا مابيسكورهاش بنفسه
     * (ده شغل موديل الـ AI في runAiCodeAnalysis()).
     * @param array<int,array<string,mixed>> $entries عناصر شجرة GitHub خام
     * @return array<int,array{path:string,size:int}>
     */
    private function selectFilesForAnalysis(array $entries): array
    {
        $candidates = [];
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = (string) ($entry['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, self::CODE_EXTENSIONS, true)) {
                continue;
            }
            $skip = false;
            foreach (self::EXCLUDED_PATH_PARTS as $excluded) {
                if (str_contains($path, $excluded)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $candidates[] = ['path' => $path, 'size' => (int) ($entry['size'] ?? 0)];
        }

        // الأهم الأول (نقاط دخول/كنترولرز/خدمات/موديلات/أمان/اختبارات)، وبعدين الأصغر — بدل "الأصغر فقط" اللي كان بيختار
        // ملفات تافهة ويسيب قلب المشروع. الملفات الأصغر من 120 بايت (غالبًا فاضية/تجميعية) بتتأجل لآخر الطابور.
        $weight = function (string $path): int {
            $l = strtolower($path);
            $w = 0;
            foreach (['program.', 'startup', 'main.', 'app.', 'server.', 'index.', 'controller', 'service', 'repository', 'handler', 'route', 'auth', 'login', 'middleware', 'model', 'entity', 'dbcontext', 'config', 'upload', 'security'] as $k) {
                if (str_contains($l, $k)) {
                    $w += 3;
                }
            }
            foreach (['test', 'spec'] as $k) {
                if (str_contains($l, $k)) {
                    $w += 2;
                }
            }
            return $w;
        };
        usort($candidates, function ($a, $b) use ($weight) {
            $tiny = ($a['size'] < 120) <=> ($b['size'] < 120);
            if ($tiny !== 0) {
                return $tiny;
            }
            $w = $weight($b['path']) <=> $weight($a['path']);
            return $w !== 0 ? $w : ($a['size'] <=> $b['size']);
        });

        $selected = [];
        $totalBytes = 0;
        foreach ($candidates as $candidate) {
            if (count($selected) >= self::MAX_FILES) {
                break;
            }
            if ($totalBytes + $candidate['size'] > self::MAX_TOTAL_BYTES && !empty($selected)) {
                continue;
            }
            $selected[] = $candidate;
            $totalBytes += $candidate['size'];
        }

        return $selected;
    }

    /** @return array{0:string,1:string} [system, user] prompts لـ completeJson() */
    private function buildAnalysisPrompt(array $files): array
    {
        $system = 'You are a senior software engineer performing a real code review for the Ultimate Intelligent Platform (UIP), a university projects platform. '
            . 'You will be given the actual contents of real source files from a student project repository. '
            . 'Judge the ACTUAL code shown — do not invent findings about files you were not given. '
            . 'Score the codebase 0-100 on each of six independent dimensions: '
            . 'overall_score (holistic judgment), security_score (injection risks, secrets in code, unsafe deserialization, auth flaws...), '
            . 'performance_score (obvious inefficiencies, N+1 patterns, blocking calls...), '
            . 'maintainability_score (readability, duplication, naming, function size...), '
            . 'architecture_score (separation of concerns, coupling, structure...), '
            . 'quality_score (error handling, edge cases, test presence/signals in the code shown...). '
            . 'Be honest and specific: if the code shown is genuinely clean, say so and use few or no issues — never invent filler findings to pad the list. '
            . 'For every real issue you find, report: file (exact path as given), line (best estimate, integer), '
            . 'category (one of: security, performance, maintainability, architecture, quality, general), '
            . 'severity (one of: critical, high, medium, low, info), description (what is wrong, specific to the code shown), '
            . 'recommendation (what to do about it), suggested_fix (a short concrete fix, code-level where useful). '
            . 'Each file is shown with line numbers in the form "  12| code": use EXACTLY those numbers for the "line" field (the first line of the problem). '
            . 'Also report what is done WELL and how the code is organised: '
            . '"strengths" = 3-6 items {file, text} naming specific good practices you actually see (e.g. parameterised queries, input validation, clear layering, DI, error handling, tests); '
            . '"structure" = {"summary": 1-2 sentences on the architecture, "layers": [{"name": layer/module (e.g. Controllers, Services, Models, Views, Data access), "files": [exact paths from the files given], '
            . '"purpose": short, "assessment": one of good|ok|weak, "note": short reason}]}. '
            . 'Respond as JSON: {"overall_score":number,"security_score":number,"performance_score":number,'
            . '"maintainability_score":number,"architecture_score":number,"quality_score":number,"summary":"3-5 sentence overview covering correctness, structure and the main risks",'
            . '"strengths":[{"file":"...","text":"..."}],"structure":{"summary":"...","layers":[{"name":"...","files":["..."],"purpose":"...","assessment":"good|ok|weak","note":"..."}]},'
            . '"issues":[{"file":"...","line":number,"category":"...","severity":"...","description":"...","recommendation":"...","suggested_fix":"..."}]}';

        // لغة المنصة (X-Locale → UipLocaleMiddleware → app()->getLocale()): لو عربي، النص الحر بالعربي.
        if (app()->getLocale() === 'ar') {
            $system .= ' IMPORTANT — LANGUAGE: write every human-readable text value (summary, strengths text, structure summary/purpose/note, description, recommendation, and any explanatory text in suggested_fix) in clear Modern Standard Arabic. '
                . 'Keep the JSON keys, the enum values of category and severity (security, performance, ..., critical, high, ...), file paths, '
                . 'identifiers, API/class/method names, and code snippets exactly as they are in English/original form — do not translate them. '
                . 'Code inside suggested_fix stays as code; only add short Arabic explanation around it if needed.';
        }

        $parts = [];
        foreach ($files as $path => $content) {
            $numbered = [];
            foreach (preg_split('/\R/', $content) as $n => $line) {
                $numbered[] = str_pad((string) ($n + 1), 4, ' ', STR_PAD_LEFT) . '| ' . $line;
            }
            $parts[] = "=== File: {$path} ===\n" . implode("\n", $numbered);
        }
        $user = "Review the following " . count($files) . " file(s):\n\n" . implode("\n\n", $parts);

        return [$system, $user];
    }

    /**
     * heuristic شفاف، مفصح عنه: مش "AI score" صندوق أسود — كل نقطة
     * بتتخصم/بتتضاف مذكورة في $issues عشان الطالب يشوف بالظبط ليه. باقية
     * كـ fallback أمين لما خطوة الـ AI فوق مش متاحة أو فشلت — فحوصات بسيطة
     * ومقصودة على إشارات ريبو حقيقية، زي ما كانت قبل Phase 45.
     */
    private function heuristicReview(array $meta, array $languages, array $rootFiles): array
    {
        $score = 100;
        $issues = [];
        $rootNames = array_map(fn ($f) => strtolower($f['name'] ?? ''), $rootFiles);

        $hasReadme = (bool) array_filter($rootNames, fn ($n) => str_starts_with($n, 'readme'));
        if (!$hasReadme) {
            $score -= 20;
            $issues[] = ['severity' => 'high', 'message_en' => 'No README file at the repo root.', 'message_ar' => 'لا يوجد ملف README في جذر المستودع.'];
        }

        $hasLicense = (bool) array_filter($rootNames, fn ($n) => str_starts_with($n, 'license') || str_starts_with($n, 'licence'));
        if (!$hasLicense) {
            $score -= 5;
            $issues[] = ['severity' => 'low', 'message_en' => 'No LICENSE file — unclear how others may reuse this code.', 'message_ar' => 'لا يوجد ملف LICENSE — غير واضح كيف يمكن لغيرك إعادة استخدام الكود.'];
        }

        $hasTests = (bool) array_filter($rootNames, fn ($n) => in_array($n, ['test', 'tests', '__tests__', 'spec'], true));
        if (!$hasTests) {
            $score -= 15;
            $issues[] = ['severity' => 'medium', 'message_en' => 'No tests/ directory found at the repo root.', 'message_ar' => 'لا يوجد مجلد اختبارات (tests) في جذر المستودع.'];
        }

        $hasCi = (bool) array_filter($rootNames, fn ($n) => in_array($n, ['.github', '.gitlab-ci.yml', '.travis.yml'], true));
        if (!$hasCi) {
            $score -= 10;
            $issues[] = ['severity' => 'medium', 'message_en' => 'No CI configuration found (e.g. GitHub Actions).', 'message_ar' => 'لا يوجد إعداد تكامل مستمر (CI) مثل GitHub Actions.'];
        }

        $hasGitignore = in_array('.gitignore', $rootNames, true);
        if (!$hasGitignore) {
            $score -= 5;
            $issues[] = ['severity' => 'low', 'message_en' => 'No .gitignore file.', 'message_ar' => 'لا يوجد ملف .gitignore.'];
        }

        if (empty($meta['description'])) {
            $score -= 5;
            $issues[] = ['severity' => 'low', 'message_en' => 'The repository has no description set.', 'message_ar' => 'المستودع بدون وصف (description).'];
        }

        if (($meta['open_issues_count'] ?? 0) > 20) {
            $score -= 5;
            $issues[] = ['severity' => 'low', 'message_en' => 'A high number of open issues (' . $meta['open_issues_count'] . ').', 'message_ar' => 'عدد كبير من المشاكل المفتوحة (' . $meta['open_issues_count'] . ').'];
        }

        $score = max(0, min(100, $score));

        $totalBytes = array_sum($languages) ?: 1;
        $languageMix = [];
        arsort($languages);
        foreach (array_slice($languages, 0, 5) as $lang => $bytes) {
            $languageMix[$lang] = round($bytes / $totalBytes * 100, 1);
        }

        return [
            'score'          => $score,
            'summary'        => "Analyzed {$meta['full_name']}: " . count($issues) . ' item(s) flagged out of 7 checks — heuristic score ' . $score . '/100.',
            'language_mix'   => $languageMix,
            'stars'          => $meta['stargazers_count'] ?? 0,
            'forks'          => $meta['forks_count'] ?? 0,
            'open_issues'    => $meta['open_issues_count'] ?? 0,
            'size_kb'        => $meta['size'] ?? 0,
            'default_branch' => $meta['default_branch'] ?? null,
            'issues'         => $issues,
        ];
    }

    /** @return array{0:string,1:string}|null [owner, repo] */
    private function parseRepoUrl(string $url): ?array
    {
        if (!preg_match('#github\.com[/:]([\w.\-]+)/([\w.\-]+?)(?:\.git)?/?$#i', trim($url), $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /** محتوى ملف واحد: raw.githubusercontent.com للعام (من غير استهلاك حد الـ API)، وcontents API بالتوكن للخاص. null عند الفشل. */
    private function fetchFileContent(string $owner, string $repo, string $path, string $branch): ?string
    {
        return GithubHttp::file($owner, $repo, $branch, $path, $this->githubToken);
    }

    /** @throws \RuntimeException عند أي رد غير 2xx أو فشل شبكة أو تجاوز حد المعدل */
    private function fetchJson(string $url): ?array
    {
        return GithubHttp::json($url, $this->githubToken);
    }
}
