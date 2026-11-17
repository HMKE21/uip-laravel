<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('forms')) {
            Schema::create('forms', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('public_code', 24)->unique();
                $t->string('slug', 80)->nullable()->unique();
                $t->unsignedBigInteger('owner_user_id')->index();
                $t->string('owner_role', 40)->nullable();
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->string('icon', 30)->default('form');
                $t->string('color', 20)->default('indigo');
                $t->string('mode', 10)->default('form');            // form | exam
                $t->string('status', 12)->default('draft')->index(); // draft | published | closed
                $t->json('settings')->nullable();
                $t->timestamp('opens_at')->nullable();
                $t->timestamp('closes_at')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->timestamp('last_response_at')->nullable();
                $t->unsignedInteger('responses_count')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (!Schema::hasTable('form_fields')) {
            Schema::create('form_fields', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('form_id')->index();
                $t->string('type', 24);
                $t->string('label', 500);
                $t->text('help')->nullable();
                $t->boolean('required')->default(false);
                $t->unsignedInteger('position')->default(0);
                $t->json('config')->nullable();      // options, min/max, regex, email rules, file rules ...
                $t->json('logic')->nullable();       // show-if rules
                $t->decimal('points', 8, 2)->default(0);
                $t->json('correct')->nullable();     // correct answer(s) for exams
                $t->text('explanation')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('form_responses')) {
            Schema::create('form_responses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('form_id')->index();
                $t->uuid('uuid')->unique();
                $t->string('edit_token', 64)->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('role', 40)->nullable();
                $t->string('email', 190)->nullable()->index();
                $t->string('phone', 20)->nullable()->index();
                $t->string('national_id', 20)->nullable()->index();
                $t->string('device_hash', 64)->nullable()->index();
                $t->string('ip', 60)->nullable();
                $t->string('user_agent', 300)->nullable();
                $t->string('status', 14)->default('in_progress'); // in_progress | submitted | graded | auto_submitted
                $t->timestamp('started_at')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedInteger('duration_seconds')->nullable();
                $t->decimal('score', 9, 2)->nullable();
                $t->decimal('max_score', 9, 2)->nullable();
                $t->boolean('needs_manual')->default(false);
                $t->unsignedInteger('violations')->default(0);
                $t->unsignedInteger('edits_count')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('form_answers')) {
            Schema::create('form_answers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('response_id')->index();
                $t->unsignedBigInteger('field_id')->index();
                $t->json('value')->nullable();
                $t->boolean('is_correct')->nullable();
                $t->decimal('points', 8, 2)->nullable();
                $t->text('feedback')->nullable();
                $t->timestamps();
                $t->unique(['response_id', 'field_id']);
            });
        }
        if (!Schema::hasTable('form_files')) {
            Schema::create('form_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('form_id')->index();
                $t->unsignedBigInteger('field_id')->index();
                $t->unsignedBigInteger('response_id')->nullable()->index();
                $t->string('upload_token', 64)->index();
                $t->string('original_name', 255);
                $t->string('stored_path', 400);
                $t->string('mime_type', 120)->nullable();
                $t->string('extension', 16)->nullable();
                $t->unsignedBigInteger('size_bytes')->default(0);
                $t->string('kind', 12)->default('other'); // image|video|audio|pdf|text|code|archive|office|other
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('form_events')) {
            Schema::create('form_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('response_id')->index();
                $t->string('type', 30);
                $t->string('detail', 190)->nullable();
                $t->boolean('counted')->default(false);
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (!Schema::hasTable('form_collaborators')) {
            Schema::create('form_collaborators', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('form_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('permission', 10)->default('view'); // view | edit
                $t->timestamps();
                $t->unique(['form_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['form_collaborators', 'form_events', 'form_files', 'form_answers', 'form_responses', 'form_fields', 'forms'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
