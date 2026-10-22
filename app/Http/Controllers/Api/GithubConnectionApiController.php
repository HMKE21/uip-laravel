<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectLink;
use App\Repositories\ProjectLinkRepository;
use App\Repositories\ProjectRepository;
use App\Services\GithubOAuthService;
use App\Services\ProjectPublishingService;
use Illuminate\Http\Request;

/**
 * Connect GitHub (OAuth) — الطالب يربط حسابه، المنصة تجيب مستودعاته (العامة والخاصة)،
 * ويربط أي مستودع بمشروع موجود أو يعمل منه مشروع جديد (مسودة).
 *
 *   GET    /api/v1/github/status            حالة الربط
 *   POST   /api/v1/github/connect           → { url } صفحة موافقة GitHub
 *   GET    /api/v1/github/callback          (عام) GitHub بيرجّع هنا → redirect للواجهة
 *   GET    /api/v1/github/repos             مستودعاتي + المشروع المربوط بكل واحد
 *   POST   /api/v1/github/link              { full_name, project_uuid }
 *   POST   /api/v1/github/create-project    { full_name }
 *   DELETE /api/v1/github/connection        فك الربط
 */
class GithubConnectionApiController extends Controller
{
    public function __construct(
        private GithubOAuthService $github,
        private ProjectRepository $projects,
        private ProjectLinkRepository $links,
        private ProjectPublishingService $publishing
    ) {
    }

    public function status(Request $request)
    {
        $conn = $this->github->connectionFor($this->userId($request));
        return $this->apiSuccess([
            'configured' => $this->github->isConfigured(),
            'connected'  => (bool) $conn && $conn->token() !== null,
            'login'      => $conn?->github_login,
            'avatar_url' => $conn?->avatar_url,
            'scope'      => $conn?->scope,
        ], 'GitHub connection status retrieved successfully.');
    }

    public function connect(Request $request)
    {
        if ($denied = $this->studentOnly($request)) {
            return $denied;
        }
        if (!$this->github->isConfigured()) {
            return $this->apiError('GitHub connection is not configured on this platform yet.', null, 503);
        }
        return $this->apiSuccess(['url' => $this->github->authorizeUrl($this->userId($request))], 'GitHub authorization URL created.');
    }

    /** عام (من غير Authorization header) — الهوية جاية من الـ state المحفوظ في الكاش. */
    public function callback(Request $request)
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/') . '/student/github';

        if ($request->query('error')) { // الطالب ضغط Cancel في GitHub
            return redirect()->away($base . '?github=error&reason=denied');
        }

        try {
            $this->github->completeCallback((string) $request->query('code', ''), (string) $request->query('state', ''));
        } catch (\RuntimeException $e) {
            $reason = in_array($e->getMessage(), ['expired_state', 'token_exchange_failed', 'profile_failed'], true) ? $e->getMessage() : 'failed';
            return redirect()->away($base . '?github=error&reason=' . $reason);
        } catch (\Throwable $e) {
            return redirect()->away($base . '?github=error&reason=failed');
        }

        return redirect()->away($base . '?github=connected');
    }

    public function repos(Request $request)
    {
        $userId = $this->userId($request);
        $conn = $this->github->connectionFor($userId);
        if (!$conn) {
            return $this->apiError('GitHub is not connected.', null, 409);
        }

        try {
            $repos = $this->github->repositories($conn);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'unauthorized') { // الطالب سحب الصلاحية من GitHub
                $conn->delete();
                return $this->apiError('GitHub access was revoked. Please connect again.', null, 409);
            }
            return $this->apiError('Could not reach GitHub right now. Please try again.', null, 502);
        }

        // المشروع المربوط بكل مستودع (لو موجود) — مقارنة بالـ canonical URL.
        $linked = [];
        foreach ($this->ownerLinks($userId) as $row) {
            $linked[$row['key']] = $row;
        }
        foreach ($repos as &$r) {
            $hit = $linked[strtolower($r['full_name'])] ?? null;
            $r['linked_project_uuid'] = $hit['uuid'] ?? null;
            $r['linked_project_title'] = $hit['title'] ?? null;
        }
        unset($r);

        return $this->apiSuccess($repos, 'GitHub repositories retrieved successfully.');
    }

    public function linkToProject(Request $request)
    {
        if ($denied = $this->studentOnly($request)) {
            return $denied;
        }
        $userId = $this->userId($request);
        $project = $this->projects->findOwnedByUuid((string) $request->input('project_uuid'), $userId);
        if (!$project) {
            return $this->apiError('Project not found.', null, 404);
        }
        $repo = $this->resolveRepo($request, $userId);
        if ($repo instanceof \Illuminate\Http\JsonResponse) {
            return $repo;
        }
        $url = $this->github->canonicalUrl($repo['full_name']);
        if (!$url) {
            return $this->apiError('This repository cannot be linked.', null, 422);
        }

        $existing = $this->links->primaryByType($project->id, 'github');
        if ($existing) {
            $existing->fill(['url' => $url, 'is_primary' => 1])->save();
            $this->links->clearPrimaryExcept($project->id, $existing->id);
        } else {
            $link = $this->links->create(['project_id' => $project->id, 'type' => 'github', 'url' => $url, 'is_primary' => 1]);
            $this->links->clearPrimaryExcept($project->id, $link->id);
        }

        return $this->apiSuccess(['project_uuid' => $project->uuid, 'repository_url' => $url], 'Repository linked successfully.');
    }

    public function createProject(Request $request)
    {
        if ($denied = $this->studentOnly($request)) {
            return $denied;
        }
        $userId = $this->userId($request);
        $repo = $this->resolveRepo($request, $userId);
        if ($repo instanceof \Illuminate\Http\JsonResponse) {
            return $repo;
        }
        $url = $this->github->canonicalUrl($repo['full_name']);
        if (!$url) {
            return $this->apiError('This repository cannot be imported.', null, 422);
        }

        $name = trim((string) $repo['name']) ?: $repo['full_name'];
        $project = $this->publishing->create($userId, [
            'title_en'       => mb_substr($name, 0, 255),
            'title_ar'       => mb_substr($name, 0, 255),
            'summary'        => trim((string) ($repo['description'] ?? '')) ?: $name,
            'repository_url' => $url,
        ]);

        return $this->apiSuccess($project->toResearchCardArray(), 'Project created from repository.', 201);
    }

    public function disconnect(Request $request)
    {
        $conn = $this->github->connectionFor($this->userId($request));
        if ($conn) {
            $this->github->disconnect($conn);
        }
        return $this->apiSuccess(null, 'GitHub disconnected.');
    }

    // -- helpers -----------------------------------------------------------

    /** @return array<string,mixed>|\Illuminate\Http\JsonResponse */
    private function resolveRepo(Request $request, int $userId)
    {
        $conn = $this->github->connectionFor($userId);
        if (!$conn) {
            return $this->apiError('GitHub is not connected.', null, 409);
        }
        try {
            return $this->github->repository($conn, trim((string) $request->input('full_name')));
        } catch (\RuntimeException $e) {
            return match ($e->getMessage()) {
                'not_found'    => $this->apiError('Repository not found or not accessible with your GitHub account.', null, 404),
                'unauthorized' => $this->apiError('GitHub access was revoked. Please connect again.', null, 409),
                default        => $this->apiError('Could not reach GitHub right now. Please try again.', null, 502),
            };
        }
    }

    /** مشاريع المستخدم اللي ليها لينك GitHub، مفهرسة بـ owner/repo صغير. */
    private function ownerLinks(int $userId): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('projects as p')
            ->join('project_links as pl', function ($j) {
                $j->on('pl.project_id', '=', 'p.id')->where('pl.type', '=', 'github');
            })
            ->where('p.owner_id', $userId)
            ->get(['p.uuid', 'p.title_en', 'p.title_ar', 'pl.url']);

        $out = [];
        foreach ($rows as $row) {
            $parsedRepo = \App\Support\GithubRepoUrl::parse((string) $row->url);
            if ($parsedRepo !== null) {
                $out[] = ['key' => strtolower($parsedRepo[0] . '/' . $parsedRepo[1]), 'uuid' => $row->uuid, 'title' => $row->title_en ?: $row->title_ar];
            }
        }
        return $out;
    }

    private function studentOnly(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ((string) $request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can connect GitHub.', null, 403);
        }
        return null;
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }
}
