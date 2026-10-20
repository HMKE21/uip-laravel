<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AiMessageAttachment.php القديمة — جدول
 * ai_message_attachments. message_id NULLABLE (ميجريشن
 * 111_fix_ai_message_attachments_message_id.sql): الملف بيتخزن قبل ما
 * الرسالة تتعمل، وبعدين بيتربط بيها (شوف AiAssistantRepository::
 * attachAttachmentsToMessage()).
 */
class AiMessageAttachment extends Model
{
    protected $table = 'ai_message_attachments';

    public $timestamps = false; // created_at بس

    protected $fillable = [
        'message_id', 'conversation_id', 'uploader_id', 'kind', 'stored_path',
        'thumbnail_path', 'original_name', 'mime_type', 'extension', 'size_bytes',
        'extracted_text',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];
}
