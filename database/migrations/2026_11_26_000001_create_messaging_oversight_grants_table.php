<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصاريح فتح محتوى الرسايل في "إشراف الرسائل". المحتوى بيفضل مشفّر/مخفي لحد ما إدارة المنصة تفتحه بالمفتاح السري
 * (من .env) لمحادثة/فترة تاريخ محددة ولمدة قصيرة. كل تصريح بيتسجّل (مين، ليه، أنهي فترة، لحد إمتى).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messaging_oversight_grants')) {
            return;
        }
        Schema::create('messaging_oversight_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('scope', 16);                         // people | ai
            $table->unsignedBigInteger('conversation_id')->nullable(); // null = كل المحادثات في النطاق ده
            $table->date('date_from');
            $table->date('date_to');
            $table->string('reason', 255);
            $table->string('ip', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['admin_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_oversight_grants');
    }
};
