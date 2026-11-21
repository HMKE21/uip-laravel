<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Platform — الخطوة 2: المستخدم النهائي + Embed SDK.
 *  - tenant_participants: المشاركين الخارجيين (موظفين/طلاب العميل من غير حساب UIPE). المفتاح: external_id من نظام العميل.
 *  - tenant_embed_origins: النطاقات المسموح تضمّن فيها الـ iframe (بتتحول لـ CSP frame-ancestors).
 *  - tenant_embed_sessions: توكن دخول بيُستخدم مرة واحدة (token_hash) بيتبدّل بتوكن جلسة (access_hash). الاتنين بيتخزنوا hash بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_participants')) {
            Schema::create('tenant_participants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('external_id', 128);
                $t->string('name', 160);
                $t->string('email', 190)->nullable();
                $t->string('role', 12)->default('participant');               // participant | host | moderator | viewer
                $t->json('meta')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'external_id'], 'uq_tenant_participant');
                $t->index(['tenant_id', 'name'], 'idx_tenant_participant_name');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_embed_origins')) {
            Schema::create('tenant_embed_origins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('origin', 190);                                     // https://app.example.com أو https://*.example.com
                $t->timestamps();
                $t->unique(['tenant_id', 'origin'], 'uq_tenant_embed_origin');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('tenant_embed_sessions')) {
            Schema::create('tenant_embed_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('participant_id');
                $t->string('room', 128)->nullable();                           // مرجع الاجتماع (بيتربط بالـ Meeting Core في الخطوة 3)
                $t->string('origin', 190)->nullable();                         // لو العميل حدد: التوكن يشتغل من الـ origin ده بس
                $t->string('token_hash', 64)->unique();                        // sha256 لتوكن الدخول (مرة واحدة)
                $t->dateTime('expires_at');                                    // انتهاء توكن الدخول
                $t->dateTime('used_at')->nullable();
                $t->string('bound_origin', 190)->nullable();                   // الـ origin اللي اتبدّل منه (parent)
                $t->string('access_hash', 64)->nullable()->unique();           // sha256 لتوكن الجلسة
                $t->dateTime('access_expires_at')->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'participant_id'], 'idx_embed_sess_participant');
                $t->index('expires_at', 'idx_embed_sess_exp');
                $t->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $t->foreign('participant_id')->references('id')->on('tenant_participants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_embed_sessions');
        Schema::dropIfExists('tenant_embed_origins');
        Schema::dropIfExists('tenant_participants');
    }
};
