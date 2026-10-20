<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** يمنع تكرار تذكير الموعد لنفس المستخدم: (موعد، مستخدم، عدد الأيام المتبقية). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_deadline_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deadline_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('days_before');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['deadline_id', 'user_id', 'days_before'], 'uq_project_deadline_reminders');
            $table->foreign('deadline_id')->references('id')->on('project_deadlines')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deadline_reminders');
    }
};
