<?php

namespace App\Services;

use App\Events\Meetings\MeetingFileRemoved;
use App\Events\Meetings\MeetingFileShared;
use App\Models\Meeting;
use App\Models\MeetingFile;
use App\Repositories\MeetingRepository;
use Illuminate\Http\UploadedFile;

/**
 * Meetings & Collaboration Platform — Round 7 (Collaboration Extras):
 * بند 19 (File Sharing). بيستخدم FileUploadService الموجود بالفعل
 * (فئة 'meetings' جديدة في config/upload.php) بدل ما يعيد اختراع
 * الفاليديشن/الحد الأقصى/الـ content-sniffing/الـ ClamAV hook — "Integrate
 * with UIP's existing storage system if one exists" (نص المواصفة
 * بالظبط). الملف الفعلي بيتخزن تحت public/uploads/meetings/{meeting_uuid}/
 * (subfolder = uuid الاجتماع، عشان ملفات كل اجتماع منفصلة عن بعض على
 * القرص من غير ما نحتاج جدول إضافي).
 *
 * الرفع/التحميل مسموح لأي actor شايف الاجتماع (canView زي الشات)، الحذف
 * لصاحب الملف نفسه أو هوست/co-host (canManage) — نفس تدرّج الصلاحيات
 * المتبع في MeetingChatService/MeetingHostControlService.
 */
class MeetingFileService
{
    public function __construct(
        private MeetingRepository $meetings,
        private FileUploadService $uploads
    ) {
    }

    public function isFileSharingEnabled(Meeting $meeting): bool
    {
        return (bool) ($meeting->settings['allow_file_sharing'] ?? true);
    }

    /** @return MeetingFile[] */
    public function listFor(Meeting $meeting): array
    {
        return $this->meetings->meetingFilesFor($meeting->id);
    }

    /**
     * @throws \RuntimeException الشات/الرفع معطّل، أو الملف رفض من FileUploadService (حجم/نوع/فحص أمني).
     */
    public function upload(Meeting $meeting, array $actor, ?UploadedFile $file): MeetingFile
    {
        if (!$this->isFileSharingEnabled($meeting)) {
            throw new \RuntimeException('File sharing is disabled for this meeting.');
        }

        $stored = $this->uploads->store($file, 'meetings', $meeting->uuid);

        $meetingFile = $this->meetings->createMeetingFile([
            'meeting_id'            => $meeting->id,
            'uploader_key'          => $actor['key'],
            'uploader_display_name' => $actor['display_name'],
            'original_name'         => $stored['original_name'],
            'stored_path'           => $stored['stored_path'],
            'extension'             => $stored['extension'],
            'mime_type'             => $stored['mime_type'],
            'size_bytes'            => $stored['size_bytes'],
        ]);

        event(new MeetingFileShared($meeting->uuid, $meetingFile->id, $meetingFile->original_name, $actor['key'], $actor['display_name']));

        return $meetingFile;
    }

    /** @throws \RuntimeException actor مش صاحب الملف ولا هوست/co-host. */
    public function delete(Meeting $meeting, array $actor, MeetingFile $file, MeetingPolicyService $policy): void
    {
        $isOwner = $file->uploader_key === $actor['key'];
        $canManage = $policy->canManage($meeting, $this->ownerUserIdFromKey($actor), $this->meetings);

        if (!$isOwner && !$canManage) {
            throw new \RuntimeException('You can only remove files you uploaded, unless you are the host or co-host.');
        }

        $this->uploads->delete($file->stored_path);
        $file->delete();

        event(new MeetingFileRemoved($meeting->uuid, $file->id));
    }

    /** canManage() بياخد $userId int — لو actor ضيف، ماينفعش يدير أي حاجة أصلًا (مش host/co-host بالتعريف)، فبنرجّع قيمة مستحيلة (0) بدل ما نكسر التوقيع. */
    private function ownerUserIdFromKey(array $actor): int
    {
        if ($actor['type'] !== 'participant') {
            return 0;
        }

        return (int) str_replace('user:', '', $actor['key']);
    }
}
