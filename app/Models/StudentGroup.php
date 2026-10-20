<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `student_groups` القديم بالظبط. */
class StudentGroup extends Model
{
    protected $table = 'student_groups';

    protected $fillable = [
        'university_id', 'name', 'description', 'max_members',
    ];
}
