<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/Portfolio.php القديمة — بند 12 (Portfolios).
 * بتتوصل بجدول `portfolios` (migration 016). صف واحد لكل مستخدم
 * (UNIQUE user_id) — الصفحة العامة/القابلة للمشاركة اللي الطلاب
 * (وأي مستخدم تاني) بيعرضوا فيها مجموعة مختارة من مشاريعهم المنشورة.
 * الترتيب/الاختيار نفسه عايش في جدول الربط `portfolio_projects`
 * (شوف PortfolioRepository).
 */
class Portfolio extends Model
{
    protected $table = 'portfolios';

    protected $fillable = [
        'user_id', 'headline', 'about', 'is_public', 'theme', 'cover_path',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public $timestamps = true;
}
