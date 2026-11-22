<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantSiteService;
use Illuminate\Http\Request;

/**
 * /api/v1/developer/tenants/{id}/site — موقع العميل: قوالب، تخصيص، معاينة، نشر، نسخ، والرابط الجاهز (slug).
 * الصلاحيات (site = owner|admin، القراءة لأي عضو) جوه TenantSiteService.
 */
class TenantSiteController extends Controller
{
    use RunsDomain;

    public function __construct(private TenantSiteService $site)
    {
    }

    public function overview(Request $r, int $id)
    {
        return $this->dom(fn () => $this->site->overview($this->uid($r), $id));
    }

    public function saveDraft(Request $r, int $id)
    {
        $in = $r->validate(['theme' => 'sometimes|array', 'layout' => 'sometimes|array', 'texts' => 'sometimes|array', 'home' => 'sometimes|array', 'nav' => 'sometimes|array', 'footer' => 'sometimes|array',
            'seo' => 'sometimes|array', 'blog' => 'sometimes|array', 'pages' => 'sometimes|array', 'posts' => 'sometimes|array']);
        return $this->dom(fn () => $this->site->saveDraft($this->uid($r), $id, $in));
    }

    public function applyTemplate(Request $r, int $id)
    {
        $in = $r->validate(['key' => 'required_without:template_id|string|max:60', 'template_id' => 'required_without:key|integer']);
        return $this->dom(fn () => $this->site->applyTemplate($this->uid($r), $id, $in));
    }

    public function discard(Request $r, int $id)
    {
        return $this->dom(fn () => $this->site->discardDraft($this->uid($r), $id));
    }

    public function publish(Request $r, int $id)
    {
        return $this->dom(fn () => $this->site->publish($this->uid($r), $id));
    }

    public function versions(Request $r, int $id)
    {
        return $this->dom(fn () => $this->site->versions($this->uid($r), $id));
    }

    public function restoreVersion(Request $r, int $id, int $versionId)
    {
        return $this->dom(fn () => $this->site->restoreVersion($this->uid($r), $id, $versionId));
    }

    public function previewToken(Request $r, int $id)
    {
        $in = $r->validate(['rotate' => 'nullable|boolean']);
        return $this->dom(fn () => $this->site->previewToken($this->uid($r), $id, (bool) ($in['rotate'] ?? false)));
    }

    public function templates(Request $r, int $id)
    {
        return $this->dom(fn () => ['templates' => $this->site->templates($this->uid($r), $id)]);
    }

    public function saveTemplate(Request $r, int $id)
    {
        $in = $r->validate(['name' => 'required|string|min:2|max:60']);
        return $this->dom(fn () => $this->site->saveTemplate($this->uid($r), $id, $in['name']), 201);
    }

    public function deleteTemplate(Request $r, int $id, int $templateId)
    {
        return $this->dom(fn () => $this->site->deleteTemplate($this->uid($r), $id, $templateId));
    }

    public function messages(Request $r, int $id)
    {
        $in = $r->validate(['status' => 'nullable|string|in:new,read,archived', 'page' => 'nullable|integer|min:1']);
        return $this->dom(fn () => $this->site->messages($this->uid($r), $id, $in['status'] ?? null, (int) ($in['page'] ?? 1)));
    }

    public function setMessageStatus(Request $r, int $id, int $messageId)
    {
        $in = $r->validate(['status' => 'required|string|in:new,read,archived']);
        return $this->dom(fn () => $this->site->setMessageStatus($this->uid($r), $id, $messageId, $in['status']));
    }

    public function deleteMessage(Request $r, int $id, int $messageId)
    {
        return $this->dom(fn () => $this->site->deleteMessage($this->uid($r), $id, $messageId));
    }

    public function setSlug(Request $r, int $id)
    {
        $in = $r->validate(['slug' => 'required|string|min:3|max:40']);
        return $this->dom(fn () => $this->site->setSlug($this->uid($r), $id, $in['slug']));
    }
}
