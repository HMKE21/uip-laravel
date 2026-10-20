<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** لقطة كاميرا (هوية/كارنيه/دورية). الملف على disk خاص، و path عمره ما يطلع في أي response. */
class ExamProctoringSnapshot extends Model
{
    protected $table = 'exam_proctoring_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'exam_attempt_id', 'exam_id', 'student_id', 'kind', 'path', 'mime', 'size_bytes', 'flags', 'ip', 'captured_at',
    ];

    protected $hidden = ['path'];

    protected $casts = [
        'flags'       => 'array',
        'captured_at' => 'datetime',
        'size_bytes'  => 'integer',
    ];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }
}
