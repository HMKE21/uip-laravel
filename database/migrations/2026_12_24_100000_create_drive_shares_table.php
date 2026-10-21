<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي": روابط مشاركة لملف أو مجلد. الرابط = token عشوائي طويل (غير قابل للتخمين) + صلاحية اختيارية + إلغاء في أي وقت.
 * الملفات نفسها مبتتنسخش: الرابط بيشاور على العنصر، فلو العنصر اتحذف (سلة/نهائي) الرابط بيموت لوحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_shares')) {
            return;
        }
        Schema::create('drive_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');           // صاحب الملف
            $table->unsignedBigInteger('node_id');           // drive_nodes.id (ملف أو مجلد)
            $table->string('token', 64)->unique();
            $table->boolean('can_download')->default(true);  // false = عرض فقط (معاينة بدون تنزيل / zip)
            $table->dateTime('expires_at')->nullable();      // null = بدون انتهاء
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'node_id'], 'drive_shares_node_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_shares');
    }
};
