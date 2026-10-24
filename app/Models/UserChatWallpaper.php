<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** يطابق جدول `user_chat_wallpapers` — صورة خلفية شات شخصية لمستخدم واحد. */
class UserChatWallpaper extends Model
{
    protected $table = 'user_chat_wallpapers';

    protected $fillable = ['user_id', 'uid', 'stored_path', 'size_bytes'];
}
