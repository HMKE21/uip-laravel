<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_dashboards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 150);
            $table->json('layout');
            $table->boolean('is_default')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_shared')->default(0);
            $table->boolean('is_archived')->default(0);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['is_shared', 'is_archived'], 'idx_saved_dashboards_shared');
            $table->index(['user_id', 'is_archived'], 'idx_saved_dashboards_user_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_dashboards');
    }
};
