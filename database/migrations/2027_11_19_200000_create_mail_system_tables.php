<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UIPE Mail — المرحلة 1: الحسابات، الرسائل، نسخ الصناديق، المستلمين، التصنيفات، المرفقات. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('address', 190)->unique();
            $t->string('kind', 16)->default('personal');          // personal | institutional
            $t->unsignedBigInteger('university_id')->nullable();
            $t->string('display_name', 150)->nullable();
            $t->boolean('is_default')->default(false);
            $t->string('status', 12)->default('active');          // active | suspended
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $t->index(['user_id', 'kind'], 'idx_mail_acc_user');
        });

        Schema::create('mail_threads', function (Blueprint $t) {
            $t->id();
            $t->string('subject_norm', 250)->nullable();
            $t->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('thread_id');
            $t->unsignedBigInteger('sender_account_id');
            $t->string('subject', 250)->default('');
            $t->longText('body_html')->nullable();
            $t->mediumText('body_text')->nullable();
            $t->string('snippet', 200)->nullable();
            $t->unsignedBigInteger('in_reply_to_message_id')->nullable();
            $t->boolean('is_draft')->default(false);
            $t->dateTime('sent_at')->nullable();
            $t->timestamps();
            $t->foreign('thread_id')->references('id')->on('mail_threads')->onDelete('cascade');
            $t->foreign('sender_account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->index(['thread_id', 'sent_at'], 'idx_mail_msg_thread');
        });

        Schema::create('mail_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('message_id');
            $t->string('kind', 3)->default('to');                 // to | cc | bcc
            $t->string('address', 190);
            $t->string('name', 150)->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->foreign('message_id')->references('id')->on('mail_messages')->onDelete('cascade');
            $t->index(['message_id', 'kind'], 'idx_mail_rcpt_msg');
            $t->index(['account_id'], 'idx_mail_rcpt_acc');
        });

        // نسخة كل شخص من الرسالة (واردة / مرسلة / مسودة) وحالتها عنده.
        Schema::create('mail_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('message_id');
            $t->string('folder', 12);                              // inbox | sent | drafts | archive | spam | trash
            $t->string('prev_folder', 12)->nullable();             // للاسترجاع من السلة
            $t->boolean('is_read')->default(false);
            $t->boolean('is_starred')->default(false);
            $t->dateTime('sort_at');
            $t->dateTime('trashed_at')->nullable();
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->foreign('message_id')->references('id')->on('mail_messages')->onDelete('cascade');
            $t->index(['account_id', 'folder', 'sort_at'], 'idx_mail_item_list');
            $t->index(['account_id', 'is_starred'], 'idx_mail_item_star');
            $t->index(['account_id', 'folder', 'is_read'], 'idx_mail_item_unread');
        });

        Schema::create('mail_labels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('name', 60);
            $t->string('color', 9)->default('#3b82f6');
            $t->timestamps();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->unique(['account_id', 'name'], 'uq_mail_label_name');
        });

        Schema::create('mail_item_labels', function (Blueprint $t) {
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('label_id');
            $t->primary(['item_id', 'label_id']);
            $t->foreign('item_id')->references('id')->on('mail_items')->onDelete('cascade');
            $t->foreign('label_id')->references('id')->on('mail_labels')->onDelete('cascade');
        });

        Schema::create('mail_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');                  // اللي رفعه
            $t->unsignedBigInteger('message_id')->nullable();      // null = لسه مرفوع ومش مربوط برسالة
            $t->string('disk', 30);
            $t->string('path', 300);
            $t->string('name', 200);
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->foreign('account_id')->references('id')->on('mail_accounts')->onDelete('cascade');
            $t->foreign('message_id')->references('id')->on('mail_messages')->onDelete('cascade');
            $t->index(['message_id'], 'idx_mail_att_msg');
        });
    }

    public function down(): void
    {
        foreach (['mail_attachments', 'mail_item_labels', 'mail_labels', 'mail_items', 'mail_recipients', 'mail_messages', 'mail_threads', 'mail_accounts'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
