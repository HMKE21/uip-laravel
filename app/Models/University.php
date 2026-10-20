<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * يطابق جدول `universities` القديم بالظبط (migrations 004/094/137).
 * كل حساب جامعة = صف `users` (role=university) + صف `universities` مرتبط
 * بيه عبر user_id.
 */
class University extends Model
{
    use SoftDeletes;

    protected $table = 'universities';

    /**
     * Keep the normalised-name keys (backed by DB unique indexes) in sync with the names.
     * A soft-deleted university has NULL keys so its name is free to be used again.
     */
    protected static function booted(): void
    {
        static::saving(function (University $u) {
            $keys = $u->trashed()
                ? ['name_key_ar' => null, 'name_key_en' => null]
                : \App\Support\UniversityName::keys($u->official_name_ar, $u->official_name_en);
            $u->name_key_ar = $keys['name_key_ar'];
            $u->name_key_en = $keys['name_key_en'];
        });
        // Soft delete goes through a raw query (no `saving`), so free the keys on `deleted`.
        // (`trashed` is not a model event — it is an instance method, hence the old crash.)
        static::deleted(function (University $u) {
            \Illuminate\Support\Facades\DB::table('universities')->where('id', $u->id)
                ->update(['name_key_ar' => null, 'name_key_en' => null]);
        });
        static::restored(function (University $u) {
            \Illuminate\Support\Facades\DB::table('universities')->where('id', $u->id)
                ->update(\App\Support\UniversityName::keys($u->official_name_ar, $u->official_name_en));
        });
    }

    /**
     * الجامعات المسموح تظهر في أي قايمة اختيار (تسجيل/فلاتر/ربط): مش محذوفة،
     * وحساب مالكها موجود، مش محذوف، ومش banned، ومعاه role الجامعة.
     * بتشتغل على Eloquent و DB::table() الاتنين.
     */
    public static function applyListable($q, string $col = 'universities.deleted_at', string $userCol = 'universities.user_id')
    {
        return $q->whereNull($col)
            ->whereIn($userCol, function ($sub) {
                $sub->select('u.id')->from('users as u')
                    ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->where('r.slug', 'university')
                    ->whereNull('u.deleted_at')
                    ->where('u.status', '!=', 'banned');
            });
    }

    protected $fillable = [
        'user_id', 'official_name_ar', 'official_name_en', 'slug', 'country', 'city',
        'website', 'logo_path', 'verification_status', 'verified_at', 'verified_by',
        'is_public',
        // Certificate branding (2026_10_25_100000): signature, stamp, dean signature + dean details
        'signature_path', 'stamp_path', 'dean_signature_path',
        'dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar',
        // Automatic Re-verification (094)
        'verification_period_days', 'verification_expires_at',
        'verification_expiry_notified_at', 'auto_reverify_enabled',
    ];

    protected $casts = [
        'verified_at'                      => 'datetime',
        'is_public'                        => 'boolean',
        'verification_expires_at'          => 'datetime',
        'verification_expiry_notified_at'  => 'datetime',
        'auto_reverify_enabled'            => 'boolean',
    ];

    /** branding kind (as used in the URL) => column that holds its path. */
    public const BRANDING_KINDS = [
        'signature'      => 'signature_path',
        'stamp'          => 'stamp_path',
        'dean_signature' => 'dean_signature_path',
    ];

    /**
     * Absolute URLs of the branding images (null when not uploaded), keyed `<kind>_url`,
     * plus the dean's name/title. This is the shape the certificate page and the uploader read.
     *
     * @return array<string,?string>
     */
    public function brandingUrls(): array
    {
        $out = [];
        foreach (self::BRANDING_KINDS as $kind => $column) {
            $path = $this->{$column};
            $out[$kind . '_url'] = $path ? asset(ltrim($path, '/')) : null;
        }
        foreach (['dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar'] as $field) {
            $out[$field] = $this->{$field};
        }
        return $out;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->official_name_ar ?: $this->official_name_en)
            : ($this->official_name_en ?: $this->official_name_ar);
    }
}
