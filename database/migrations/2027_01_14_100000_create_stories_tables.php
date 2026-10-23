<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الاستوري (زي واتساب): نص / صورة / فيديو بتختفي بعد مدة (6/12/24/48 ساعة).
 *
 *  stories         الاستوري نفسها. audience: contacts | everyone | selected | except
 *  story_audience  لما audience = selected (دول بس يشوفوها) أو except (جهات اتصالي ماعدا دول)
 *  story_views     مين شاف + وقت المشاهدة + تفاعل (إيموجي). hidden = المشاهد قافل "إيصالات القراءة" فمش بيظهر لصاحب الاستوري
 *  story_mutes     كتم استوري شخص معيّن (من غير ما يعرف)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stories')) {
            Schema::create('stories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('type', 10);                        // text | image | video
                $table->text('body')->nullable();                  // نص الاستوري (type=text)
                $table->string('caption', 400)->nullable();        // تعليق على صورة/فيديو
                $table->string('bg', 24)->nullable();              // مفتاح خلفية التدرّج للنص
                $table->string('font', 16)->nullable();            // sans | serif | mono | bold
                $table->string('media_path', 255)->nullable();     // نسبي لـ public/
                $table->string('thumbnail_path', 255)->nullable();
                $table->unsignedInteger('duration_seconds')->nullable(); // مدة الفيديو
                $table->string('audience', 12)->default('contacts');
                $table->boolean('allow_replies')->default(1);
                $table->dateTime('expires_at');
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('deleted_at')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['user_id', 'expires_at'], 'idx_stories_user_expires');
                $table->index(['expires_at'], 'idx_stories_expires');
            });
        }

        if (!Schema::hasTable('story_audience')) {
            Schema::create('story_audience', function (Blueprint $table) {
                $table->unsignedBigInteger('story_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['story_id', 'user_id']);
                $table->index(['user_id'], 'idx_story_audience_user');
                $table->foreign('story_id')->references('id')->on('stories')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('story_views')) {
            Schema::create('story_views', function (Blueprint $table) {
                $table->unsignedBigInteger('story_id');
                $table->unsignedBigInteger('viewer_id');
                $table->dateTime('viewed_at')->useCurrent();
                $table->string('reaction', 16)->nullable();
                $table->dateTime('reacted_at')->nullable();
                $table->boolean('hidden')->default(0);
                $table->primary(['story_id', 'viewer_id']);
                $table->index(['viewer_id'], 'idx_story_views_viewer');
                $table->foreign('story_id')->references('id')->on('stories')->onDelete('cascade');
                $table->foreign('viewer_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('story_mutes')) {
            Schema::create('story_mutes', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('muted_user_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['user_id', 'muted_user_id']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('muted_user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_mutes');
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('story_audience');
        Schema::dropIfExists('stories');
    }
};
