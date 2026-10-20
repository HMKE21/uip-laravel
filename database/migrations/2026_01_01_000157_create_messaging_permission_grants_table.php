<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_permission_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_a_id');
            $table->unsignedBigInteger('user_b_id');
            $table->unsignedBigInteger('requested_by');
            $table->enum('status', ['pending', 'approved', 'rejected', 'revoked'])->default('pending');
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_a_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('user_b_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('decided_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['user_a_id', 'user_b_id'], 'uq_messaging_permission_pair');
            $table->index(['user_a_id', 'status'], 'idx_messaging_permission_a');
            $table->index(['user_b_id', 'status'], 'idx_messaging_permission_b');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_permission_grants');
    }
};
