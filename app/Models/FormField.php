<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    protected $table = 'form_fields';
    protected $guarded = ['id'];
    protected $casts = ['config' => 'array', 'logic' => 'array', 'correct' => 'array', 'required' => 'boolean', 'points' => 'float'];
}
