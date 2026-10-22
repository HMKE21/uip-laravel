<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل إصدارات ملفات "جهازي": كل مرة ملف بيتحفظ فوق نفسه (محرر / مزامنة مجلد / استرجاع) النسخة القديمة بتفضل على الـ disk
 * وبتتسجّل هنا بدل ما تتمسح، فالطالب يقدر يشوفها ويرجّع لها. الـ blob له storage_path خاص (u{user}/{uuid}) زي أي ملف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_node_versions')) {
            return;
        }
        Schema::create('drive_node_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('node_id');
            $table->string('name', 255);                    // اسم الملف وقت النسخة
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_path', 191);
            $table->string('sha1', 40)->nullable();
            $table->string('reason', 16)->default('save');  // save | sync | restore
            $table->timestamp('created_at')->nullable();    // وقت ما النسخة دي بقت قديمة (= وقت الحفظ اللي حلّ محلها)

            $table->index(['node_id', 'id'], 'drive_versions_node_idx');
            $table->index(['user_id', 'id'], 'drive_versions_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_node_versions');
    }
};
