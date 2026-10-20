<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/FaqUnmatchedLog.php القديمة — جدول
 * faq_unmatched_log. صف واحد لكل سؤال (بعد التطبيع) عجز الـ resolver
 * عن الإجابة عليه بثقة، مع عداد تكرار — يوريّ الأدمن أكتر الأسئلة
 * المفقودة تكرارًا من غير ما يخزن نص المحادثة الخام كامل.
 */
class FaqUnmatchedLog extends Model
{
    protected $table = 'faq_unmatched_log';

    public $timestamps = false; // created_at + last_seen_at يدويين

    protected $fillable = [
        'normalized_question', 'sample_question', 'language', 'role', 'portal',
        'best_candidate_key', 'best_candidate_score', 'hit_count', 'last_seen_at',
    ];

    protected $casts = [
        'best_candidate_score' => 'float',
        'hit_count'            => 'integer',
        'last_seen_at'         => 'datetime',
        'created_at'           => 'datetime',
    ];
}
