<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_explorer_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('dataset_key', 60);
            $table->dateTime('opened_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'opened_at'], 'idx_dx_activity_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_explorer_activity');
    }
};
