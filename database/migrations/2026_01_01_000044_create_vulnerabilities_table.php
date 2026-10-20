<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('affected_component', 150)->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->enum('status', ['open', 'in_progress', 'mitigated', 'resolved', 'accepted_risk'])->default('open');
            $table->unsignedBigInteger('discovered_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('discovered_at')->useCurrent();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->date('deadline')->nullable();
            $table->foreign('discovered_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->index(['status', 'severity'], 'idx_vuln_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
