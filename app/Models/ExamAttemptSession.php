<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سطر واحد في سجل جلسات المحاولة (claim/resume/takeover/blocked) — append-only. */
class ExamAttemptSession extends Model
{
    protected $table = 'exam_attempt_sessions';

    public $timestamps = false;

    protected $fillable = ['exam_attempt_id', 'event', 'ip', 'user_agent', 'device_id', 'device_label', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function attempt()
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }
}
