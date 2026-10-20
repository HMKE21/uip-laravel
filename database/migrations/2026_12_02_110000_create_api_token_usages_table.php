<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل استخدام كل API token (صفحة تفاصيل التوكن في /admin/mobile/{id}).
 * صف لكل طلب جاي من التطبيق ومعاه X-App-Token: مين المستخدم، من أي IP/جهاز، أنهي endpoint، والنتيجة.
 * بيتمسح مع التوكن (cascade)، والقديم منه بيتنضف تلقائيًا (config site.app_token_log_days، الافتراضي 60 يوم).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_token_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_token_id');
            $table->unsignedBigInteger('user_id')->nullable();   // null = طلب قبل تسجيل الدخول
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('route', 255)->nullable();            // نمط الـ route (users/{id}) لتجميع الـ endpoints
            $table->unsignedSmallInteger('status')->default(0);
            $table->string('outcome', 12)->default('ok');        // ok | revoked (توكن ملغي حاول يدخل)
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('client', 40)->nullable();            // X-UIP-Client
            $table->string('device_id', 64)->nullable();         // X-Device-Id
            $table->dateTime('created_at')->useCurrent();
            $table->index(['api_token_id', 'created_at'], 'idx_api_token_usages_token_time');
            $table->index('user_id', 'idx_api_token_usages_user');
            $table->foreign('api_token_id')->references('id')->on('api_tokens')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_usages');
    }
};
