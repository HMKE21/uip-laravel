<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نسخة "قراءة بس" مؤقتة من موديل Project القديم — كل عمود/ميثود لسه
 * مستخدمة فعليًا لحد بند 7: forOwner()/toCardArray() (بند 4، داشبورد
 * الطالب)، toResearchCardArray()/keywordList()/sdgList()/technologyList()
 * (بند 7، بحث/دليل الباحث). الموديول الكامل (بند 11 — Projects: CRUD +
 * files/team/discussion/AI) هيوسّعها لاحقًا؛ هنا القصد إعادة استخدام نفس
 * شكل الـ JSON بتاع الكارت من غير ما نبني الـ 10 endpoints بتوع Projects
 * قبل وقتها.
 *
 * ملحوظة مهمة (زي ما القديم موثّق بالظبط في تعليق toCardArray()):
 * live_demo_url/repo_url/supervisor_name مش أعمدة حقيقية في `projects` —
 * دي بتتملى بس لما الكويري تعمل subquery aliases (ProjectRepository::
 * publishedPaginated() وغيرها، بند 11). هنا forOwner() بتعمل hydrate عادي
 * (Project::where(...))، فالتلات حقول دي بترجع null دايمًا — بالظبط زي
 * سلوك القديم لنفس الـ call site.
 */
class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'uuid', 'slug', 'owner_id', 'university_id', 'title_ar', 'title_en', 'summary',
        'description', 'category', 'category_id', 'tags', 'keywords', 'supervisor_name', 'cover_image_path',
        'repository_url', 'demo_url', 'visibility', 'status', 'views_count', 'likes_count', 'published_at', 'approved_at', 'review_round',
        'sdgs', 'technologies', 'budget', 'timeline_start', 'timeline_end',
    ];

    protected $casts = [
        'tags'         => 'array',
        'published_at' => 'datetime',
        'approved_at'  => 'datetime',
        'review_round' => 'integer',
        'budget'       => 'float',
    ];

    /**
     * يطابق Project::slugify() القديمة بالظبط — سلاج من عنوان إنجليزي/عربي
     * خام. بند 5 محتاجها في ProjectRepository::generateUniqueSlug() لما
     * الجامعة تعتمد مشروع (published) ومعندوش slug لسه.
     */
    public static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    /** يطابق Project::uiStatus() القديمة بالظبط. */
    public function uiStatus(): string
    {
        return match ($this->status) {
            'draft'     => 'draft',
            'published' => 'published',
            'approved'  => 'approved', // معتمد ومش منشور
            default     => 'pending', // submitted, under_review, rejected
        };
    }

    /** يطابق Project::tagList() القديمة بالظبط. */
    public function tagList(): array
    {
        if (is_array($this->tags)) {
            return $this->tags;
        }
        if (is_string($this->tags) && $this->tags !== '') {
            $decoded = json_decode($this->tags, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** يطابق Project::toCardArray() القديمة بالظبط. */
    public function toCardArray(): array
    {
        $title = $this->title_en ?: $this->title_ar;
        return [
            'id'              => $this->uuid ?: (string) $this->id,
            'slug'            => $this->slug ?: null,
            'title'           => ['en' => $this->title_en ?: $title, 'ar' => $this->title_ar ?: $title],
            'summary'         => ['en' => $this->summary, 'ar' => $this->summary],
            'category'        => ['en' => $this->category ?: 'General', 'ar' => $this->category ?: 'عام'],
            'status'          => $this->uiStatus(),
            'score'           => 0, // real AI readiness score لسه جاي مع بند AI (21)
            'supervisor_name' => $this->supervisor_name ?? null,
            'tags'            => $this->tagList(),
            'created_at'      => $this->created_at,
            'published_at'    => $this->published_at,
            'cover_image_path' => $this->cover_image_path ?: null,
            'live_demo_url'    => $this->live_demo_url ?? null,
            'repo_url'         => $this->repo_url ?? null,
        ];
    }

    /**
     * يطابق Project::keywordList() القديمة بالظبط — `keywords` مخزّنة JSON.
     * (بند 7 — Search محتاجها لكارت "own projects".)
     */
    public function keywordList(): array
    {
        return $this->decodeJsonList($this->keywords);
    }

    /** يطابق Project::sdgList() القديمة بالظبط — أهداف التنمية المستدامة، JSON array. */
    public function sdgList(): array
    {
        return $this->decodeJsonList($this->sdgs);
    }

    /** يطابق Project::technologyList() القديمة بالظبط — مختلفة عن `tags` العامة. */
    public function technologyList(): array
    {
        return $this->decodeJsonList($this->technologies);
    }

    private function decodeJsonList($raw): array
    {
        if (!$raw) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * يطابق Project::toResearchCardArray() القديمة بالظبط — نسخة موسّعة من
     * toCardArray() لصفحة نتائج بحث الباحث (بند 7): وصف كامل + keywords/
     * sdgs/technologies/budget/timeline + الحالة الكاملة (مش الـ 3-state
     * uiStatus()).
     */
    public function toResearchCardArray(): array
    {
        $card = $this->toCardArray();
        $card['description']    = $this->description;
        $card['category_id']    = $this->category_id !== null ? (int) $this->category_id : null;
        $card['keywords']       = $this->keywordList();
        $card['sdgs']           = $this->sdgList();
        $card['technologies']   = $this->technologyList();
        $card['budget']         = $this->budget !== null ? (float) $this->budget : null;
        $card['timeline_start'] = $this->timeline_start;
        $card['timeline_end']   = $this->timeline_end;
        $card['visibility']     = $this->visibility;
        $card['status']         = $this->status; // الحالة الكاملة لسير العمل، مش uiStatus()
        return $card;
    }
}
