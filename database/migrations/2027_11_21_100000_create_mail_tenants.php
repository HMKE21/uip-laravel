<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UIPE Mail — المرحلة 4: Multi-Tenant للجامعات.
 *  - mail_tenants: tenant واحد لكل جامعة (سياسات + حصص التخزين + إيقاف الجامعة كلها).
 *  - mail_domains: نطاقات الجامعة (واحد primary). النطاق الجديد pending لحد ما أدمن المنصة يفعّله.
 *  - mail_accounts: ربط الصندوق بالـ tenant والنطاق والعضو (طالب/موظف/كلية/جامعة) + حصة خاصة + سبب الإيقاف.
 * الجامعات اللي ليها email_domain بالفعل بيتعمل لها tenant ونطاق primary (active) تلقائيًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_tenants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('university_id')->unique();
            $t->string('status', 12)->default('active');                 // active | suspended
            $t->string('suspended_reason', 190)->nullable();
            $t->unsignedInteger('quota_student_mb')->default(1024);
            $t->unsignedInteger('quota_staff_mb')->default(5120);        // أعضاء هيئة التدريس والموظفين
            $t->unsignedInteger('quota_admin_mb')->default(5120);        // الجامعة والكليات
            $t->unsignedSmallInteger('max_attachment_mb')->default(20);
            $t->unsignedSmallInteger('max_recipients')->default(50);
            $t->string('student_send_scope', 12)->default('platform');   // platform | university (الطالب يبعت لجامعته بس)
            $t->boolean('allow_external')->default(true);                // false → صناديق الجامعة ترسل داخل المنصة فقط
            $t->timestamps();
            $t->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
        });

        Schema::create('mail_domains', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('domain', 120)->unique();
            $t->boolean('is_primary')->default(false);
            $t->string('status', 12)->default('pending');                // pending | active | disabled
            $t->dateTime('activated_at')->nullable();
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('mail_tenants')->onDelete('cascade');
            $t->index(['tenant_id', 'is_primary'], 'idx_mail_dom_tenant');
        });

        Schema::table('mail_accounts', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->after('university_id');
            $t->unsignedBigInteger('domain_id')->nullable()->after('tenant_id');
            $t->string('member_type', 12)->nullable()->after('domain_id');   // student | staff | faculty | university
            $t->unsignedBigInteger('member_id')->nullable()->after('member_type');
            $t->unsignedInteger('quota_mb')->nullable()->after('member_id'); // حصة خاصة (تغلب حصة الـ tenant)
            $t->string('suspended_reason', 190)->nullable()->after('status');
            $t->index(['tenant_id', 'member_type'], 'idx_mail_acc_tenant');
            $t->unique(['tenant_id', 'member_type', 'member_id'], 'uq_mail_acc_member');
        });

        // الجامعات اللي ليها دومين إيميل جامعي بالفعل ← tenant + نطاق primary مفعّل.
        if (Schema::hasColumn('universities', 'email_domain')) {
            $now = now();
            foreach (DB::table('universities')->whereNotNull('email_domain')->where('email_domain', '!=', '')->get(['id', 'email_domain']) as $u) {
                $tid = DB::table('mail_tenants')->insertGetId(['university_id' => $u->id, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('mail_domains')->insertOrIgnore(['tenant_id' => $tid, 'domain' => strtolower(trim($u->email_domain)), 'is_primary' => 1,
                    'status' => 'active', 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $t) {
            $t->dropUnique('uq_mail_acc_member');
            $t->dropIndex('idx_mail_acc_tenant');
            $t->dropColumn(['tenant_id', 'domain_id', 'member_type', 'member_id', 'quota_mb', 'suspended_reason']);
        });
        Schema::dropIfExists('mail_domains');
        Schema::dropIfExists('mail_tenants');
    }
};
