<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * messages.body كان TEXT (حوالي 64KB). بعد ما حد طول الرسالة بقى من الـ .env (MESSAGING_MAX_MESSAGE_LENGTH، 0 = من غير حد)
 * لازم العمود نفسه ميبقاش هو السقف المخفي: LONGTEXT (4GB).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE messages MODIFY body LONGTEXT NOT NULL');
        }
    }

    public function down(): void
    {
        // مفيش رجوع: التحويل لـ TEXT ممكن يقص رسائل طويلة.
    }
};
