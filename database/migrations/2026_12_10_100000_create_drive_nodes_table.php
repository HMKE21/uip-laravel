<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي": شجرة ملفات/مجلدات لكل مستخدم. صف واحد = عنصر. الملفات الحقيقية على disk 'drive' باسم عشوائي
 * (storage_path) — الاسم الظاهر والمكان في الشجرة في الـ DB بس، فالنقل/إعادة التسمية مفيهاش لمس للملف.
 * الحذف soft: العنصر وكل أحفاده بياخدوا deleted_at + trash_root_id (العنصر اللي المستخدم حذفه فعلًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_nodes')) {
            return;
        }
        Schema::create('drive_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('type', 8);                       // folder | file
            $table->string('kind', 16)->nullable();          // project (مجلد مشروع) | null
            $table->string('name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_path', 191)->nullable(); // u{user}/{uuid} للملفات بس
            $table->string('sha1', 40)->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedBigInteger('trash_root_id')->nullable();
            $table->unsignedBigInteger('prev_parent_id')->nullable(); // مكانه قبل الحذف (للاسترجاع)
            $table->timestamps();

            $table->index(['user_id', 'parent_id', 'deleted_at'], 'drive_nodes_list_idx');
            $table->index(['user_id', 'deleted_at'], 'drive_nodes_trash_idx');
            $table->index('trash_root_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_nodes');
    }
};
