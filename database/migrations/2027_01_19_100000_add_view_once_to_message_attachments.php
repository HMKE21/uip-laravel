<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "مرة واحدة" / "تايمر" للصور والفيديو في الشات (زي واتساب).
 *  - view_mode    : null = مرفق عادي | 'once' = يتفتح مرة واحدة | 'timer' = يتفتح مرة واحدة وبيتعرض لعدد ثواني
 *  - view_seconds : مدة العرض في وضع timer
 *  - viewed_at    : أول مرة اتفتح (بعدها الملف بيتمسح من السيرفر ومحدش يقدر يفتحه تاني)
 *  - viewed_by    : اللي فتحه
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('view_mode', 10)->nullable()->after('is_encrypted');
            $table->unsignedSmallInteger('view_seconds')->nullable()->after('view_mode');
            $table->dateTime('viewed_at')->nullable()->after('view_seconds');
            $table->unsignedBigInteger('viewed_by')->nullable()->after('viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn(['view_mode', 'view_seconds', 'viewed_at', 'viewed_by']);
        });
    }
};
