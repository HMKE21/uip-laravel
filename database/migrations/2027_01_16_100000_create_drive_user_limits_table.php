<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → My PC Control: تخصيص "جهازي" لكل مستخدم على حدة (صف واحد لكل مستخدم عنده تخصيص).
 * أي عمود NULL = "ارجع للقيمة العامة / حصة الدور". الأولوية: تخصيص المستخدم > حصة الدور > الإعداد العام.
 *   quota_mb   0 = بلا حد · NULL = وراثة
 *   status     active (طبيعي) | readonly (عرض وتنزيل بس) | blocked (مقفول بالكامل)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_user_limits')) {
            return;
        }
        Schema::create('drive_user_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedInteger('quota_mb')->nullable();
            $table->unsignedInteger('max_file_mb')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('reason', 255)->nullable();      // بيظهر للمستخدم لما يتقفل/يتحوّل لقراءة فقط
            $table->text('note')->nullable();               // ملاحظة داخلية للأدمن (مش بتظهر للمستخدم)
            $table->boolean('sharing_blocked')->default(false);
            $table->boolean('uploads_blocked')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_user_limits');
    }
};
