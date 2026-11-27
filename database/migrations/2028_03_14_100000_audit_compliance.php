<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بند 6 من نواقص بورتال الأمان — التدقيق والامتثال:
 *  - سلسلة هاش (hash chain) على audit_logs: كل صف بيتختم بهاش الصف اللي قبله، فأي تعديل/حذف/إدراج في النص بيبان.
 *    audit_chain_state (راس السلسلة) + audit_chain_checkpoints (نقاط تحقق موقّعة HMAC) + audit_chain_anchors (مرساة بعد مسح الأقدم)
 *    + audit_chain_verifications (سجل نتائج الفحص).
 *  - سياسة الاحتفاظ بالسجلات: الإعدادات في compliance_settings، والمسح بيسيب مرساة موقّعة فالسلسلة تفضل قابلة للتحقق.
 *  - مراجعة دورية للصلاحيات: access_reviews + access_review_items (لقطة لكل مستخدم + قرار keep/revoke + متابعة التنفيذ).
 *  - قوائم الامتثال: compliance_controls (ISO 27001 + سياسة الجامعة + ضوابط مخصصة) + compliance_control_status.
 *  - صلاحيتين جداد: security.compliance.view (قراءة) و security.compliance.manage (تعديل).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('audit_logs', 'chain_seq')) {
                    $table->unsignedBigInteger('chain_seq')->nullable()->unique('uq_audit_chain_seq');
                }
                if (!Schema::hasColumn('audit_logs', 'prev_hash')) {
                    $table->char('prev_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('audit_logs', 'entry_hash')) {
                    $table->char('entry_hash', 64)->nullable();
                }
                // نسخة من user_id وقت الكتابة: المفتاح الأجنبي بيتحوّل NULL لو اليوزر اتمسح، فمينفعش يدخل في الهاش.
                if (!Schema::hasColumn('audit_logs', 'actor_ref')) {
                    $table->unsignedBigInteger('actor_ref')->nullable();
                }
            });
        }

        if (!Schema::hasTable('audit_chain_state')) {
            Schema::create('audit_chain_state', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_seq')->default(0);
                $table->char('last_hash', 64)->default(str_repeat('0', 64));
                $table->dateTime('updated_at')->useCurrent();
            });
        }
        DB::table('audit_chain_state')->insertOrIgnore(['id' => 1, 'last_seq' => 0, 'last_hash' => str_repeat('0', 64)]);

        if (!Schema::hasTable('audit_chain_checkpoints')) {
            Schema::create('audit_chain_checkpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seq');
                $table->char('entry_hash', 64);
                $table->unsignedBigInteger('entries_count')->default(0);
                $table->char('signature', 64);
                $table->string('kind', 12)->default('auto'); // auto | manual | verify
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index('seq', 'idx_audit_cp_seq');
            });
        }

        if (!Schema::hasTable('audit_chain_anchors')) {
            Schema::create('audit_chain_anchors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('up_to_seq');
                $table->char('up_to_hash', 64);
                $table->unsignedBigInteger('pruned_count')->default(0);
                $table->dateTime('cutoff_at');
                $table->dateTime('from_created_at')->nullable();
                $table->dateTime('to_created_at')->nullable();
                $table->string('archive_path', 255)->nullable();
                $table->char('archive_sha256', 64)->nullable();
                $table->char('signature', 64);
                $table->unsignedBigInteger('run_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index('up_to_seq', 'idx_audit_anchor_seq');
            });
        }

        if (!Schema::hasTable('audit_chain_verifications')) {
            Schema::create('audit_chain_verifications', function (Blueprint $table) {
                $table->id();
                $table->string('status', 12); // ok | broken | empty
                $table->unsignedBigInteger('checked_rows')->default(0);
                $table->unsignedBigInteger('unsealed_rows')->default(0);
                $table->unsignedBigInteger('issues_total')->default(0);
                $table->unsignedBigInteger('first_break_seq')->nullable();
                $table->json('issues')->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('trigger', 12)->default('manual'); // manual | scheduled
                $table->unsignedBigInteger('run_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('compliance_settings')) {
            Schema::create('compliance_settings', function (Blueprint $table) {
                $table->string('skey', 64)->primary();
                $table->json('value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('access_reviews')) {
            Schema::create('access_reviews', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160);
                $table->string('scope', 20)->default('privileged'); // privileged | staff | all
                $table->string('status', 12)->default('open');      // open | completed | cancelled
                $table->date('due_at')->nullable();
                $table->json('summary')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();
                $table->index('status', 'idx_access_reviews_status');
            });
        }

        if (!Schema::hasTable('access_review_items')) {
            Schema::create('access_review_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_id');
                $table->unsignedBigInteger('user_id');
                $table->json('snapshot');
                $table->json('flags')->nullable();
                $table->string('decision', 10)->nullable(); // keep | revoke
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->dateTime('remediated_at')->nullable();
                $table->unsignedBigInteger('remediated_by')->nullable();
                $table->foreign('review_id')->references('id')->on('access_reviews')->onDelete('cascade');
                $table->unique(['review_id', 'user_id'], 'uq_access_review_user');
            });
        }

        if (!Schema::hasTable('compliance_controls')) {
            Schema::create('compliance_controls', function (Blueprint $table) {
                $table->id();
                $table->string('framework', 24);
                $table->string('code', 24);
                $table->string('category', 60)->nullable();
                $table->string('title_en', 255);
                $table->string('title_ar', 255);
                $table->text('guidance_en')->nullable();
                $table->text('guidance_ar')->nullable();
                $table->string('auto_key', 40)->nullable();
                $table->boolean('is_custom')->default(0);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->unique(['framework', 'code'], 'uq_compliance_control');
            });
        }

        if (!Schema::hasTable('compliance_control_status')) {
            Schema::create('compliance_control_status', function (Blueprint $table) {
                $table->unsignedBigInteger('control_id')->primary();
                $table->string('status', 16)->default('not_started'); // not_started | in_progress | implemented | not_applicable
                $table->string('owner', 120)->nullable();
                $table->text('evidence_note')->nullable();
                $table->string('evidence_url', 500)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->date('next_review_at')->nullable();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->foreign('control_id')->references('id')->on('compliance_controls')->onDelete('cascade');
            });
        }

        // الصلاحيات الجديدة + منحها: security_admin الاتنين، security_officer قراءة بس (الأدمن بيعدّي دايمًا).
        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            DB::table('permissions')->insertOrIgnore([
                ['slug' => 'security.compliance.view', 'module' => 'security', 'description' => 'View audit integrity, retention, access reviews and compliance checklists'],
                ['slug' => 'security.compliance.manage', 'module' => 'security', 'description' => 'Manage audit retention, run access reviews and update compliance checklists'],
            ]);
            $perm = DB::table('permissions')->pluck('id', 'slug');
            $role = DB::table('roles')->pluck('id', 'slug');
            $grant = [
                'security_admin'   => ['security.compliance.view', 'security.compliance.manage'],
                'security_officer' => ['security.compliance.view'],
                'admin'            => ['security.compliance.view', 'security.compliance.manage'],
            ];
            foreach ($grant as $roleSlug => $slugs) {
                foreach ($slugs as $slug) {
                    if (isset($role[$roleSlug], $perm[$slug])) {
                        DB::table('role_permission')->insertOrIgnore(['role_id' => $role[$roleSlug], 'permission_id' => $perm[$slug]]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['compliance_control_status', 'compliance_controls', 'access_review_items', 'access_reviews', 'compliance_settings',
                  'audit_chain_verifications', 'audit_chain_anchors', 'audit_chain_checkpoints', 'audit_chain_state'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('audit_logs', 'chain_seq')) {
                    $table->dropUnique('uq_audit_chain_seq');
                }
                foreach (['chain_seq', 'prev_hash', 'entry_hash', 'actor_ref'] as $col) {
                    if (Schema::hasColumn('audit_logs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('slug', ['security.compliance.view', 'security.compliance.manage'])->delete();
        }
    }
};
