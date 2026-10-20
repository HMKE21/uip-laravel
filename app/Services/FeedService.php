<?php

namespace App\Services;

use App\Repositories\FeedRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Http\Request;

/**
 * منقولة من app/Services/FeedService.php القديمة حرف بحرف منطقيًا —
 * Core\Request -> Illuminate\Http\Request (زي GroupsApiController/
 * publish() تمامًا: القديمة والجديدة بيقروا title/body/is_event/... مباشرة
 * من الـ Request مش من DTO منفصل). الفرق الجوهري الوحيد المتعمّد:
 * MessagingService::startConversation() هنا مالهاش باراميتر $subject
 * منفصل (بعكس القديمة) — فـ share() بتدمج عنوان البوست جوه الـ body
 * بدل ما تبعته منفصل (شوف share() تحت).
 *
 * Publishing جامعة بس؛ كل قراءة متقيدة بجامعة الطالب نفسه
 * (StudentRepository) عشان طالب ميشوفش feed جامعة تانية. بوست جديد
 * بيبعت notification حقيقي (مش toast وهمي) لكل طالب داخل الـ targeting
 * scope عبر NotificationService، بنفس الـ loop-and-notify pattern.
 */
class FeedService
{
    private const MAX_ATTACHMENTS = 10;

    public function __construct(
        private FeedRepository $feed,
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FileUploadService $uploads,
        private NotificationService $notifications,
        private AuditLogService $auditLog,
        private MessagingService $messaging
    ) {
    }

    /** @throws \RuntimeException on validation failure (message is safe to show the user) */
    public function publish(int $universityId, $authorUserId, Request $request): int
    {
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            throw new \RuntimeException('Title is required.');
        }
        $body = trim((string) $request->input('body', ''));
        $isEvent = (bool) $request->input('is_event', false);
        $eventStart = $isEvent ? (trim((string) $request->input('event_starts_at', '')) ?: null) : null;
        $eventEnd = $isEvent ? (trim((string) $request->input('event_ends_at', '')) ?: null) : null;
        $eventLocation = $isEvent ? (trim((string) $request->input('event_location', '')) ?: null) : null;
        // Draft/publish gate (migration 122): "Save as Draft" keeps the post
        // invisible to students until the university explicitly publishes it
        // later (setStatus()).
        $asDraft = (bool) $request->input('save_as_draft', false);
        $target = $this->targetFieldsFromRequest($request);

        $postId = $this->feed->create(array_merge([
            'university_id'   => $universityId,
            'author_user_id'  => $authorUserId,
            'status'          => $asDraft ? 0 : 1,
            'title'           => $title,
            'body'            => $body !== '' ? $body : null,
            'is_event'        => $isEvent ? 1 : 0,
            'event_starts_at' => $eventStart,
            'event_ends_at'   => $eventEnd,
            'event_location'  => $eventLocation,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $target));

        $this->storeAttachments($postId, $request);

        $this->auditLog->record($authorUserId, 'feed_post.created', 'feed_post', $postId, null, ['title' => $title, 'draft' => $asDraft]);

        if (!$asDraft) {
            $this->notifyStudents($postId, $universityId, $title, $target);
        }

        return $postId;
    }

    /** Publishes a previously-saved draft — the explicit "review, then go live" action. */
    public function publishDraft(int $postId, int $universityId, $actorUserId): void
    {
        $post = $this->feed->findOwnedByUniversity($postId, $universityId);
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        if ((int) $post['status'] === 1) {
            return; // already published — no-op, not an error
        }
        $this->feed->setStatus($postId, true);
        $this->auditLog->record($actorUserId, 'feed_post.published', 'feed_post', $postId, ['status' => 'draft'], ['status' => 'published']);

        $target = [
            'target_faculty_id'    => $post['target_faculty_id'] ?? null,
            'target_department_id' => $post['target_department_id'] ?? null,
            'target_academic_year' => $post['target_academic_year'] ?? null,
        ];
        $this->notifyStudents($postId, $universityId, $post['title'], $target);
    }

    /** Reverts a live post back to draft (e.g. to fix a mistake before more students see it) — doesn't un-notify students already notified. */
    public function unpublish(int $postId, int $universityId, $actorUserId): void
    {
        $post = $this->feed->findOwnedByUniversity($postId, $universityId);
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $this->feed->setStatus($postId, false);
        $this->auditLog->record($actorUserId, 'feed_post.unpublished', 'feed_post', $postId, ['status' => 'published'], ['status' => 'draft']);
    }

    /** @return array{target_faculty_id:?int,target_department_id:?int,target_academic_year:?int} */
    private function targetFieldsFromRequest(Request $request): array
    {
        return [
            'target_faculty_id'    => $request->input('target_faculty_id') !== '' && $request->input('target_faculty_id') !== null ? (int) $request->input('target_faculty_id') : null,
            'target_department_id' => $request->input('target_department_id') !== '' && $request->input('target_department_id') !== null ? (int) $request->input('target_department_id') : null,
            'target_academic_year' => $request->input('target_academic_year') !== '' && $request->input('target_academic_year') !== null ? (int) $request->input('target_academic_year') : null,
        ];
    }

    private function storeAttachments(int $postId, Request $request): void
    {
        $order = 0;
        for ($i = 0; $i < self::MAX_ATTACHMENTS; $i++) {
            $file = $request->file('attachment_' . $i);
            if (!$file || !$file->isValid()) {
                continue;
            }
            $stored = $this->uploads->store($file, 'feed', 'post-' . $postId);
            $this->feed->addAttachment($postId, [
                'kind'          => $this->kindForExtension($stored['extension']),
                'file_path'     => $stored['stored_path'],
                'original_name' => $stored['original_name'],
                'mime_type'     => $stored['mime_type'],
                'size_bytes'    => $stored['size_bytes'],
                'sort_order'    => $order++,
                'created_at'    => now(),
            ]);
        }

        $linkUrls = (array) $request->input('link_url', []);
        $linkTitles = (array) $request->input('link_title', []);
        foreach ($linkUrls as $idx => $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('One of the external links is not a valid URL.');
            }
            $this->feed->addAttachment($postId, [
                'kind'         => 'link',
                'external_url' => $url,
                'link_title'   => trim((string) ($linkTitles[$idx] ?? '')) ?: $url,
                'sort_order'   => $order++,
                'created_at'   => now(),
            ]);
        }
    }

    private function kindForExtension(string $ext): string
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return 'image';
        }
        if (in_array($ext, ['mp4', 'webm', 'mov'], true)) {
            return 'video';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }
        return 'file';
    }

    /**
     * Fans out the "new post" notification to students at this university
     * who are actually allowed to see it — i.e. the same visibility-scope
     * rule FeedRepository::feedForUniversity() enforces on read, applied
     * here too so nobody gets notified about a post their own feed page
     * would then hide from them.
     * @param array{target_faculty_id:?int,target_department_id:?int,target_academic_year:?int} $target
     */
    private function notifyStudents(int $postId, int $universityId, string $title, array $target): void
    {
        $university = $this->universities->find($universityId);
        $uniName = $university?->name('en') ?? 'Your university';
        foreach ($this->students->forUniversity($universityId) as $student) {
            if (!empty($target['target_faculty_id']) && (int) ($student->faculty_id ?? 0) !== (int) $target['target_faculty_id']) {
                continue;
            }
            if (!empty($target['target_department_id']) && (int) ($student->department_id ?? 0) !== (int) $target['target_department_id']) {
                continue;
            }
            if (!empty($target['target_academic_year']) && (int) ($student->academic_year ?? 0) !== (int) $target['target_academic_year']) {
                continue;
            }
            $this->notifications->notify(
                $student->user_id,
                'feed_post',
                'New post from ' . $uniName,
                $title,
                '/student/feed?post=' . $postId,
                'normal'
            );
        }
    }

    public function update(int $postId, int $universityId, Request $request): void
    {
        $post = $this->feed->findOwnedByUniversity($postId, $universityId);
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            throw new \RuntimeException('Title is required.');
        }
        $body = trim((string) $request->input('body', ''));
        $this->feed->update($postId, array_merge([
            'title'      => $title,
            'body'       => $body !== '' ? $body : null,
            'is_edited'  => 1,
            'edited_at'  => now(),
            'updated_at' => now(),
        ], $this->targetFieldsFromRequest($request)));
        $this->storeAttachments($postId, $request);
    }

    public function delete(int $postId, int $universityId, $actorUserId): void
    {
        $post = $this->feed->findOwnedByUniversity($postId, $universityId);
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $this->feed->softDelete($postId);
        $this->auditLog->record($actorUserId, 'feed_post.deleted', 'feed_post', $postId, ['title' => $post['title']], null);
    }

    public function setPinned(int $postId, int $universityId, bool $pinned, $actorUserId): void
    {
        $post = $this->feed->findOwnedByUniversity($postId, $universityId);
        if (!$post) {
            throw new \RuntimeException('Post not found.');
        }
        $this->feed->setPinned($postId, $pinned);
        $this->auditLog->record($actorUserId, $pinned ? 'feed_post.pinned' : 'feed_post.unpinned', 'feed_post', $postId);
    }

    /** For the student side: resolves the student's own university_id and returns it, or null if unaffiliated. */
    public function universityIdForStudent($userId): ?int
    {
        $student = $this->students->getOrCreate($userId);
        return $student->university_id ? (int) $student->university_id : null;
    }

    /**
     * The requesting student's own faculty/department/year — used to
     * evaluate a post's visibility-scope targeting.
     * @return array{faculty_id:?int,department_id:?int,academic_year:?int}
     */
    public function scopeForStudent($userId): array
    {
        $student = $this->students->getOrCreate($userId);
        return [
            'faculty_id'    => $student->faculty_id ? (int) $student->faculty_id : null,
            'department_id' => $student->department_id ? (int) $student->department_id : null,
            'academic_year' => $student->academic_year ? (int) $student->academic_year : null,
        ];
    }

    public function toggleLike(int $postId, $userId): bool
    {
        return $this->feed->toggleLike($postId, $userId);
    }

    public function toggleSave(int $postId, $userId): bool
    {
        return $this->feed->toggleSave($postId, $userId);
    }

    public function addComment(int $postId, $userId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Comment cannot be empty.');
        }
        return $this->feed->addComment($postId, $userId, $body);
    }

    public function deleteComment(int $commentId, $userId): void
    {
        $comment = $this->feed->findComment($commentId);
        if (!$comment) {
            throw new \RuntimeException('Comment not found.');
        }
        if ((string) $comment['user_id'] !== (string) $userId) {
            throw new \RuntimeException('You can only delete your own comment.');
        }
        $this->feed->deleteComment($commentId, (int) $comment['post_id']);
    }

    /**
     * Shares the post to a chosen recipient's inbox via the existing
     * messaging system. Laravel's MessagingService::startConversation()
     * has no separate $subject parameter (unlike the legacy one), so the
     * post title is folded into the opening line of the body instead of
     * being passed apart.
     */
    public function share(int $postId, $userId, string $recipientEmail, ?string $note, array $post): void
    {
        $link = '/student/feed?post=' . $postId;
        $body = 'Shared: ' . $post['title'] . "\n\n";
        if ($note !== null && trim($note) !== '') {
            $body .= trim($note) . "\n\n";
        }
        $body .= 'Shared a post: "' . $post['title'] . '" — ' . $link;

        $conversationId = $this->messaging->startConversation($userId, $recipientEmail, $body);
        $this->feed->recordShare($postId, $userId, $conversationId ?: null);
    }

    // -- Moderation / Reports --

    /** @throws \RuntimeException on validation failure or a duplicate report by the same student */
    public function report(int $postId, $reporterUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Please describe why you are reporting this post.');
        }
        try {
            $this->feed->createReport($postId, $reporterUserId, $reason);
        } catch (\Throwable $e) {
            // UNIQUE (post_id, reporter_user_id) — same student reporting the same post twice.
            throw new \RuntimeException('You have already reported this post.');
        }
    }

    /** University's moderation queue: pending + recently-resolved reports across its own feed. */
    public function reportsForUniversity($universityId): array
    {
        return $this->feed->reportsForUniversity($universityId);
    }

    /**
     * Resolves a report. $action is 'dismiss' (report was unfounded, post
     * stays up) or 'takedown' (report was valid — the post is soft-deleted,
     * same softDelete() the university's own delete button uses).
     * @throws \RuntimeException if the report doesn't belong to this university or the action is invalid
     */
    public function moderateReport(int $reportId, int $universityId, string $action, ?string $notes, $actorUserId): void
    {
        $report = $this->feed->findReport($reportId);
        if (!$report) {
            throw new \RuntimeException('Report not found.');
        }
        $post = $this->feed->findOwnedByUniversity((int) $report['post_id'], $universityId);
        // A report on an already-deleted post can still be resolved (dismissed/closed) —
        // findOwnedByUniversity() excludes soft-deleted rows, so fall back to a raw ownership check.
        if (!$post) {
            $rawPost = $this->feed->findOwnedByUniversityIncludingDeleted((int) $report['post_id'], $universityId);
            if (!$rawPost) {
                throw new \RuntimeException('Report not found.');
            }
        }
        if (!in_array($action, ['dismiss', 'takedown'], true)) {
            throw new \RuntimeException('Invalid moderation action.');
        }

        if ($action === 'takedown' && $post) {
            $this->feed->softDelete((int) $report['post_id']);
            $this->auditLog->record($actorUserId, 'feed_post.taken_down', 'feed_post', (int) $report['post_id'], null, ['reason' => $report['reason']]);
        }

        $this->feed->resolveReport($reportId, $action === 'takedown' ? 'actioned' : 'dismissed', $actorUserId, $notes);
        $this->auditLog->record($actorUserId, 'feed_report.' . $action, 'feed_post_report', $reportId, null, ['post_id' => $report['post_id']]);
    }
}
