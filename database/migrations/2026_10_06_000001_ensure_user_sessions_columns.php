<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يضمن إن جدول user_sessions فيه كل الأعمدة اللي UserSessionService بيكتبها.
 * لو الداتابيز الفعلية اتعملت من schema أقدم، الـ insert كان بيفشل بصمت
 * (بيتسجّل "Session tracking failed" في اللوج بس) وصفحة Sessions تفضل 0.
 * آمنة على أي داتابيز: بتضيف بس اللي ناقص.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_sessions')) {
            return;
        }

        Schema::table('user_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('user_sessions', 'device_label')) {
                $table->string('device_label', 120)->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'location_label')) {
                $table->string('location_label', 120)->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'revoked_by')) {
                $table->unsignedBigInteger('revoked_by')->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'revoked_at')) {
                $table->dateTime('revoked_at')->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'revoke_reason')) {
                $table->string('revoke_reason', 40)->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'last_activity_at')) {
                $table->dateTime('last_activity_at')->nullable();
            }
            if (!Schema::hasColumn('user_sessions', 'expires_at')) {
                $table->dateTime('expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // مقصود: مفيش rollback — الأعمدة دي جزء من الـ schema الأساسي.
    }
};
