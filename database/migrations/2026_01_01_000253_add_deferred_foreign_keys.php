<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Foreign keys that reference a table created later in the migration order
// (or self-referencing columns) are added here, after every table exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('student_groups')->onDelete('set null');
            $table->foreign('faculty_id')->references('id')->on('faculties')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('set null');
        });
        Schema::table('project_files', function (Blueprint $table) {
            $table->foreign('root_file_id')->references('id')->on('project_files')->onDelete('set null');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('student_group_id')->references('id')->on('student_groups')->onDelete('set null');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('parent_message_id')->references('id')->on('messages')->onDelete('set null');
            $table->foreign('forwarded_from_id')->references('id')->on('messages')->onDelete('set null');
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('report_schedules')->onDelete('set null');
        });
        Schema::table('ai_code_review_results', function (Blueprint $table) {
            $table->foreign('previous_review_id')->references('id')->on('ai_code_review_results')->onDelete('set null');
        });
        Schema::table('student_university_requests', function (Blueprint $table) {
            $table->foreign('faculty_id')->references('id')->on('faculties')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('set null');
        });
        Schema::table('data_exports', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('export_schedules')->onDelete('set null');
        });
        Schema::table('data_analysis_report_comments', function (Blueprint $table) {
            $table->foreign('parent_comment_id')->references('id')->on('data_analysis_report_comments')->onDelete('cascade');
        });
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->foreign('parent_message_id')->references('id')->on('ai_messages')->onDelete('set null');
            $table->foreign('faq_intent_id')->references('id')->on('faq_intents')->onDelete('set null');
        });
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->foreign('replaced_by_id')->references('id')->on('refresh_tokens')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropForeign(['faculty_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['program_id']);
        });
        Schema::table('project_files', function (Blueprint $table) {
            $table->dropForeign(['root_file_id']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['student_group_id']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['parent_message_id']);
            $table->dropForeign(['forwarded_from_id']);
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });
        Schema::table('ai_code_review_results', function (Blueprint $table) {
            $table->dropForeign(['previous_review_id']);
        });
        Schema::table('student_university_requests', function (Blueprint $table) {
            $table->dropForeign(['faculty_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['program_id']);
        });
        Schema::table('data_exports', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });
        Schema::table('data_analysis_report_comments', function (Blueprint $table) {
            $table->dropForeign(['parent_comment_id']);
        });
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropForeign(['parent_message_id']);
            $table->dropForeign(['faq_intent_id']);
        });
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropForeign(['replaced_by_id']);
        });
    }
};
