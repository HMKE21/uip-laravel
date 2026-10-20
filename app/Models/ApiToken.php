<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/ApiToken.php القديمة — بند 25 batch 4. يطابق جدول
 * `api_tokens` بالظبط (migration 038). بيرن bearer tokens للعميل
 * الموبايل المستقبلي/مستهلكين خارجيين للـ API. بس الـ SHA-256 hash
 * بيتخزن — الـ raw token موجود بس في الـ HTTP response وقت الإنشاء ومش
 * قابل للاسترجاع تاني بعد كده.
 */
class ApiToken extends Model
{
    protected $table = 'api_tokens';

    /** الجدول فيه created_at بس (DEFAULT CURRENT_TIMESTAMP)، مفيهوش updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'name', 'token_hash', 'created_by', 'last_used_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
        'created_at'   => 'datetime',
    ];
}
