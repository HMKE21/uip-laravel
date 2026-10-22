<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المصاريف:
 *  - fee_items     : بند رسوم (للجامعة كلها أو لكلية) بمبلغ وتاريخ استحقاق.
 *  - student_fees  : المستحق على كل طالب (المبلغ نسخة وقت الإصدار). status = unpaid | partial | paid | waived.
 *  - fee_payments  : دفعات. الطالب بيبعت إخطار (pending) بمرجع التحويل والإدارة تأكّد/ترفض؛ والإدارة تقدر تسجّل دفعة مؤكَّدة مباشرة.
 *                    مفيش بوابة دفع أونلاين — التسجيل يدوي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fee_items')) {
            Schema::create('fee_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('university_id')->index();
                $table->unsignedBigInteger('faculty_id')->nullable()->index();   // null = الجامعة كلها
                $table->string('name', 160);
                $table->string('kind', 16)->default('tuition');                  // tuition | registration | service | other
                $table->decimal('amount', 10, 2);
                $table->string('currency', 3)->default('EGP');
                $table->date('due_on')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('student_fees')) {
            Schema::create('student_fees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fee_item_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->decimal('amount', 10, 2);
                $table->decimal('paid_amount', 10, 2)->default(0);
                $table->date('due_on')->nullable();
                $table->string('status', 10)->default('unpaid')->index();
                $table->string('waive_note', 500)->nullable();
                $table->timestamps();
                $table->unique(['fee_item_id', 'student_id']);
            });
        }

        if (!Schema::hasTable('fee_payments')) {
            Schema::create('fee_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_fee_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->decimal('amount', 10, 2);
                $table->string('method', 16)->default('bank_transfer');          // cash | bank_transfer | card | other
                $table->string('reference', 120)->nullable();
                $table->text('note')->nullable();
                $table->string('status', 10)->default('pending')->index();       // pending | confirmed | rejected
                $table->string('receipt_no', 24)->nullable()->unique();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('student_fees');
        Schema::dropIfExists('fee_items');
    }
};
