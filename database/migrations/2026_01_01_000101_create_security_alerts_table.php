<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['failed_login', 'account_lockout', 'privilege_escalation', 'suspicious_login_location', 'brute_force', 'multiple_session_detection', 'unauthorized_file_access', 'security_policy_violation']);
            $table->enum('severity', ['info', 'warning', 'high', 'critical'])->default('warning');
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'escalated'])->default('open');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->unsignedBigInteger('escalated_by')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->string('escalation_note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('acknowledged_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('escalated_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['status', 'severity'], 'idx_alerts_status');
            $table->index(['type'], 'idx_alerts_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
