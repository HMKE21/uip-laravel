<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سجل محاولات المحتوى الممنوع (غير لائق / حسّاس) — للأدمن في Admin → Content Safety. مفيش نص المحتوى بيتخزّن. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('content_safety_events')) {
            return;
        }
        Schema::create('content_safety_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('kind', 30);            // text | filename | image | video | gif_query | gif | svg_text | sticker …
            $t->string('category', 30);        // sexual | gore | child_safety | profanity | custom | scanner_unavailable
            $t->string('detail', 190)->nullable(); // الكلمة اللي اتطابقت أو سبب المزوّد (مش نص المستخدم)
            $t->string('route', 190)->nullable();
            $t->string('ip', 60)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_safety_events');
    }
};
