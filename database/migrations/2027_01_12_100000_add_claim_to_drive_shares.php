<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي" ▸ روابط المشاركة لشخص واحد / مرة واحدة.
 *
 *  single_person   أول متصفح يفتح الرابط (بعد ما يوافق) بيتربط بيه — أي متصفح تاني بيترفض.
 *  one_time        الرابط بيتفتح مرة واحدة بس: بعد أول فتح بيفضل شغال للمتصفح ده لمدة access_minutes وبعدها بيتقفل نهائيًا.
 *  access_minutes  مدة الإتاحة بعد أول فتح (لـ one_time).
 *  claim_hash      sha256 للسر المحفوظ في متصفح اللي فتح الرابط (X-Share-Client) — مش بيترجّع للفرونت.
 *  claimed_at / claim_expires_at / claimed_device   وقت أول فتح / وقت انتهاء الإتاحة / وصف الجهاز لصاحب الرابط.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('drive_shares')) {
            return;
        }
        Schema::table('drive_shares', function (Blueprint $table) {
            if (!Schema::hasColumn('drive_shares', 'single_person')) {
                $table->boolean('single_person')->default(false);
            }
            if (!Schema::hasColumn('drive_shares', 'one_time')) {
                $table->boolean('one_time')->default(false);
            }
            if (!Schema::hasColumn('drive_shares', 'access_minutes')) {
                $table->unsignedInteger('access_minutes')->nullable();
            }
            if (!Schema::hasColumn('drive_shares', 'claim_hash')) {
                $table->string('claim_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('drive_shares', 'claimed_at')) {
                $table->dateTime('claimed_at')->nullable();
            }
            if (!Schema::hasColumn('drive_shares', 'claim_expires_at')) {
                $table->dateTime('claim_expires_at')->nullable();
            }
            if (!Schema::hasColumn('drive_shares', 'claimed_device')) {
                $table->string('claimed_device', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('drive_shares')) {
            return;
        }
        Schema::table('drive_shares', function (Blueprint $table) {
            foreach (['single_person', 'one_time', 'access_minutes', 'claim_hash', 'claimed_at', 'claim_expires_at', 'claimed_device'] as $c) {
                if (Schema::hasColumn('drive_shares', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
