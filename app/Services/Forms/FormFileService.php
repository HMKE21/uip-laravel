<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFile;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Any-type uploads (even zip) + a preview for every kind + signed links + zip archive of all files. */
class FormFileService
{
    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    private const VIDEO = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];
    private const AUDIO = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'oga'];
    private const TEXT = ['txt', 'md', 'log', 'json', 'xml', 'yml', 'yaml', 'ini', 'csv', 'tsv', 'tex', 'rtf'];
    private const CODE = ['php', 'js', 'ts', 'jsx', 'tsx', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rb', 'rs', 'html', 'htm', 'css', 'scss', 'sql', 'sh', 'bat', 'kt', 'swift', 'dart', 'vue'];
    private const ARCHIVE = ['zip', 'rar', '7z', 'tar', 'gz'];
    private const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'];
    private const INLINE_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
    ];

    public function __construct(private FileUploadService $uploads)
    {
    }

    public static function kindOf(string $ext): string
    {
        $ext = strtolower($ext);
        return match (true) {
            in_array($ext, self::IMAGE, true) => 'image',
            in_array($ext, self::VIDEO, true) => 'video',
            in_array($ext, self::AUDIO, true) => 'audio',
            $ext === 'pdf' => 'pdf',
            in_array($ext, self::TEXT, true) => 'text',
            in_array($ext, self::CODE, true) => 'code',
            in_array($ext, self::ARCHIVE, true) => 'archive',
            in_array($ext, self::OFFICE, true) => 'office',
            default => 'other',
        };
    }

    /** @throws FormException */
    public function upload(Form $form, FormField $field, ?UploadedFile $file): FormFile
    {
        if ($field->type !== 'file' || (int) $field->form_id !== (int) $form->id) {
            throw new FormException('not_file_field', 'This question does not accept files.', 422);
        }
        $cfg = (array) $field->config;
        $allowed = array_values((array) ($cfg['allowed_extensions'] ?? []));
        $maxKb = (int) ($cfg['max_kb'] ?? config('forms.limits.max_file_kb'));

        try {
            $stored = $this->uploads->store($file, 'forms', 'form_' . $form->id, $maxKb, $allowed ?: ['*']);
        } catch (\RuntimeException $e) {
            throw new FormException('upload_rejected', $e->getMessage(), 422);
        }

        $ext = strtolower((string) ($stored['extension'] ?? pathinfo($stored['original_name'], PATHINFO_EXTENSION)));
        $row = new FormFile([
            'form_id' => $form->id, 'field_id' => $field->id, 'response_id' => null,
            'upload_token' => Str::random(40),
            'original_name' => mb_substr((string) $stored['original_name'], 0, 255),
            'stored_path' => $stored['stored_path'], 'mime_type' => $stored['mime_type'] ?? null,
            'extension' => $ext, 'size_bytes' => (int) $stored['size_bytes'], 'kind' => self::kindOf($ext),
        ]);
        $row->save();
        return $row;
    }

    public function attachToResponse(int $responseId, array $fileIds): void
    {
        if ($fileIds) {
            FormFile::whereIn('id', $fileIds)->whereNull('response_id')->update(['response_id' => $responseId]);
        }
    }

    /** Files that were attached to a response but are no longer referenced by any answer. */
    public function detachUnused(int $responseId, array $keepIds): void
    {
        $q = FormFile::where('response_id', $responseId);
        if ($keepIds) {
            $q->whereNotIn('id', $keepIds);
        }
        foreach ($q->get() as $f) {
            $this->uploads->delete($f->stored_path);
            $f->delete();
        }
    }

    public function deleteForResponse(int $responseId): void
    {
        foreach (FormFile::where('response_id', $responseId)->get() as $f) {
            $this->uploads->delete($f->stored_path);
            $f->delete();
        }
    }

    public function deleteUnattached(Form $form, string $token): bool
    {
        $f = FormFile::where('form_id', $form->id)->where('upload_token', $token)->whereNull('response_id')->first();
        if (!$f) {
            return false;
        }
        $this->uploads->delete($f->stored_path);
        $f->delete();
        return true;
    }

    public function signedUrl(FormFile $f, bool $inline = true): string
    {
        return URL::temporarySignedRoute('forms.file', now()->addSeconds((int) config('forms.signed_url_ttl', 600)), [
            'id' => $f->id, 'disp' => $inline ? 'inline' : 'attachment',
        ], false);
    }

    public function previewType(FormFile $f): string
    {
        $ext = strtolower((string) $f->extension);
        return match ($f->kind) {
            'image', 'video', 'audio', 'pdf' => 'embed',   // browser renders it from the signed url
            'text', 'code' => 'text',                       // /preview returns the text content
            'archive' => $ext === 'zip' ? 'archive' : 'none', // zip: entry listing
            default => 'none',                              // office/other: download only
        };
    }

    public function toArray(FormFile $f, bool $withUrls = true): array
    {
        $a = [
            'id' => (int) $f->id, 'name' => $f->original_name, 'size' => (int) $f->size_bytes, 'mime' => $f->mime_type,
            'extension' => $f->extension, 'kind' => $f->kind, 'preview' => $this->previewType($f),
            'token' => $f->upload_token, 'created_at' => $f->created_at?->toIso8601String(),
        ];
        if ($withUrls) {
            $a['url'] = $this->signedUrl($f, true);
            $a['download_url'] = $this->signedUrl($f, false);
        }
        return $a;
    }

    public function absolutePath(FormFile $f): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $f->stored_path), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $full = FileUploadService::isPrivatePath($rel) ? FileUploadService::privateRoot() . '/' . $rel : public_path($rel);
        if (!is_file($full)) {
            try {
                if (!\App\Support\UploadMirror::restore($rel, $full)) {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }
        return is_file($full) ? $full : null;
    }

    /** @return array{type:string,content?:string,truncated?:bool,entries?:array} */
    public function textPreview(FormFile $f): array
    {
        $path = $this->absolutePath($f);
        if (!$path) {
            throw new FormException('file_missing', 'File not found on the server.', 404);
        }
        $ext = strtolower((string) $f->extension);
        if (in_array($ext, ['zip'], true) && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $entries = [];
                $n = min($zip->numFiles, 500);
                for ($i = 0; $i < $n; $i++) {
                    $st = $zip->statIndex($i);
                    if ($st === false) {
                        continue;
                    }
                    $entries[] = ['name' => (string) $st['name'], 'size' => (int) $st['size'], 'is_dir' => str_ends_with((string) $st['name'], '/')];
                }
                $total = $zip->numFiles;
                $zip->close();
                return ['type' => 'archive', 'entries' => $entries, 'total' => $total, 'truncated' => $total > $n];
            }
            return ['type' => 'archive', 'entries' => [], 'total' => 0, 'truncated' => false];
        }
        $limit = 200 * 1024;
        $raw = (string) @file_get_contents($path, false, null, 0, $limit + 1);
        $truncated = strlen($raw) > $limit;
        $raw = substr($raw, 0, $limit);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256, ISO-8859-1');
        }
        return ['type' => 'text', 'content' => $raw, 'truncated' => $truncated, 'language' => $ext];
    }

    /** Safe inline MIME for the browser, or null => force download. */
    public function inlineMime(FormFile $f): ?string
    {
        return self::INLINE_MIME[strtolower((string) $f->extension)] ?? null;
    }

    /** Build a zip with every uploaded file of a form (grouped by respondent). Returns the temp file path. */
    public function buildArchive(Form $form, ?int $fieldId = null): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $labels = [];
        $rows = DB::table('form_responses as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.form_id', $form->id)->select('r.id', 'r.email', 'u.full_name')->get();
        foreach ($rows as $r) {
            $labels[$r->id] = $r->full_name ?: ($r->email ?: 'anonymous');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'formzip');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $used = [];
        $count = 0;
        $q = FormFile::where('form_id', $form->id)->whereNotNull('response_id');
        if ($fieldId) {
            $q->where('field_id', $fieldId);
        }
        foreach ($q->orderBy('id')->cursor() as $f) {
            $abs = $this->absolutePath($f);
            if (!$abs) {
                continue;
            }
            $who = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', (string) ($labels[$f->response_id] ?? 'response')) . '_' . $f->response_id;
            $name = $who . '/' . preg_replace('/[\\\\\/:*?"<>|]+/', '_', $f->original_name);
            if (isset($used[$name])) {
                $name = $who . '/' . ($used[$name]++) . '_' . basename($name);
            } else {
                $used[$name] = 1;
            }
            $zip->addFile($abs, $name);
            $count++;
        }
        $zip->close();
        if ($count === 0) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    public function pruneOrphans(): int
    {
        $cut = now()->subHours((int) config('forms.orphan_upload_hours', 24));
        $n = 0;
        foreach (FormFile::whereNull('response_id')->where('created_at', '<', $cut)->get() as $f) {
            $this->uploads->delete($f->stored_path);
            $f->delete();
            $n++;
        }
        return $n;
    }
}
