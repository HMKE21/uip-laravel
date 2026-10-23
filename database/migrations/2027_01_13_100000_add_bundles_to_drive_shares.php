<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رابط مشاركة واحد لأكتر من ملف/مجلد ("مجموعة"):
 *  - drive_shares.is_bundle = true  → الرابط بيشاور على قايمة عناصر في drive_share_items بدل عنصر واحد
 *    (node_id بيفضل أول عنصر عشان باقي الكود/الاستعلامات القديمة تفضل شغالة).
 *  - drive_shares.title     → اسم المجموعة اللي بيظهر عند المستلم.
 * الملفات نفسها مبتتنسخش، زي الروابط العادية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_shares')) {
            Schema::table('drive_shares', function (Blueprint $table) {
                if (!Schema::hasColumn('drive_shares', 'is_bundle')) {
                    $table->boolean('is_bundle')->default(false);
                }
                if (!Schema::hasColumn('drive_shares', 'title')) {
                    $table->string('title', 190)->nullable();
                }
            });
        }

        if (!Schema::hasTable('drive_share_items')) {
            Schema::create('drive_share_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('share_id');
                $table->unsignedBigInteger('node_id');
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['share_id', 'node_id'], 'dsi_share_node_uq');
                $table->index('share_id', 'dsi_share_idx');
                $table->index('node_id', 'dsi_node_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_share_items');
        if (Schema::hasTable('drive_shares')) {
            Schema::table('drive_shares', function (Blueprint $table) {
                foreach (['is_bundle', 'title'] as $c) {
                    if (Schema::hasColumn('drive_shares', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
