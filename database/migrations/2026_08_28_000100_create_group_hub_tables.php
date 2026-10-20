<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "Group Hub" (StudentGroupHub.jsx — Timeline/Announcements/Files/
 * Tasks لمجموعة الطالب). GroupCollaborationService القديمة كانت فيها
 * الفيتشر ده أصلًا لكن اتنقل جزئيًا بس (groupIdForStudent() فقط،
 * راجع docblock الكلاس) — الجداول التلاتة دي هي الجزء اللي متأجل من
 * وقتها. student_groups.id هي bigIncrements (زي أي جدول تاني في
 * المشروع)، فـ group_id هنا foreignId عادي عليها. users.id كذلك.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'created_at']);
        });

        Schema::create('group_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['group_id', 'created_at']);
        });

        Schema::create('group_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->enum('status', ['todo', 'in_progress', 'done'])->default('todo');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_tasks');
        Schema::dropIfExists('group_files');
        Schema::dropIfExists('group_announcements');
    }
};
