<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل التذكيرات التلقائية للامتحانات (قبل الفتح / قبل الإقفال). unique(exam, user, kind, target_at) هو الـ dedup:
 * الطالب ياخد كل تذكير مرة واحدة بس حتى لو الـ sweep اشتغل كذا مرة أو اتشغّل مرتين في نفس الوقت،
 * و target_at (موعد الفتح/الإقفال وقت الإرسال) معناه إن تغيير موعد الامتحان بعد كده بيسمح بتذكير جديد.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('exam_reminders_sent')) {
            return;
        }
        Schema::create('exam_reminders_sent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 20);            // opens_24h | opens_1h | closes_24h | closes_1h
            $table->timestamp('target_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['exam_id', 'user_id', 'kind', 'target_at'], 'uq_exam_reminder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_reminders_sent');
    }
};
