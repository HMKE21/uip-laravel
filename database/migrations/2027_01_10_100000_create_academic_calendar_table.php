<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التقويم الأكاديمي: أحداث بتواريخ (بداية/نهاية الفصل، امتحانات، إجازات، مواعيد نهائية...).
 * faculty_id = null ← للجامعة كلها؛ غير كده ← لكلية بعينها. فترات التسجيل والفصول الصيفية بتتضاف للعرض تلقائياً (مش بتتخزن هنا).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('academic_calendar_events')) {
            Schema::create('academic_calendar_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('university_id')->index();
                $table->unsignedBigInteger('faculty_id')->nullable()->index();
                $table->string('title', 160);
                $table->string('kind', 16)->default('other');   // term | exams | holiday | deadline | registration | other
                $table->date('starts_on')->index();
                $table->date('ends_on')->nullable();
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_events');
    }
};
