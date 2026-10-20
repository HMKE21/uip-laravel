<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_classifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('predicted_category', 100);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('alternative_categories')->nullable();
            $table->boolean('is_demo_data')->default(1);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_classifications');
    }
};
