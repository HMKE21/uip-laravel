<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name_ar', 200);
            $table->string('name_en', 200);
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->enum('degree_type', ['diploma', 'bachelor', 'master', 'phd', 'other'])->default('bachelor');
            $table->decimal('duration_years', 3, 1)->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_public')->default(1);
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
            $table->index(['department_id'], 'idx_programs_department');
            $table->index(['status'], 'idx_programs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
