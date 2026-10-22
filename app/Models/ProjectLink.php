<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق موديل ProjectLink القديم بالظبط (migration 132) — بديل أعمدة projects.repository_url/demo_url القديمة الثابتة. */
class ProjectLink extends Model
{
    protected $table = 'project_links';
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'type', 'url', 'label', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /** الأنواع اللي الطالب/الباحث يقدر يختار منها لما يضيف لينك — يطابق ENUM المايجريشن بالظبط. */
    public const TYPES = [
        'github', 'gitlab', 'live_demo', 'website', 'mobile_app',
        'documentation', 'video_demo', 'presentation', 'research_paper', 'other',
    ];

    /**
     * رابط مستودع GitHub صالح: https://github.com/{owner}/{repo} (مع .git أو / اختياريين).
     * نفس الصيغة اللي GithubCodeReviewService::parseRepoUrl() بتقبلها، عشان
     * الرابط اللي يتحفظ يشتغل فعلًا في مراجعة الكود.
     */
    public static function isValidGithubRepoUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://(?:www\.)?github\.com/#i', trim($url)) && \App\Support\GithubRepoUrl::parse($url) !== null;
    }

    /** يطابق ProjectLink::toRowArray() القديمة بالظبط. */
    public function toRowArray(): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'label'      => $this->label ?: self::defaultLabelFor((string) $this->type),
            'url'        => $this->url,
            'is_primary' => (bool) $this->is_primary,
            'created_at' => $this->created_at,
        ];
    }

    public static function defaultLabelFor(string $type): string
    {
        return match ($type) {
            'github'         => 'GitHub Repository',
            'gitlab'         => 'GitLab Repository',
            'live_demo'      => 'Live Demo',
            'website'        => 'Website',
            'mobile_app'     => 'Mobile App',
            'documentation'  => 'Documentation',
            'video_demo'     => 'Video Demo',
            'presentation'   => 'Presentation',
            'research_paper' => 'Research Paper',
            default          => 'Other Link',
        };
    }
}
