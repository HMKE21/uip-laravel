<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 255)->nullable();
            $table->unsignedBigInteger('related_project_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_group')->default(0);
            $table->string('group_name', 255)->nullable();
            $table->unsignedBigInteger('student_group_id')->nullable();
            $table->foreign('related_project_id')->references('id')->on('projects')->onDelete('set null');
            $table->index(['student_group_id'], 'idx_conversations_student_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
