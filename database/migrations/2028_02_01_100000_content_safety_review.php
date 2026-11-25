<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مراجعة الأدمن للمحتوى المحجوب: عيّنة النص / نسخة الملف + قائمة "مسموح" (بالهاش). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('content_safety_events')) {
            Schema::table('content_safety_events', function (Blueprint $t) {
                if (!Schema::hasColumn('content_safety_events', 'sample')) {
                    $t->string('sample', 600)->nullable()->after('detail');
                    $t->string('content_hash', 40)->nullable()->index()->after('sample');
                    $t->string('file_name', 190)->nullable()->after('content_hash');
                    $t->string('file_mime', 100)->nullable()->after('file_name');
                    $t->unsignedBigInteger('file_size')->nullable()->after('file_mime');
                    $t->boolean('has_file')->default(false)->after('file_size');
                    $t->string('status', 12)->default('blocked')->index()->after('has_file');
                    $t->unsignedBigInteger('reviewed_by')->nullable()->after('status');
                    $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }
            });
        }
        if (!Schema::hasTable('content_safety_allowlist')) {
            Schema::create('content_safety_allowlist', function (Blueprint $t) {
                $t->id();
                $t->string('type', 10);
                $t->string('hash', 40)->index();
                $t->string('label', 190)->nullable();
                $t->unsignedBigInteger('event_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['type', 'hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_safety_allowlist');
        if (Schema::hasTable('content_safety_events') && Schema::hasColumn('content_safety_events', 'sample')) {
            Schema::table('content_safety_events', function (Blueprint $t) {
                $t->dropColumn(['sample', 'content_hash', 'file_name', 'file_mime', 'file_size', 'has_file', 'status', 'reviewed_by', 'reviewed_at']);
            });
        }
    }
};
