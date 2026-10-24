<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خلفيات الشات الشخصية (صور بيرفعها المستخدم لمحادثات المراسلة وشات الـ AI). `uid` معرّف نصي قصير بيتولّد في الفرونت
 * (زي ما كان بيتخزّن محليًا في المتصفح قبل كده) فالاختيار المحفوظ `img:<uid>` يفضل شغال بعد نقل الصور للسيرفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_chat_wallpapers')) {
            return;
        }
        Schema::create('user_chat_wallpapers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('uid', 24);
            $table->string('stored_path', 255);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['user_id', 'uid']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_chat_wallpapers');
    }
};
