<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->enum('type', ['github', 'gitlab', 'live_demo', 'website', 'mobile_app', 'documentation', 'video_demo', 'presentation', 'research_paper', 'other'])->default('other');
            $table->string('url', 500);
            $table->string('label', 150)->nullable();
            $table->boolean('is_primary')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->index(['project_id'], 'idx_project_links_project');
            $table->index(['project_id', 'type'], 'idx_project_links_project_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
    }
};
