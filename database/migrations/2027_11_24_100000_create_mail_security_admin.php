<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 7: الإدارة والأمان.
 *  - mail_audit_logs          : سجل تدقيق لكل إجراء مهم (إرسال، حذف نهائي، إجراءات الأدمن...). مفيهوش نصوص رسائل.
 *  - mail_security_events     : نشاط مشبوه (حد الإرسال، brute-force، bounce spike، IP محظور، إيقاف تلقائي).
 *  - mail_ip_rules            : قواعد IP لكل جامعة (university_id=0 = المنصة كلها؛ المنصة block بس).
 *  - mail_external_deliveries : طابور/حالة الإرسال الخارجي (Brevo) مع إعادة المحاولة.
 *  - mail_bounces             : الـ bounces / الشكاوى (من Brevo webhook).
 *  - mail_suppressions        : عناوين ممنوع الإرسال لها (hard bounce / شكوى / يدوي). university_id=0 = صناديق شخصية/المنصة.
 *  - mail_tenants   (+أعمدة)  : سياسات الاحتفاظ وحدود الإرسال والإيقاف التلقائي.
 *  - mail_accounts  (+أعمدة)  : legal_hold (تجميد الحذف لصندوق).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mail_audit_logs')) {
            Schema::create('mail_audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->nullable();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('actor_role', 20)->nullable();
                $t->unsignedBigInteger('account_id')->nullable();
                $t->string('action', 60);
                $t->string('target_type', 30)->nullable();
                $t->string('target_id', 60)->nullable();
                $t->string('summary', 250)->nullable();
                $t->json('meta')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['university_id', 'id'], 'idx_mail_audit_uni');
                $t->index(['action', 'id'], 'idx_mail_audit_action');
                $t->index(['account_id', 'id'], 'idx_mail_audit_acc');
                $t->index(['created_at'], 'idx_mail_audit_created');
            });
        }

        if (!Schema::hasTable('mail_security_events')) {
            Schema::create('mail_security_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->nullable();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->unsignedBigInteger('account_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('type', 40);                               // send_limit | auto_suspended | bounce_spike | login_bruteforce | login_after_failures | ip_blocked | suppressed_send
                $t->string('severity', 8)->default('medium');         // low | medium | high
                $t->string('status', 10)->default('open');            // open | resolved | ignored
                $t->string('ip_address', 45)->nullable();
                $t->json('details')->nullable();
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->string('resolution_note', 250)->nullable();
                $t->dateTime('resolved_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['university_id', 'status', 'id'], 'idx_mail_sec_uni');
                $t->index(['status', 'id'], 'idx_mail_sec_status');
                $t->index(['account_id', 'type', 'created_at'], 'idx_mail_sec_acc');
            });
        }

        if (!Schema::hasTable('mail_ip_rules')) {
            Schema::create('mail_ip_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);  // 0 = المنصة
                $t->string('cidr', 50);
                $t->string('action', 5)->default('block');            // block | allow (allow للجامعات بس)
                $t->string('note', 190)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['university_id', 'cidr'], 'uq_mail_ip_rule');
            });
        }

        if (!Schema::hasTable('mail_external_deliveries')) {
            Schema::create('mail_external_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('message_id');
                $t->unsignedBigInteger('sender_account_id');
                $t->unsignedBigInteger('university_id')->nullable();
                $t->string('status', 12)->default('queued');          // queued | sent | delivered | failed | bounced | suppressed
                $t->unsignedSmallInteger('recipients')->default(0);
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->string('last_error', 250)->nullable();
                $t->dateTime('last_attempt_at')->nullable();
                $t->dateTime('next_retry_at')->nullable();
                $t->timestamps();
                $t->unique(['message_id'], 'uq_mail_extdel_msg');
                $t->index(['status', 'next_retry_at'], 'idx_mail_extdel_status');
                $t->index(['university_id', 'id'], 'idx_mail_extdel_uni');
            });
        }

        if (!Schema::hasTable('mail_bounces')) {
            Schema::create('mail_bounces', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->unsignedBigInteger('message_id')->nullable();
                $t->unsignedBigInteger('account_id')->nullable();     // صندوق المرسِل
                $t->string('recipient', 190);
                $t->string('type', 10);                               // hard | soft | complaint | blocked
                $t->string('reason', 250)->nullable();
                $t->string('provider', 12)->default('brevo');
                $t->string('provider_event_id', 64)->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['provider', 'provider_event_id'], 'uq_mail_bounce_event');
                $t->index(['university_id', 'id'], 'idx_mail_bounce_uni');
                $t->index(['recipient'], 'idx_mail_bounce_rcpt');
                $t->index(['account_id', 'created_at'], 'idx_mail_bounce_acc');
            });
        }

        if (!Schema::hasTable('mail_suppressions')) {
            Schema::create('mail_suppressions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->string('address', 190);
                $t->string('reason', 14)->default('manual');          // hard_bounce | complaint | manual
                $t->string('note', 190)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['university_id', 'address'], 'uq_mail_suppress');
            });
        }

        if (Schema::hasTable('mail_tenants') && !Schema::hasColumn('mail_tenants', 'sec_auto_suspend')) {
            Schema::table('mail_tenants', function (Blueprint $t) {
                $t->unsignedSmallInteger('retention_trash_days')->nullable();   // null = الافتراضي العام (mailbox.trash_days)
                $t->unsignedSmallInteger('retention_spam_days')->nullable();    // null = من غير مسح تلقائي
                $t->unsignedSmallInteger('retention_sent_days')->nullable();
                $t->unsignedSmallInteger('retention_all_days')->nullable();     // أي رسالة أقدم من كده (ماعدا المسودات) تتمسح
                $t->unsignedSmallInteger('sec_max_per_hour')->nullable();       // null = الافتراضي (config)
                $t->unsignedSmallInteger('sec_max_recipients_per_hour')->nullable();
                $t->unsignedSmallInteger('sec_max_external_per_hour')->nullable();
                $t->boolean('sec_auto_suspend')->default(false);
            });
        }

        if (Schema::hasTable('mail_accounts') && !Schema::hasColumn('mail_accounts', 'legal_hold')) {
            Schema::table('mail_accounts', function (Blueprint $t) {
                $t->boolean('legal_hold')->default(false);
                $t->string('legal_hold_reason', 190)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['mail_suppressions', 'mail_bounces', 'mail_external_deliveries', 'mail_ip_rules', 'mail_security_events', 'mail_audit_logs'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        if (Schema::hasColumn('mail_tenants', 'sec_auto_suspend')) {
            Schema::table('mail_tenants', fn (Blueprint $t) => $t->dropColumn(['retention_trash_days', 'retention_spam_days', 'retention_sent_days', 'retention_all_days',
                'sec_max_per_hour', 'sec_max_recipients_per_hour', 'sec_max_external_per_hour', 'sec_auto_suspend']));
        }
        if (Schema::hasColumn('mail_accounts', 'legal_hold')) {
            Schema::table('mail_accounts', fn (Blueprint $t) => $t->dropColumn(['legal_hold', 'legal_hold_reason']));
        }
    }
};
