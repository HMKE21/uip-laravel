<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محادثات شات الكود (محرر MyPC / ZipWorkbench) المحفوظة على حساب المستخدم: بترجع من أي جهاز، وينفع تتفتح تاني
 * أو تتسمّى أو تتحذف. الـ uuid بيتولّد في الفرونت (نفس المحادثة = نفس الـ uuid في كل حفظ). الرسايل JSON واحد
 * (شوف App\Services\Ai\CodeChatHistory).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_code_chats')) {
            return;
        }
        Schema::create('ai_code_chats', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('scope', 64)->default('');      // uuid المشروع (لو الشات جوه مشروع) أو فاضي
            $table->string('label', 160)->default('');     // اسم الأرشيف/المشروع للعرض
            $table->string('title', 120)->default('');
            $table->string('mode', 12)->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->longText('messages');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'updated_at'], 'idx_ai_code_chats_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_code_chats');
    }
};
