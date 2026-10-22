<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** نسخة قديمة من ملف في "جهازي" (راجع migration drive_node_versions). كل استعلام بيتقيّد بـ user_id في DriveService. */
class DriveNodeVersion extends Model
{
    public $timestamps = false;

    protected $table = 'drive_node_versions';

    protected $fillable = ['user_id', 'node_id', 'name', 'mime_type', 'size_bytes', 'storage_path', 'sha1', 'reason', 'created_at'];

    protected $hidden = ['storage_path', 'user_id'];

    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function toRowArray(): array
    {
        return [
            'id'         => (int) $this->id,
            'node_id'    => (int) $this->node_id,
            'name'       => $this->name,
            'mime_type'  => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'size_human' => DriveNode::humanSize((int) $this->size_bytes),
            'reason'     => $this->reason,
            'created_at' => $this->created_at,
        ];
    }
}
