<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_queries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->text('sql_text');
            $table->text('builder_state')->nullable();
            $table->string('dataset_key', 60)->nullable();
            $table->boolean('is_template')->default(0);
            $table->boolean('is_shared')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'updated_at'], 'idx_saved_queries_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_queries');
    }
};
