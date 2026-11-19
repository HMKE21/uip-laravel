<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;

class MailAccount extends Model
{
    protected $table = 'mail_accounts';
    protected $fillable = ['user_id', 'address', 'kind', 'university_id', 'display_name', 'is_default', 'status',
        'tenant_id', 'domain_id', 'member_type', 'member_id', 'quota_mb', 'suspended_reason',
        'membership_expires_at', 'suspended_by'];
    protected $casts = ['is_default' => 'boolean'];
}
