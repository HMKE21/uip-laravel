<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي": الملاحظات اللاصقة والويدجت على سطح المكتب (ساعة / تقويم / قائمة مهام). صف واحد لكل مستخدم،
 * `items` = JSON (قائمة عناصر بأماكنها ومحتواها). الأحدث بيكسب (updated_ms) — نفس فكرة desktop_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_widgets')) {
            return;
        }
        Schema::create('desktop_widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->mediumText('items')->nullable();
            $table->unsignedBigInteger('updated_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_widgets');
    }
};
