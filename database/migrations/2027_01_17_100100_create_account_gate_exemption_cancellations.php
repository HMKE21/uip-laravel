<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حسابات الأمن (security_admin / security_officer) وتحليل البيانات (data_analyst) مستثناة من بوابة "الحساب لازم يكون
 * مرتبط بجامعة". صف هنا = الأدمن لغى الاستثناء عن الحساب ده، فبقى بيتعامل زي الطالب غير المرتبط (مقفول لحد ما يتمنح صلاحية).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('account_gate_exemption_cancellations')) {
            return;
        }
        Schema::create('account_gate_exemption_cancellations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_gate_exemption_cancellations');
    }
};
