<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/DataExplorerFavorite.php القديمة — بند 24
 * batch 4 (Data Explorer، enhancement spec section 2). تطابق جدول
 * `data_explorer_favorites` (migration 074) — toggle لكل مستخدم بيعلّم
 * مفتاح dataset (من config/data_explorer_datasets.php) كمفضّل. صف واحد
 * لكل user+dataset (UNIQUE KEY uq_dx_favorite).
 */
class DataExplorerFavorite extends Model
{
    protected $table = 'data_explorer_favorites';

    public $timestamps = false;

    protected $fillable = ['user_id', 'dataset_key'];
}
