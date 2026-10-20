<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `api_files` القديم بالظبط (migration 143 — نفس الجدول، مفيش migration جديدة). */
class ApiFile extends Model
{
    protected $table = 'api_files';

    public $timestamps = false; // فيه created_at بس، مفيهوش updated_at

    protected $fillable = [
        'uploaded_by', 'category', 'original_name', 'stored_path',
        'mime_type', 'extension', 'size_bytes', 'download_count',
        'is_deleted', 'deleted_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
