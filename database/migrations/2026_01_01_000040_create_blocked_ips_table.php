<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('blocked_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['ip_address']);
            $table->index(['ip_address'], 'idx_blocked_ips_ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
