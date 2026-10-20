<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataSegment.php القديمة — بند 24 batch 2. تطابق
 * جدول `data_segments` (migration 042) — مجموعة فلاتر محفوظة قابلة لإعادة
 * الاستخدام فوق `users` أو `projects`. `criteria` عمود JSON.
 */
class DataSegment extends Model
{
    protected $table = 'data_segments';

    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'entity', 'criteria'];
}