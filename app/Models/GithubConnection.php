<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** ربط حساب GitHub (OAuth) بمستخدم المنصة. التوكن بيتخزن مشفّر وبيتفك هنا بس. */
class GithubConnection extends Model
{
    protected $table = 'github_connections';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'github_user_id', 'github_login', 'avatar_url', 'access_token', 'scope', 'connected_at', 'last_used_at',
    ];

    /** مخفي من أي toArray()/JSON بالغلط. */
    protected $hidden = ['access_token'];

    public function setToken(string $plain): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($plain);
    }

    public function token(): ?string
    {
        try {
            return Crypt::decryptString((string) $this->attributes['access_token']);
        } catch (\Throwable) {
            return null; // APP_KEY اتغير أو داتا تالفة → اعتبره مش مربوط
        }
    }
}
