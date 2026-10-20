<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exam & Assessment System — Round 1. امتحان واحد بيخص عضو هيئة تدريس
 * واحد (created_by_academic_staff_id)، ومربوط بجامعة إجباري + كلية/قسم/
 * برنامج اختياريين. الأسئلة بتتربط عبر exam_questions pivot (Round 1
 * بس — targeting/attempts/grading/security لسه Rounds 2-5).
 *
 * status الافتراضية 'draft' — الامتحان بيفضل draft لحد Round 2 (لسه مفيش
 * publish حقيقي من غير targeting، فمفيش داعي نضيف transition دلوقتي).
 *
 * secure_mode_enabled/max_violations (Round 5 — Phases 13-16): إعدادات
 * الـ Secure Exam Mode بتاعة الامتحان ده. max_violations=null يعني مفيش
 * حد أقصى (الأحداث بتتسجل برضه، بس مفيش auto-submit بسبب المخالفات).
 */
class Exam extends Model
{
    use SoftDeletes;

    protected $table = 'exams';

    protected $fillable = [
        'university_id', 'faculty_id', 'department_id', 'created_by_academic_staff_id',
        'title', 'description', 'subject', 'course_id', 'exam_type', 'academic_year', 'semester',
        'duration_minutes', 'start_at', 'end_at', 'max_attempts', 'passing_score', 'total_marks',
        'instructions', 'randomize_questions', 'randomize_options', 'result_visibility', 'results_published_at', 'status',
        'secure_mode_enabled', 'max_violations',
        'auto_submit_on_timeout', 'allow_back_navigation', 'show_answer_review', 'show_score_only',
        'late_grace_minutes', 'late_penalty_percent',
        'single_session_enabled', 'proctoring_mode', 'identity_check_required', 'snapshot_interval_seconds',
        'appeals_enabled', 'appeal_window_days', 'is_template',
        'access_password',
    ];

    /** الباسورد (hash) عمره ما يطلع في أي response — الفرونت بياخد has_access_password بس. */
    protected $hidden = ['access_password'];

    protected $appends = ['has_access_password'];

    public function getHasAccessPasswordAttribute(): bool
    {
        return !empty($this->attributes['access_password'] ?? null);
    }

    protected $casts = [
        'start_at'             => 'datetime',
        'end_at'               => 'datetime',
        'results_published_at' => 'datetime',
        'randomize_questions'  => 'boolean',
        'randomize_options'    => 'boolean',
        'secure_mode_enabled'  => 'boolean',
        'auto_submit_on_timeout' => 'boolean',
        'allow_back_navigation'  => 'boolean',
        'show_answer_review'     => 'boolean',
        'show_score_only'        => 'boolean',
        'late_grace_minutes'     => 'integer',
        'single_session_enabled'  => 'boolean',
        'identity_check_required' => 'boolean',
        'snapshot_interval_seconds' => 'integer',
        'appeals_enabled'        => 'boolean',
        'appeal_window_days'     => 'integer',
        'is_template'            => 'boolean',
        'late_penalty_percent'   => 'decimal:2',
        'passing_score'        => 'decimal:2',
        'total_marks'          => 'decimal:2',
    ];

    public function examQuestions()
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'exam_questions')
            ->withPivot(['id', 'marks_override', 'sort_order'])
            ->orderBy('exam_questions.sort_order');
    }

    public function creator()
    {
        return $this->belongsTo(AcademicStaff::class, 'created_by_academic_staff_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(ExamSecurityEvent::class);
    }

    /** Round 7 — إعدادات الـ pools المربوطة بالامتحان ده (Phase 6)، مرتبة sort_order. */
    public function questionPools()
    {
        return $this->hasMany(ExamQuestionPool::class)->orderBy('sort_order');
    }
}
