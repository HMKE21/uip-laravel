<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 20);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('category', ['unauthorized_access', 'malware', 'data_leak', 'phishing', 'brute_force', 'policy_violation', 'other'])->default('other');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->enum('status', ['open', 'investigating', 'contained', 'resolved', 'closed'])->default('open');
            $table->string('source_ip', 45)->nullable();
            $table->unsignedBigInteger('affected_user_id')->nullable();
            $table->string('related_log_ref', 64)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('detected_at')->useCurrent();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('affected_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['reference_code']);
            $table->index(['status', 'severity'], 'idx_incidents_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};
