<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** صورة توضيحية اختيارية للسؤال (رسم/دايجرام...). مسار نسبي لـ public/، فاضي لو مفيش صورة. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('questions', 'image_path')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->string('image_path', 255)->nullable()->after('prompt');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('questions', 'image_path')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
