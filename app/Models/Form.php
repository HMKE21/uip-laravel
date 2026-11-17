<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    use SoftDeletes;

    protected $table = 'forms';
    protected $guarded = ['id'];
    protected $casts = [
        'settings' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime',
        'published_at' => 'datetime', 'last_response_at' => 'datetime',
    ];

    public function fields() { return $this->hasMany(FormField::class, 'form_id')->orderBy('position')->orderBy('id'); }
    public function responses() { return $this->hasMany(FormResponse::class, 'form_id'); }
    public function collaborators() { return $this->hasMany(FormCollaborator::class, 'form_id'); }
    public function isExam(): bool { return $this->mode === 'exam'; }
    public function setting(string $path, $default = null) { return data_get($this->settings ?? [], $path, $default); }
}
