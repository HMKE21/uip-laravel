<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "الشبكة" (Network) — طلبات الاتصال (زي LinkedIn) + طلبات المتابعة (حساب بموافقة) + إعدادات الشبكة.
 *
 *  user_connections         اتصال متبادل: requester → addressee، status = pending | accepted | declined
 *  post_follow_requests     طلب متابعة معلّق (لما صاحب الحساب مفعّل "أوافق على المتابِعين")
 *  user_network_settings    إعدادات المستخدم: follow_approval + accept_connection_requests
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_connections')) {
            Schema::create('user_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('requester_id');
                $table->unsignedBigInteger('addressee_id');
                $table->string('status', 10)->default('pending'); // pending | accepted | declined
                $table->string('message', 300)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('responded_at')->nullable();
                $table->unique(['requester_id', 'addressee_id'], 'uq_user_connections_pair');
                $table->index(['addressee_id', 'status'], 'idx_user_connections_addressee');
                $table->index(['requester_id', 'status'], 'idx_user_connections_requester');
                $table->foreign('requester_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('addressee_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_follow_requests')) {
            Schema::create('post_follow_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('follower_id');
                $table->unsignedBigInteger('followee_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['follower_id', 'followee_id']);
                $table->index(['followee_id'], 'idx_post_follow_requests_followee');
                $table->foreign('follower_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('followee_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('user_network_settings')) {
            Schema::create('user_network_settings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->boolean('follow_approval')->default(false);            // أوافق يدويًا على المتابِعين
                $table->boolean('accept_connection_requests')->default(true);  // أستقبل طلبات اتصال
                $table->dateTime('updated_at')->useCurrent();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        foreach (['user_network_settings', 'post_follow_requests', 'user_connections'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
