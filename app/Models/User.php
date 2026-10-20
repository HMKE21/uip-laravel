<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * يطابق جدول `users` القديم بالظبط (نفس الأعمدة، بدون تعديل على القاعدة).
 * password_hash (مش password) عشان كده معمول override لـ getAuthPassword().
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    /**
     * الحذف soft delete (deleted_at) بس عمود email عليه unique، فالصف المحذوف كان بيفضل ماسك الإيميل:
     * التسجيل/الدعوة بنفس الإيميل بعد الحذف كانت بتقع بـ 500 (duplicate key) لأن كل البحث بيتجاهل المحذوفين.
     * قبل أي إنشاء حساب جديد بنحرّر الإيميل من أي صف محذوف (بنغيّره لقيمة tombstone؛ الصف نفسه وسجلاته بتفضل).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $email = trim((string) $user->email);
            if ($email === '') {
                return;
            }
            // Google sign-in creates the user already verified (inbox ownership proven by Google): a real
            // address like a.b.c.d.e@gmail.com must not trip the "generated address" pattern check.
            static::assertRealEmail($user, $email, $user->email_verified_at !== null);
            \App\Support\DeletedEmail::release($email);
        });
        // Backstop for "real e-mail only": a changed address is re-checked even if a controller forgot to.
        static::updating(function (User $user) {
            if ($user->isDirty('email')) {
                $email = trim((string) $user->email);
                if ($email !== '' && !str_starts_with($email, 'deleted-')) {
                    static::assertRealEmail($user, $email);
                }
            }
        });
    }

    /** Throws a 422-style ValidationException for temporary / disposable addresses (blocklist only, no DNS). */
    protected static function assertRealEmail(User $user, string $email, bool $inboxVerified = false): void
    {
        $locale = ($user->preferred_language ?? 'en') === 'ar' ? 'ar' : 'en';
        if ($why = \App\Support\DisposableEmail::problem($email, $locale, false, $inboxVerified)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => [$why]]);
        }
    }

    protected $fillable = [
        'uuid', 'full_name', 'name_ar', 'name_en', 'email', 'phone', 'password_hash', 'avatar_path',
        'preferred_language', 'theme_preference', 'status', 'email_verified_at',
        'last_login_at', 'last_login_ip', 'two_factor_enabled', 'remember_token',
        // 2FA enrollment — دول كانوا ناقصين من
        // fillable فعليًا، يعني أي fill() سابق ليهم كان بيتجاهَل بصمت لحد
        // دلوقتي (مفيش كولر بينادي عليهم قبل كده). اتضافوا هنا مع
        // UserRepository::enableTwoFactor()/disableTwoFactor().
        'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes',
        'mfa_grace_started_at',
    ];

    protected $hidden = [
        'password_hash', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at'          => 'datetime',
        'last_login_at'              => 'datetime',
        'two_factor_enabled'         => 'boolean',
        'two_factor_confirmed_at'    => 'datetime',
        'two_factor_recovery_codes'  => 'array',
        'locked_until'               => 'datetime',
        'lock_permanent'             => 'boolean',
        'locked_at'                  => 'datetime',
        'mfa_grace_started_at'       => 'datetime',
    ];

    /** لارافيل بيدور على password افتراضيًا؛ عندنا العمود اسمه password_hash. */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /** الاسم حسب اللغة (ar/en) مع fallback للتانية ثم full_name القديم. */
    public function nameFor(string $locale = 'ar'): string
    {
        return \App\Support\BilingualName::pick($this->name_ar, $this->name_en, $locale, (string) $this->full_name);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }
}
