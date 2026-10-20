<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** نسخة سابقة من جسم رسالة اتعدّلت — "Edit History". بند 18. */
class MessageVersion extends Model
{
    protected $table = 'message_versions';
    public $timestamps = false;

    protected $fillable = ['message_id', 'body', 'edited_at'];

    protected $casts = ['edited_at' => 'datetime'];
}
