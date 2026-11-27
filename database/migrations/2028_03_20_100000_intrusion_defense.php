<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — بند 3: الاستجابة التلقائية + Intrusion Defense.
 *
 *  - security_detection_hits.incident_id : الحادث اللي القاعدة فتحته (إجراء open_incident) — بيستخدمه ربط التفعيل بالحادث
 *    وعدم فتح حادث تاني لنفس المصدر طول ما الأول مفتوح.
 *  - لا تغيير في security_detection_rules: عمود actions JSON فبيقبل الإجراءين الجداد (open_incident / notify_admins) من غير تعديل.
 * آمنة لإعادة التشغيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_detection_hits') && !Schema::hasColumn('security_detection_hits', 'incident_id')) {
            Schema::table('security_detection_hits', function (Blueprint $t) {
                $t->unsignedBigInteger('incident_id')->nullable()->after('alert_id');
                $t->index('incident_id', 'idx_det_hit_incident');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_detection_hits') && Schema::hasColumn('security_detection_hits', 'incident_id')) {
            Schema::table('security_detection_hits', function (Blueprint $t) {
                $t->dropIndex('idx_det_hit_incident');
                $t->dropColumn('incident_id');
            });
        }
    }
};
