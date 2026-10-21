<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Google Drive backup link of one student. The refresh token is stored encrypted and decrypted only here. */
class GoogleDriveConnection extends Model
{
    protected $table = 'google_drive_connections';
    public $timestamps = false;

    protected $fillable = ['user_id', 'google_email', 'refresh_token', 'scope', 'connected_at', 'last_used_at'];
    protected $hidden = ['refresh_token'];

    public function setRefreshToken(string $plain): void
    {
        $this->attributes['refresh_token'] = Crypt::encryptString($plain);
    }

    public function refreshToken(): ?string
    {
        try {
            return Crypt::decryptString((string) $this->attributes['refresh_token']);
        } catch (\Throwable) {
            return null; // APP_KEY changed or corrupt data → treat as not connected
        }
    }
}
