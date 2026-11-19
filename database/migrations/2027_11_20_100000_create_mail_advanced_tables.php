<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UIPE Mail — المرحلة 3: Snooze / Scheduled / Undo Send / Confidential / Filters / Templates / Signatures / Vacation / Import. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_items', function (Blueprint $t) {
            $t->dateTime('snoozed_until')->nullable();
            $t->index(['snoozed_until'], 'idx_mail_item_snooze');
        });

        Schema::table('mail_messages', function (Blueprint $t) {
            $t->string('send_state', 10)->nullable();              // scheduled | undo (الرسالة لسه مسودة وبتستنى وقتها)
            $t->dateTime('scheduled_at')->nullable();
            $t->string('schedule_error', 200)->nullable();
            $t->boolean('confidential')->default(false);
            $t->unsignedSmallInteger('conf_days')->nullable();      // مدة الصلاحية بعد الإرسال
            $t->dateTime('conf_expires_at')->nullable();
            $t->string('conf_passcode', 100)->nullable();           // hash
            $t->dateTime('conf_revoked_at')->nullable();
            $t->boolean('is_auto')->default(false);                // رد تلقائي (Vacation)
            $t->boolean('is_imported')->default(false);
            $t->string('ext_from_address', 190)->nullable();       // مرسل الرسائل المستوردة
            $t->string('ext_from_name', 150)->nullable();
            $t->string('ext_message_id', 250)->nullable();
            $t->index(['send_state', 'scheduled_at'], 'idx_mail_msg_sched');
            $t->index(['sender_account_id', 'ext_message_id'], 'idx_mail_msg_extid');
        });

        Schema::create('mail_filters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 100);
            $t->json('conditions');
            $t->json('actions');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->index(['account_id', 'is_active'], 'idx_mail_filter_acc');
        });

        Schema::create('mail_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 100);
            $t->string('subject', 250)->nullable();
            $t->longText('body_html')->nullable();
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->index(['account_id'], 'idx_mail_tpl_acc');
        });

        Schema::create('mail_signatures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 100);
            $t->mediumText('body_html')->nullable();
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->index(['account_id'], 'idx_mail_sig_acc');
        });

        Schema::create('mail_vacation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->string('subject', 250)->default('');
            $t->mediumText('body_html')->nullable();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
        });

        Schema::create('mail_vacation_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('sender_address', 190);
            $t->dateTime('replied_at');
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->index(['account_id', 'sender_address'], 'idx_mail_vac_log');
        });

        Schema::create('mail_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->unique();
            $t->unsignedTinyInteger('undo_seconds')->default(10);
            $t->unsignedBigInteger('sig_new_id')->nullable();
            $t->unsignedBigInteger('sig_reply_id')->nullable();
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        foreach (['mail_settings', 'mail_vacation_log', 'mail_vacation', 'mail_signatures', 'mail_templates', 'mail_filters'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('mail_messages', function (Blueprint $t) {
            $t->dropIndex('idx_mail_msg_sched');
            $t->dropIndex('idx_mail_msg_extid');
            $t->dropColumn(['send_state', 'scheduled_at', 'schedule_error', 'confidential', 'conf_days', 'conf_expires_at', 'conf_passcode',
                'conf_revoked_at', 'is_auto', 'is_imported', 'ext_from_address', 'ext_from_name', 'ext_message_id']);
        });
        Schema::table('mail_items', function (Blueprint $t) {
            $t->dropIndex('idx_mail_item_snooze');
            $t->dropColumn('snoozed_until');
        });
    }
};
