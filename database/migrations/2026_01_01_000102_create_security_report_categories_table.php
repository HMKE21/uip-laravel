<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_report_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 100);
            $table->string('name_ar', 100);
            $table->string('slug', 100);
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_report_categories');
    }
};
