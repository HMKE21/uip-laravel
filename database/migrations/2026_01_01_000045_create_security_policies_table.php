<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_policies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('policy_key', 80);
            $table->string('category', 60);
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->text('value');
            $table->boolean('is_active')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['policy_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_policies');
    }
};
