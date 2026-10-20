<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('repo_url', 255);
            $table->string('default_branch', 100)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->enum('sync_status', ['not_connected', 'connected', 'error'])->default('not_connected');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_repositories');
    }
};
