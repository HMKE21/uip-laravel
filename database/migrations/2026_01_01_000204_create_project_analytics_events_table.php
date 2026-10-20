<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->enum('event_type', ['view', 'github_click', 'demo_click', 'link_click', 'file_download', 'contact_request']);
            $table->char('visitor_hash', 64)->nullable();
            $table->string('meta', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->index(['project_id', 'event_type', 'created_at'], 'idx_pae_project_type_date');
            $table->index(['project_id', 'visitor_hash', 'created_at'], 'idx_pae_project_visitor_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_analytics_events');
    }
};
