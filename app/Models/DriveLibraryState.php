<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** تفضيلات "مكتبتي" لمستخدم واحد (مفضلة/تاجات/مخفي/مجلدات ذكية). راجع LibraryStateSanitizer. */
class DriveLibraryState extends Model
{
    protected $table = 'drive_library_state';

    protected $fillable = ['user_id', 'marks', 'library'];

    protected $casts = [
        'marks'   => 'array',
        'library' => 'array',
    ];
}
