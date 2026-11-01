<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "باكات الستيكرز" (Sticker packs): المستخدم يجمّع ستيكراته في باكات بأسماء (زي واتساب: Add to sticker pack).
 * الباك بتشاور على user_stickers (المكتبة الشخصية)؛ ستيكر وصلني من حد بيتنسخ للمكتبة مرة واحدة
 * (user_stickers.source_attachment_id بيمنع التكرار) وبعدين يتضاف للباك.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sticker_packs')) {
            Schema::create('sticker_packs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name', 60);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['user_id', 'updated_at'], 'idx_sticker_packs_user');
            });
        }
        if (!Schema::hasTable('sticker_pack_items')) {
            Schema::create('sticker_pack_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pack_id');
                $table->unsignedBigInteger('sticker_id');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['pack_id', 'sticker_id'], 'uq_sticker_pack_item');
                $table->foreign('pack_id')->references('id')->on('sticker_packs')->onDelete('cascade');
                $table->foreign('sticker_id')->references('id')->on('user_stickers')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('user_stickers') && !Schema::hasColumn('user_stickers', 'source_attachment_id')) {
            Schema::table('user_stickers', function (Blueprint $table) {
                $table->unsignedBigInteger('source_attachment_id')->nullable()->after('label');
                $table->index(['user_id', 'source_attachment_id'], 'idx_user_stickers_source');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_pack_items');
        Schema::dropIfExists('sticker_packs');
        if (Schema::hasColumn('user_stickers', 'source_attachment_id')) {
            Schema::table('user_stickers', function (Blueprint $table) {
                $table->dropIndex('idx_user_stickers_source');
                $table->dropColumn('source_attachment_id');
            });
        }
    }
};
