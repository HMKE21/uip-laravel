<?php

namespace App\Services;

use App\Repositories\AnnouncementRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Http\Request;

/**
 * منقولة من app/Services/AnnouncementService.php القديمة حرف بحرف
 * منطقيًا — Core\Request -> Illuminate\Http\Request زي FeedService
 * بالظبط (القديمة والجديدة بيقروا title/body/category/publish_at/
 * expires_at/target_.../attachment_.../link_url[]/link_title[] مباشرة من
 * الـ Request مش من DTO منفصل). مفيش أي فرق تصميم متعمّد هنا (بعكس
 * FeedService::share()) — الميثودز كلها 1:1 مع القديمة.
 *
 * Publishing جامعة بس؛ كل قراءة متقيدة بجامعة الطالب نفسه
 * (StudentRepository) عشان طالب ميشوفش إعلانات جامعة تانية. إعلان فوري
 * (publish_at فاضي أو في الماضي) بيبعت notification حقيقي لكل طالب داخل
 * نطاق الاستهداف فورًا؛ إعلان مجدول (publish_at في المستقبل) بيتسجل من
 * غير إشعار لحد ما notifyDuePending() (اللي PublishScheduledAnnouncements
 * console command بيلفها) تلاقيه استحق.
 */
class AnnouncementService
{
    private const MAX_ATTACHMENTS = 5;

    private const CATEGORIES = [
        'academic', 'event', 'competition', 'deadline', 'training', 'workshop', 'research',
    ];

    public function __construct(
        private AnnouncementRepository $announcements,
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FileUploadService $uploads,
        private NotificationService $notifications,
        private AuditLogService $auditLog
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
        $category = (string) $request->input('category', 'academic');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'academic';
        }

        $publishAtRaw = trim((string) $request->input('publish_at', ''));
        $publishAt = null;
        $isImmediate = true;
        if ($publishAtRaw !== '') {
            $ts = strtotime($publishAtRaw);
            if ($ts === false) {
                throw new \RuntimeException('Invalid scheduled publish date/time.');
            }
            $publishAt = date('Y-m-d H:i:s', $ts);
            $isImmediate = $ts <= time();
        }

        // Expiry (spec gap — Section 3, closed): announcement drops out of the
        // student-facing list once past its expires_at (migration 122); NULL never expires.
        $expiresAtRaw = trim((string) $request->input('expires_at', ''));
        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            $expTs = strtotime($expiresAtRaw);
            if ($expTs === false) {
                throw new \RuntimeException('Invalid expiry date/time.');
            }
            $expiresAt = date('Y-m-d H:i:s', $expTs);
        }

        $id = $this->announcements->create(array_merge([
            'university_id'  => $universityId,
            'author_user_id' => $authorUserId,
            'category'       => $category,
            'title'          => $title,
            'body'           => $body !== '' ? $body : null,
            'publish_at'     => $publishAt,
            'expires_at'     => $expiresAt,
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $this->targetFieldsFromRequest($request)));

        $this->storeAttachments($id, $request);

        $this->auditLog->record($authorUserId, 'announcement.created', 'announcement', $id, null, [
            'title' => $title, 'category' => $category, 'scheduled' => !$isImmediate,
        ]);

        if ($isImmediate) {
            $this->notifyStudents($id, $universityId, $title);
            $this->announcements->markNotified($id);
        }

        return $id;
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

    private function storeAttachments(int $announcementId, Request $request): void
    {
        $order = 0;
        for ($i = 0; $i < self::MAX_ATTACHMENTS; $i++) {
            $file = $request->file('attachment_' . $i);
            if (!$file || !$file->isValid()) {
                continue;
            }
            $stored = $this->uploads->store($file, 'announcements', 'announcement-' . $announcementId);
            $this->announcements->addAttachment($announcementId, [
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
            $this->announcements->addAttachment($announcementId, [
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

    private function notifyStudents(int $announcementId, int $universityId, string $title): void
    {
        $university = $this->universities->find($universityId);
        $uniName = $university?->name('en') ?? 'Your university';
        foreach ($this->students->forUniversity($universityId) as $student) {
            $this->notifications->notify(
                $student->user_id,
                'announcement',
                'New announcement from ' . $uniName,
                $title,
                '/student/announcements?a=' . $announcementId,
                'normal'
            );
        }
    }

    /** Called by PublishScheduledAnnouncements console command: notifies every announcement whose publish_at has now arrived. */
    public function notifyDuePending(): int
    {
        $count = 0;
        foreach ($this->announcements->duePendingNotification() as $a) {
            $this->notifyStudents((int) $a['id'], (int) $a['university_id'], $a['title']);
            $this->announcements->markNotified((int) $a['id']);
            $count++;
        }
        return $count;
    }

    public function update(int $id, int $universityId, Request $request): void
    {
        $announcement = $this->announcements->findOwnedByUniversity($id, $universityId);
        if (!$announcement) {
            throw new \RuntimeException('Announcement not found.');
        }
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            throw new \RuntimeException('Title is required.');
        }
        $body = trim((string) $request->input('body', ''));
        $category = (string) $request->input('category', $announcement['category']);
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = $announcement['category'];
        }
        $expiresAtRaw = trim((string) $request->input('expires_at', ''));
        $expiresAt = $announcement['expires_at'] ?? null;
        if ($expiresAtRaw !== '') {
            $expTs = strtotime($expiresAtRaw);
            if ($expTs === false) {
                throw new \RuntimeException('Invalid expiry date/time.');
            }
            $expiresAt = date('Y-m-d H:i:s', $expTs);
        } elseif ($request->input('expires_at') !== null) {
            $expiresAt = null; // explicitly cleared
        }
        $this->announcements->update($id, array_merge([
            'title'      => $title,
            'body'       => $body !== '' ? $body : null,
            'category'   => $category,
            'expires_at' => $expiresAt,
            'updated_at' => now(),
        ], $this->targetFieldsFromRequest($request)));
        $this->storeAttachments($id, $request);
    }

    /**
     * The requesting student's own faculty/department/year — used to
     * evaluate an announcement's visibility-scope targeting (spec gap, closed).
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

    public function delete(int $id, int $universityId, $actorUserId): void
    {
        $announcement = $this->announcements->findOwnedByUniversity($id, $universityId);
        if (!$announcement) {
            throw new \RuntimeException('Announcement not found.');
        }
        $this->announcements->softDelete($id);
        $this->auditLog->record($actorUserId, 'announcement.deleted', 'announcement', $id, ['title' => $announcement['title']], null);
    }

    public function universityIdForStudent($userId): ?int
    {
        $student = $this->students->getOrCreate($userId);
        return $student->university_id ? (int) $student->university_id : null;
    }
}
