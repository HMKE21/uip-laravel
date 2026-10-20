<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/SavedQuery.php القديمة — بند 24 batch 4 (SQL
 * Query Builder، enhancement spec section 3). تطابق جدول `saved_queries`
 * (migration 082). builder_state عمود JSON (لقطة من فورم الـ builder
 * المرئي) — المستدعي بيعمله json_encode/json_decode يدويًا، نفس اتفاقية
 * SavedDashboard::layout.
 */
class SavedQuery extends Model
{
    protected $table = 'saved_queries';

    protected $fillable = [
        'user_id', 'name', 'description', 'sql_text', 'builder_state',
        'dataset_key', 'is_template', 'is_shared',
    ];
}
