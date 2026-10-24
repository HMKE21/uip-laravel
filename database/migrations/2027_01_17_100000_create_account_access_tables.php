<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بوابة "الحساب لازم يكون مرتبط بجامعة":
 *  - access_plans          قايمة الأسعار/الباقات (الأدمن / الجامعة / الكلية كل واحد بيدير باقاته).
 *  - account_access_grants سجل منح الوصول لحساب مش مرتبط بجامعة: مميزات محددة (أو الكل) + سعر + مدة.
 * مفيش FK عمدًا عشان السجل المالي ما يتحذفش بالغلط مع المستخدم.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('access_plans')) {
            Schema::create('access_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_user_id');
                $table->string('owner_role', 20);                 // admin | university | faculty
                $table->string('name_en', 120);
                $table->string('name_ar', 120);
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);      // 0 = مجاني
                $table->string('currency', 3)->default('EGP');
                $table->unsignedInteger('duration_days')->nullable(); // NULL = بدون انتهاء
                $table->json('features');                        // ['messaging','meetings'] أو ['all']
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['owner_user_id', 'is_active'], 'idx_access_plans_owner');
                $table->index(['owner_role'], 'idx_access_plans_role');
            });
        }

        if (!Schema::hasTable('account_access_grants')) {
            Schema::create('account_access_grants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');            // الحساب اللي اتمنح
                $table->unsignedBigInteger('granted_by');
                $table->string('granted_by_role', 20);
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->json('features');                         // نسخة ثابتة وقت المنح
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 3)->default('EGP');
                $table->unsignedInteger('duration_days')->nullable();
                $table->string('payment_status', 10)->default('free'); // free | pending | paid
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('starts_at')->nullable();       // بيتحدد لما المنحة تتفعّل (فورًا لو مجانية/مدفوعة)
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->string('note', 300)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at'], 'idx_aag_user');
                $table->index(['granted_by'], 'idx_aag_granter');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_access_grants');
        Schema::dropIfExists('access_plans');
    }
};
