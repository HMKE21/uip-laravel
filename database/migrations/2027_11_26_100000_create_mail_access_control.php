<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — إدارة الوصول (Access Control).
 *  university_id = 0 → قواعد المنصة كلها (أدمن المنصة). أي رقم تاني → قواعد جامعة (أدمن الجامعة).
 *  القاعدة الفعلية = AND بين المنصة والجامعة (الأشد يكسب) — أدمن الجامعة يقدر يقيّد بس ميقدرش يفك قيد المنصة.
 *
 *  - mail_access_settings : إعدادات لكل نطاق (وضع الدومينات الخارجية + طريقة إنشاء الصناديق الشخصية).
 *  - mail_role_policies   : لكل دور: يستخدم البريد / يرسل / يستقبل / يرسل خارجيًا / يتعمله صندوق.
 *  - mail_role_blocks     : مصفوفة الأدوار — أي دور ممنوع يراسل أي دور (الباقي مسموح).
 *  - mail_domain_rules    : دومينات محظورة أو مسموحة (send | receive | both) — يدعم *.example.com.
 *  - mail_user_overrides  : استثناءات لمستخدم بعينه (تغلب سياسة الدور داخل نفس النطاق). null = يورث.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mail_access_settings')) {
            Schema::create('mail_access_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->string('external_domain_mode', 10)->default('open');          // open (كله مسموح ما عدا المحظور) | allowlist (المسموح بس)
                $t->string('personal_mailbox_creation', 12)->default('auto');     // auto (أول فتح) | admin_only
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['university_id'], 'uq_mail_access_settings');
            });
        }

        if (!Schema::hasTable('mail_role_policies')) {
            Schema::create('mail_role_policies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->string('role_key', 50);
                $t->boolean('can_use')->default(true);
                $t->boolean('can_send')->default(true);
                $t->boolean('can_receive')->default(true);
                $t->boolean('can_send_external')->default(true);
                $t->boolean('can_create')->default(true);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['university_id', 'role_key'], 'uq_mail_role_policy');
            });
        }

        if (!Schema::hasTable('mail_role_blocks')) {
            Schema::create('mail_role_blocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->string('from_role', 50);
                $t->string('to_role', 50);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['university_id', 'from_role', 'to_role'], 'uq_mail_role_block');
            });
        }

        if (!Schema::hasTable('mail_domain_rules')) {
            Schema::create('mail_domain_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->string('domain', 120);                                        // example.com أو *.example.com
                $t->string('type', 5)->default('block');                          // block | allow
                $t->string('direction', 7)->default('both');                      // send | receive | both
                $t->string('note', 190)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique(['university_id', 'domain', 'type', 'direction'], 'uq_mail_domain_rule');
                $t->index(['university_id', 'type'], 'idx_mail_domain_rule_scope');
            });
        }

        if (!Schema::hasTable('mail_user_overrides')) {
            Schema::create('mail_user_overrides', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('university_id')->default(0);
                $t->unsignedBigInteger('user_id');
                $t->boolean('can_use')->nullable();                               // null = يورث من سياسة الدور
                $t->boolean('can_send')->nullable();
                $t->boolean('can_receive')->nullable();
                $t->boolean('can_send_external')->nullable();
                $t->boolean('can_create')->nullable();
                $t->string('reason', 190)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['university_id', 'user_id'], 'uq_mail_user_override');
                $t->index(['user_id'], 'idx_mail_user_override_user');
            });
        }
    }

    public function down(): void
    {
        foreach (['mail_user_overrides', 'mail_domain_rules', 'mail_role_blocks', 'mail_role_policies', 'mail_access_settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
