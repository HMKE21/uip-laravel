<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormAnswer extends Model
{
    protected $table = 'form_answers';
    protected $guarded = ['id'];
    protected $casts = ['value' => 'array', 'is_correct' => 'boolean', 'points' => 'float'];
}
