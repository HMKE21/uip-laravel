<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — جلسة واحدة للطالب + مراقبة الكاميرا/تحقق الهوية.
 *
 * exams: single_session_enabled, proctoring_mode (off|optional|required), identity_check_required,
 *        snapshot_interval_seconds.
 * exam_attempts: session_* (hash للـ token فقط + آخر ظهور + IP/UA/device)، identity_*، proctoring_flags_count.
 * exam_student_overrides.proctoring_waived: إعفاء طالب من الكاميرا.
 * exam_attempt_sessions: سجل append-only (claim/resume/takeover/blocked).
 * exam_proctoring_snapshots: اللقطات (الملف على disk خاص).
 * exam_security_events.event_type: enum -> string عشان الأحداث الجديدة.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'single_session_enabled')) {
                $table->boolean('single_session_enabled')->default(true);
            }
            if (!Schema::hasColumn('exams', 'proctoring_mode')) {
                $table->string('proctoring_mode', 10)->default('off');
            }
            if (!Schema::hasColumn('exams', 'identity_check_required')) {
                $table->boolean('identity_check_required')->default(false);
            }
            if (!Schema::hasColumn('exams', 'snapshot_interval_seconds')) {
                $table->unsignedSmallInteger('snapshot_interval_seconds')->default(60);
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_attempts', 'session_token_hash')) {
                $table->string('session_token_hash', 64)->nullable();
                $table->timestamp('session_last_seen_at')->nullable();
                $table->string('session_ip', 45)->nullable();
                $table->string('session_user_agent', 255)->nullable();
                $table->string('session_device_id', 64)->nullable();
                $table->unsignedSmallInteger('session_claims_count')->default(0);
                $table->string('identity_status', 10)->default('none');
                $table->unsignedBigInteger('identity_reviewed_by')->nullable();
                $table->timestamp('identity_reviewed_at')->nullable();
                $table->string('identity_review_note', 500)->nullable();
                $table->unsignedSmallInteger('proctoring_flags_count')->default(0);
            }
        });

        Schema::table('exam_student_overrides', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_student_overrides', 'proctoring_waived')) {
                $table->boolean('proctoring_waived')->default(false);
            }
        });

        if (!Schema::hasTable('exam_attempt_sessions')) {
            Schema::create('exam_attempt_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
                $table->string('event', 12); // claim | resume | takeover | blocked
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('device_id', 64)->nullable();
                $table->string('device_label', 120)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['exam_attempt_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('exam_proctoring_snapshots')) {
            Schema::create('exam_proctoring_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
                $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('kind', 10); // identity | id_card | periodic
                $table->string('path', 255);
                $table->string('mime', 30);
                $table->unsignedInteger('size_bytes')->default(0);
                $table->json('flags')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('captured_at');
                $table->index(['exam_attempt_id', 'captured_at']);
            });
        }

        if (Schema::hasTable('exam_security_events')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE exam_security_events MODIFY event_type VARCHAR(40) NOT NULL');
            } else {
                Schema::table('exam_security_events', function (Blueprint $table) {
                    $table->string('event_type', 40)->change();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_proctoring_snapshots');
        Schema::dropIfExists('exam_attempt_sessions');

        Schema::table('exam_student_overrides', function (Blueprint $table) {
            if (Schema::hasColumn('exam_student_overrides', 'proctoring_waived')) {
                $table->dropColumn('proctoring_waived');
            }
        });
        Schema::table('exam_attempts', function (Blueprint $table) {
            foreach ([
                'session_token_hash', 'session_last_seen_at', 'session_ip', 'session_user_agent', 'session_device_id',
                'session_claims_count', 'identity_status', 'identity_reviewed_by', 'identity_reviewed_at',
                'identity_review_note', 'proctoring_flags_count',
            ] as $col) {
                if (Schema::hasColumn('exam_attempts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('exams', function (Blueprint $table) {
            foreach (['single_session_enabled', 'proctoring_mode', 'identity_check_required', 'snapshot_interval_seconds'] as $col) {
                if (Schema::hasColumn('exams', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // event_type بيفضل string عمدًا.
    }
};
