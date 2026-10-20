<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * منقولة من app/Models/AIAnalysisConsent.php القديمة — يطابق جدول
 * `ai_analysis_consents` بالظبط. صف واحد لكل مشروع (UNIQUE project_id):
 * موافقة صريحة من مالك المشروع قبل ما بياناته تتبعت لمزوّد AI خارجي.
 * شوف AIAnalysisService::hasValidConsent()/recordConsent().
 */
class AIAnalysisConsent extends Model
{
    protected $table = 'ai_analysis_consents';

    /** الجدول فيه consented_at بس، مفيهوش created_at/updated_at قياسيين. */
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'user_id', 'policy_version', 'ip_address', 'consented_at', 'revoked_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];
}
