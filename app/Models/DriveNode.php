<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** عنصر في "جهازي" (ملف أو مجلد). مفيش relation عام: كل استعلام بيتقيّد بـ user_id في DriveService. */
class DriveNode extends Model
{
    protected $table = 'drive_nodes';

    protected $fillable = [
        'user_id', 'parent_id', 'type', 'kind', 'name', 'mime_type', 'size_bytes',
        'storage_path', 'sha1', 'deleted_at', 'trash_root_id', 'prev_parent_id',
    ];

    protected $hidden = ['storage_path', 'user_id'];

    protected $casts = [
        'size_bytes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }

    public static function humanSize(int $b): string
    {
        if ($b < 1024) {
            return $b . ' B';
        }
        $u = ['KB', 'MB', 'GB', 'TB'];
        $i = (int) min(count($u) - 1, floor(log($b, 1024)) - 1);
        return round($b / (1024 ** ($i + 1)), $i === 0 ? 0 : 1) . ' ' . $u[$i];
    }

    public function toRowArray(): array
    {
        $isFolder = $this->isFolder();
        return [
            'id'            => (int) $this->id,
            'parent_id'     => $this->parent_id !== null ? (int) $this->parent_id : null,
            'type'          => $this->type,
            'kind'          => $this->kind,
            'name'          => $this->name,
            'extension'     => $isFolder ? '' : strtolower((string) pathinfo((string) $this->name, PATHINFO_EXTENSION)),
            'mime_type'     => $isFolder ? null : $this->mime_type,
            'size_bytes'    => $isFolder ? 0 : (int) $this->size_bytes,
            'size_human'    => $isFolder ? '' : self::humanSize((int) $this->size_bytes),
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'deleted_at'    => $this->deleted_at,
            'trash_root_id' => $this->trash_root_id !== null ? (int) $this->trash_root_id : null,
        ];
    }
}
