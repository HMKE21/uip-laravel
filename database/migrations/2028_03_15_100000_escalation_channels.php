<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بند 7 من نواقص بورتال الأمان — قنوات التصعيد:
 *  - escalation_channels   : قنوات الإرسال (email / Slack / Telegram / Webhook عام). الإعدادات (توكن/لينك) متشفّرة.
 *  - escalation_policies   : قواعد "إمتى نبعت لمين": مصدر (alert/incident) + أقل خطورة + أنواع + قنوات + مناوب،
 *                            ومستوى تاني لو محدش استلم التنبيه (ack) خلال مهلة.
 *  - escalation_oncall_shifts : جدول المناوبة (مين مناوب من إمتى لإمتى).
 *  - escalation_runs       : حالة كل تصعيد (بيمنع التكرار + بيتابع الاستلام والتصعيد للمستوى التاني).
 *  - escalation_deliveries : سجل كل محاولة إرسال (نجحت/فشلت وليه) — مفيهوش أسرار.
 *  - escalation_digests    : ملخصات/تقارير أمنية مجدولة بتتبعت تلقائي على القنوات.
 *  - صلاحيتين جداد: security.escalation.view / security.escalation.manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('escalation_channels')) {
            Schema::create('escalation_channels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('type', 20); // email | slack | telegram | webhook
                $table->longText('config'); // JSON متشفّر (Crypt)
                $table->string('min_severity', 12)->default('high'); // أقل خطورة تعدّي من القناة دي
                $table->boolean('is_active')->default(true);
                $table->string('last_status', 12)->nullable();
                $table->string('last_error', 255)->nullable();
                $table->dateTime('last_sent_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->index(['is_active', 'type'], 'idx_esc_channels_active');
            });
        }

        if (!Schema::hasTable('escalation_policies')) {
            Schema::create('escalation_policies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('source', 12)->default('any'); // any | alert | incident
                $table->string('min_severity', 12)->default('high');
                $table->json('types')->nullable(); // null = كل الأنواع (نوع التنبيه أو تصنيف الحادثة)
                $table->json('channel_ids')->nullable();
                $table->boolean('notify_oncall')->default(true);
                $table->unsignedInteger('ack_timeout_minutes')->nullable(); // null = من غير مستوى تاني
                $table->json('level2_channel_ids')->nullable();
                $table->boolean('level2_notify_admins')->default(false); // المستوى التاني يبعت لكل security_admin كمان
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->index(['is_active', 'source'], 'idx_esc_policies_active');
            });
        }

        if (!Schema::hasTable('escalation_oncall_shifts')) {
            Schema::create('escalation_oncall_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index(['starts_at', 'ends_at'], 'idx_esc_shifts_window');
                $table->index('user_id', 'idx_esc_shifts_user');
            });
        }

        if (!Schema::hasTable('escalation_runs')) {
            Schema::create('escalation_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('policy_id');
                $table->string('source_type', 12); // alert | incident
                $table->unsignedBigInteger('source_id');
                $table->string('severity', 12);
                $table->string('title', 200);
                $table->unsignedTinyInteger('level')->default(1);
                $table->string('status', 12)->default('pending'); // pending | acked | escalated | done
                $table->dateTime('notified_at');
                $table->dateTime('next_check_at')->nullable();
                $table->dateTime('acked_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->unique(['policy_id', 'source_type', 'source_id'], 'uq_esc_runs_source');
                $table->index(['status', 'next_check_at'], 'idx_esc_runs_due');
            });
        }

        if (!Schema::hasTable('escalation_deliveries')) {
            Schema::create('escalation_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->nullable();
                $table->unsignedBigInteger('policy_id')->nullable();
                $table->unsignedBigInteger('channel_id')->nullable();
                $table->string('channel_type', 20); // email | slack | telegram | webhook | oncall
                $table->string('channel_name', 120)->nullable();
                $table->string('kind', 12); // alert | incident | digest | test
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedTinyInteger('level')->default(1);
                $table->string('status', 10); // sent | failed | skipped
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error', 255)->nullable();
                $table->string('title', 200)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index(['created_at'], 'idx_esc_deliv_time');
                $table->index(['status', 'created_at'], 'idx_esc_deliv_status');
            });
        }

        if (!Schema::hasTable('escalation_digests')) {
            Schema::create('escalation_digests', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('frequency', 10)->default('daily'); // daily | weekly
                $table->unsignedTinyInteger('hour')->default(8); // الساعة (بتوقيت السيرفر)
                $table->unsignedTinyInteger('weekday')->nullable(); // 1=الإثنين .. 7=الأحد (للأسبوعي)
                $table->json('channel_ids')->nullable();
                $table->json('sections')->nullable(); // alerts | incidents | vulnerabilities | network
                $table->boolean('is_active')->default(true);
                $table->dateTime('last_run_at')->nullable();
                $table->dateTime('next_run_at')->nullable();
                $table->string('last_status', 12)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->index(['is_active', 'next_run_at'], 'idx_esc_digests_due');
            });
        }

        // الصلاحيات الجديدة: security_admin الاتنين، security_officer قراءة بس (الأدمن بيعدّي دايمًا).
        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            DB::table('permissions')->insertOrIgnore([
                ['slug' => 'security.escalation.view', 'module' => 'security', 'description' => 'View escalation channels, policies, on-call rota and delivery log'],
                ['slug' => 'security.escalation.manage', 'module' => 'security', 'description' => 'Manage escalation channels, policies, on-call rota and scheduled digests'],
            ]);
            $perm = DB::table('permissions')->pluck('id', 'slug');
            $role = DB::table('roles')->pluck('id', 'slug');
            $grant = [
                'security_admin'   => ['security.escalation.view', 'security.escalation.manage'],
                'security_officer' => ['security.escalation.view'],
                'admin'            => ['security.escalation.view', 'security.escalation.manage'],
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
        foreach (['escalation_digests', 'escalation_deliveries', 'escalation_runs', 'escalation_oncall_shifts', 'escalation_policies', 'escalation_channels'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('slug', ['security.escalation.view', 'security.escalation.manage'])->delete();
        }
    }
};
