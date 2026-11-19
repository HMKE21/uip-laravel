<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;

class MailAccount extends Model
{
    protected $table = 'mail_accounts';
    protected $fillable = ['user_id', 'address', 'kind', 'university_id', 'display_name', 'is_default', 'status'];
    protected $casts = ['is_default' => 'boolean'];
}
