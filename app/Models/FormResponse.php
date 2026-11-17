<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormResponse extends Model
{
    protected $table = 'form_responses';
    protected $guarded = ['id'];
    protected $casts = [
        'started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime',
        'needs_manual' => 'boolean', 'score' => 'float', 'max_score' => 'float',
    ];

    public function answers() { return $this->hasMany(FormAnswer::class, 'response_id'); }
    public function files() { return $this->hasMany(FormFile::class, 'response_id'); }
    public function isFinal(): bool { return in_array($this->status, ['submitted', 'graded', 'auto_submitted'], true); }
}
