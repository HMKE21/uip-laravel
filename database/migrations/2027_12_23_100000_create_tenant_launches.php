<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** متابعة إطلاق المنصات في الخلفية (الحالة + التقدّم). payload/result مشفّرين وبيتمسحوا بعد الاستخدام. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_launches')) {
            Schema::create('tenant_launches', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('status', 12)->default('queued');   // queued | running | done | failed
                $t->string('step', 24)->default('queued');
                $t->unsignedTinyInteger('progress')->default(0);
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->string('slug', 60)->nullable();
                $t->text('error')->nullable();
                $t->longText('payload')->nullable();
                $t->longText('result')->nullable();
                $t->timestamp('seen_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_launches');
    }
};
