<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `security_policies` القديم بالظبط (migration 041) — صف
 * JSON واحد لكل سياسة (policy_key)، بيستخدمه كل *PolicyService في بند 25
 * batch 3 بنفس النمط (SecurityPolicyRepository::findByKey/updateValue).
 */
class SecurityPolicy extends Model
{
    protected $table = 'security_policies';

    protected $fillable = [
        'policy_key', 'category', 'name_ar', 'name_en', 'value', 'is_active', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
